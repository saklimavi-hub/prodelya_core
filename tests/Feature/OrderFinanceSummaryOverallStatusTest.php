<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\OrderFinanceSummaryService;
use App\Services\OrderPaymentService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class OrderFinanceSummaryOverallStatusTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_overall_status_is_finans_acik_when_customer_balance_remains(): void
    {
        $order = $this->createOrder('SP-OFS-OVERALL-OPEN');
        $order->forceFill(['grand_total' => 1200, 'subtotal' => 1000, 'vat_total' => 200])->save();

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame('Finans açık', $summary['overall']['status_label']);
    }

    public function test_overall_status_is_karsi_odeme_bekliyor_when_customer_is_collected_but_counterparty_debt_remains(): void
    {
        $order = $this->createOrder('SP-OFS-OVERALL-COUNTER');
        $order->forceFill(['grand_total' => 1200, 'subtotal' => 1000, 'vat_total' => 200])->save();

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);
        app(OrderPaymentService::class)->createPayment($order, [
            'payment_type' => 'tahsilat',
            'amount' => 1200,
            'currency' => 'TL',
            'payment_method' => 'havale',
            'paid_at' => '2026-07-04 10:00:00',
        ], $this->financeUser);

        $this->attachSupplierDebt($order, 'OFCOUNTER');

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame('Karşı ödeme bekliyor', $summary['overall']['status_label']);
    }

    public function test_overall_status_is_finans_tamamlandi_when_customer_is_collected_and_no_counterparty_debt_exists(): void
    {
        $order = $this->createOrder('SP-OFS-OVERALL-DONE');
        $order->forceFill(['grand_total' => 1200, 'subtotal' => 1000, 'vat_total' => 200])->save();

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);
        app(OrderPaymentService::class)->createPayment($order, [
            'payment_type' => 'tahsilat',
            'amount' => 1200,
            'currency' => 'TL',
            'payment_method' => 'havale',
            'paid_at' => '2026-07-04 10:00:00',
        ], $this->financeUser);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame('Finans tamamlandı', $summary['overall']['status_label']);
    }

    public function test_overall_status_is_kontrol_gerekli_when_customer_debit_is_missing(): void
    {
        $order = $this->createOrder('SP-OFS-OVERALL-CHECK');
        $order->forceFill(['grand_total' => 1200, 'subtotal' => 1000, 'vat_total' => 200])->save();

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame('Kontrol gerekli', $summary['overall']['status_label']);
    }

    private function attachSupplierDebt(Order $order, string $code): void
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
        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 8,
            'discount_rate' => 0,
        ]], $this->adminUser);
    }
}
