<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Services\OrderFinanceSummaryService;
use App\Services\OrderPaymentService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class OrderFinanceSummaryCounterpartyDebtTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_supplier_and_subcontractor_debts_are_grouped_without_inventing_payments(): void
    {
        $order = $this->createOrder('SP-OFS-COUNTER-001');
        $order->forceFill(['grand_total' => 2400, 'subtotal' => 2000, 'vat_total' => 400])->save();

        [$supplierAmount, $supplierName] = $this->attachSupplierDebt($order, 'OFSUP', 12.0);
        [$subcontractorAmount, $subcontractorName] = $this->attachSubcontractorDebt($order, 'OFS Fasoncu', 850.0);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame($supplierAmount, $summary['supplier_debts']['total_debt']);
        $this->assertSame($subcontractorAmount, $summary['subcontractor_debts']['total_debt']);
        $this->assertNull($summary['supplier_debts']['paid_amount']);
        $this->assertNull($summary['subcontractor_debts']['paid_amount']);
        $this->assertSame('Bağlı ödeme bulunamadı', $summary['supplier_debts']['formatted_paid_amount']);
        $this->assertSame('Bağlı ödeme bulunamadı', $summary['subcontractor_debts']['formatted_paid_amount']);
        $this->assertSame($supplierName, $summary['supplier_debts']['items'][0]['supplier_name']);
        $this->assertSame($subcontractorName, $summary['subcontractor_debts']['items'][0]['supplier_name']);
        $this->assertNotNull($summary['supplier_debts']['items'][0]['source_url']);
        $this->assertNotNull($summary['subcontractor_debts']['items'][0]['source_url']);
    }

    private function attachSupplierDebt(Order $order, string $code, float $purchaseUnitPrice): array
    {
        [$supplier, $source] = $this->createSupplierWithAccess($code);
        $procurement = $this->createSupplierProcurement($supplier, $source, $order->document_number, $order);
        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );
        $item = $request->items->firstOrFail();
        $request = app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => $purchaseUnitPrice,
            'discount_rate' => 0,
        ]], $this->adminUser);
        $item = $request->fresh('items')->items->firstOrFail();

        return [(float) $item->purchase_total, $supplier->name];
    }

    private function attachSubcontractorDebt(Order $order, string $name, float $cost): array
    {
        $partner = $this->createPartnerCompany($name);
        $production = $this->createProduction($order->document_number, $partner, OrderItemPrintProduction::TYPE_OUTSOURCED, $order);
        $production->forceFill([
            'subcontractor_cost' => $cost,
            'subcontractor_cost_currency' => 'TL',
            'updated_by' => $this->adminUser->id,
        ])->save();

        app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        return [$cost, $partner->legal_name];
    }
}
