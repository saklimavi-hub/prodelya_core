<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class OrderFinanceActionsTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_finance_screen_shows_safe_actions_and_hides_raw_cancel_or_delete_links(): void
    {
        $order = $this->createOrder('SP-OFS-ACTION-001');
        $order->forceFill(['grand_total' => 1500, 'subtotal' => 1250, 'vat_total' => 250])->save();

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);
        [$procurementUrl, $productionUrl] = $this->attachCounterpartyRows($order);

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $response->assertOk()
            ->assertSee('Tahsilat Gir')
            ->assertSee('Cari Ekstreye Git')
            ->assertSee('Siparişi Aç')
            ->assertSee('Tedarik Kaydını Aç')
            ->assertSee('Üretim Kaydını Aç')
            ->assertSee(route('admin.finance.payments.store', $order), false)
            ->assertSee($procurementUrl, false)
            ->assertSee($productionUrl, false)
            ->assertDontSee('Sil')
            ->assertDontSee('transaction_id')
            ->assertDontSee('source_id');
    }

    private function attachCounterpartyRows(Order $order): array
    {
        [$supplier, $source] = $this->createSupplierWithAccess('OFACT');
        $procurement = $this->createSupplierProcurement($supplier, $source, $order->document_number, $order);
        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );
        $item = $request->items->firstOrFail();
        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 7,
            'discount_rate' => 0,
        ]], $this->adminUser);

        $partner = $this->createPartnerCompany('Aksiyon Fason');
        $production = $this->createProduction($order->document_number, $partner, OrderItemPrintProduction::TYPE_OUTSOURCED, $order);
        $production->forceFill([
            'subcontractor_cost' => 500,
            'subcontractor_cost_currency' => 'TL',
            'updated_by' => $this->adminUser->id,
        ])->save();
        app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        return [route('admin.procurements.show', $procurement), route('admin.productions.show', $production)];
    }
}
