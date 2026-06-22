<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\FinanceSummaryService;
use App\Services\OrderPaymentService;
use App\Services\PublicWorkFormTrackingDataBuilder;
use App\Services\WorkFormCreationService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_order_payments_table_model_and_multiple_payments_relationships_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('order_payments'));

        ['order' => $order] = $this->createOrderWithWorkForm('SP-FIN-001', 18000, 4500, 22500, 4500, 27000);
        $service = app(OrderPaymentService::class);

        $first = $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 10000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-15 10:00:00',
            'payment_reference' => 'EFT-001',
        ], $this->adminUser);

        $second = $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 5000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_CREDIT_CARD,
            'paid_at' => '2026-06-17 11:30:00',
            'payment_reference' => 'POS-002',
        ], $this->adminUser);

        $order = $order->fresh(['payments', 'customer']);

        $this->assertCount(2, $order->payments);
        $this->assertSame($order->id, $first->order->id);
        $this->assertSame($this->customer->id, $first->customerCompany?->id);
        $this->assertSame('Tahsilat', $first->safePaymentTypeLabel());
        $this->assertSame('Kredi Kartı', $second->safePaymentMethodLabel());
    }

    public function test_finance_summary_computes_partial_full_and_overpayment_statuses_correctly(): void
    {
        $service = app(OrderPaymentService::class);
        $summaryService = app(FinanceSummaryService::class);

        ['order' => $partialOrder] = $this->createOrderWithWorkForm('SP-FIN-002', 1000, 200, 1200, 240, 1440);
        $service->createPayment($partialOrder, [
            'amount' => 440,
            'currency' => 'TL',
            'paid_at' => '2026-06-16 10:00:00',
        ], $this->adminUser);

        ['order' => $paidOrder] = $this->createOrderWithWorkForm('SP-FIN-003', 1000, 0, 1000, 0, 1000, 'fis');
        $service->createPayment($paidOrder, [
            'amount' => 1000,
            'currency' => 'TL',
            'paid_at' => '2026-06-16 10:05:00',
        ], $this->adminUser);

        ['order' => $overpaidOrder] = $this->createOrderWithWorkForm('SP-FIN-004', 1000, 0, 1000, 0, 1000, 'fis');
        $service->createPayment($overpaidOrder, [
            'amount' => 1200,
            'currency' => 'TL',
            'paid_at' => '2026-06-16 10:10:00',
        ], $this->adminUser);

        $partialSummary = $summaryService->summarizeOrder($partialOrder->fresh());
        $paidSummary = $summaryService->summarizeOrder($paidOrder->fresh());
        $overpaidSummary = $summaryService->summarizeOrder($overpaidOrder->fresh());

        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $partialSummary['payment_status']);
        $this->assertSame(440.0, $partialSummary['paid_total']);
        $this->assertSame(1000.0, $partialSummary['balance_due']);

        $this->assertSame(FinanceSummaryService::STATUS_PAID, $paidSummary['payment_status']);
        $this->assertSame(1000.0, $paidSummary['net_paid_total']);
        $this->assertSame(0.0, $paidSummary['balance_due']);

        $this->assertSame(FinanceSummaryService::STATUS_OVERPAID, $overpaidSummary['payment_status']);
        $this->assertSame(1200.0, $overpaidSummary['net_paid_total']);
        $this->assertSame(-200.0, $overpaidSummary['balance_due']);
    }

    public function test_refund_adjustment_and_cancelled_payments_affect_net_paid_summary_correctly(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FIN-005', 1000, 500, 1500, 300, 1800);
        $service = app(OrderPaymentService::class);
        $summaryService = app(FinanceSummaryService::class);

        $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-16 12:00:00',
        ], $this->adminUser);

        $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_REFUND,
            'amount' => 200,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-17 09:00:00',
        ], $this->adminUser);

        $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_ADJUSTMENT,
            'amount' => -100,
            'currency' => 'TL',
            'payment_note' => 'İade farkı düzeltmesi',
        ], $this->adminUser);

        $cancelled = $service->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 300,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_CASH,
            'paid_at' => '2026-06-18 08:30:00',
        ], $this->adminUser);

        $service->cancelPayment($cancelled, $this->adminUser, 'Yanlış kayıt');

        $summary = $summaryService->summarizeOrder($order->fresh());

        $this->assertSame(1000.0, $summary['paid_total']);
        $this->assertSame(200.0, $summary['refunded_total']);
        $this->assertSame(-100.0, $summary['adjustment_total']);
        $this->assertSame(700.0, $summary['net_paid_total']);
        $this->assertSame(1100.0, $summary['balance_due']);
        $this->assertSame(3, $summary['payment_count']);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $summary['payment_status']);
    }

    public function test_payment_statuses_for_pending_due_warning_and_cancelled_orders_are_derived_correctly(): void
    {
        $summaryService = app(FinanceSummaryService::class);
        $service = app(OrderPaymentService::class);

        ['order' => $pendingOrder] = $this->createOrderWithWorkForm('SP-FIN-006', 1000, 0, 1000, 0, 1000, 'fis');
        $pendingSummary = $summaryService->summarizeOrder($pendingOrder->fresh());
        $this->assertSame(FinanceSummaryService::STATUS_PAYMENT_PENDING, $pendingSummary['payment_status']);

        ['order' => $dueOrder] = $this->createOrderWithWorkForm('SP-FIN-007', 1000, 0, 1000, 0, 1000, 'fis');
        $service->createPayment($dueOrder, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1000,
            'currency' => 'TL',
            'due_date' => now()->addDays(5)->toDateTimeString(),
        ], $this->adminUser);
        $this->assertSame(FinanceSummaryService::STATUS_DUE_PENDING, $summaryService->summarizeOrder($dueOrder->fresh())['payment_status']);

        ['order' => $warningOrder] = $this->createOrderWithWorkForm('SP-FIN-008', 1000, 0, 1000, 0, 1000, 'fis');
        $service->createPayment($warningOrder, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1000,
            'currency' => 'TL',
            'due_date' => now()->subDays(3)->toDateTimeString(),
        ], $this->adminUser);
        $this->assertSame(FinanceSummaryService::STATUS_COLLECTION_WARNING, $summaryService->summarizeOrder($warningOrder->fresh())['payment_status']);

        ['order' => $cancelledOrder] = $this->createOrderWithWorkForm('SP-FIN-009', 1000, 0, 1000, 0, 1000, 'fis');
        $cancelledOrder->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame(FinanceSummaryService::STATUS_CANCELLED, $summaryService->summarizeOrder($cancelledOrder->fresh())['payment_status']);
    }

    public function test_payment_currency_is_limited_to_order_currency_and_order_snapshot_totals_are_preserved(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FIN-010', 18123.45, 4500, 22623.45, 4524.69, 27148.14);
        $service = app(OrderPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment currency must match order currency.');

        try {
            $service->createPayment($order, [
                'amount' => 500,
                'currency' => 'USD',
                'paid_at' => '2026-06-16 13:00:00',
            ], $this->adminUser);
        } finally {
            $order = $order->fresh();

            $this->assertSame(18123.45, (float) $order->product_total);
            $this->assertSame(4500.0, (float) $order->print_total);
            $this->assertSame(22623.45, (float) $order->subtotal);
            $this->assertSame(4524.69, (float) $order->vat_total);
            $this->assertSame(27148.14, (float) $order->grand_total);
            $this->assertSame('fatura', $order->invoice_status);
        }
    }

    public function test_invoice_status_is_not_confused_with_payment_status(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FIN-011', 5000, 0, 5000, 0, 5000, 'fis');
        $service = app(OrderPaymentService::class);
        $summaryService = app(FinanceSummaryService::class);

        $service->createPayment($order, [
            'amount' => 2500,
            'currency' => 'TL',
            'paid_at' => '2026-06-16 14:00:00',
        ], $this->adminUser);

        $summary = $summaryService->summarizeOrder($order->fresh());

        $this->assertSame('fis', $summary['invoice_status']);
        $this->assertSame('Fiş', $summary['invoice_status_label']);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $summary['payment_status']);
        $this->assertSame('Kısmi Ödeme', $summary['payment_status_label']);
    }

    public function test_delivery_financial_warning_is_derived_from_payment_summary_and_public_tracking_stays_safe(): void
    {
        ['order' => $order, 'workForm' => $workForm, 'delivery' => $delivery] = $this->createOrderWithWorkForm('SP-FIN-012', 18000, 4500, 22500, 4500, 27000);
        $service = app(OrderPaymentService::class);
        $summaryService = app(FinanceSummaryService::class);
        $publicBuilder = app(PublicWorkFormTrackingDataBuilder::class);

        $delivery = $delivery->fresh(['workForm']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->financial_warning);
        $this->assertSame('Ödeme bekliyor', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));

        $service->createPayment($order, [
            'amount' => 10000,
            'currency' => 'TL',
            'paid_at' => '2026-06-17 10:00:00',
        ], $this->adminUser);

        $delivery = $delivery->fresh(['workForm']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->financial_warning);
        $this->assertSame('Bakiye var', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));
        $this->assertStringNotContainsString('TL', (string) data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $summaryService->deliveryFinancialWarning($order->fresh()));

        $publicPayload = $publicBuilder->build($workForm->fresh());
        $publicJson = json_encode($publicPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertStringNotContainsString('financial_warning', $publicJson);
        $this->assertStringNotContainsString('Bakiye var', $publicJson);
        $this->assertStringNotContainsString('10.000,00 TL', $publicJson);
        $this->assertStringNotContainsString('17.000,00 TL', $publicJson);

        $publicResponse = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicResponse->assertOk();
        $publicResponse->assertDontSee('Bakiye var');
        $publicResponse->assertDontSee('Ödeme bekliyor');
        $publicResponse->assertDontSee('10.000,00 TL');
        $publicResponse->assertDontSee('17.000,00 TL');

        $service->createPayment($order->fresh(), [
            'amount' => 17000,
            'currency' => 'TL',
            'paid_at' => '2026-06-18 10:00:00',
        ], $this->adminUser);

        $delivery = $delivery->fresh();
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_NONE, $delivery->financial_warning);
        $this->assertSame('Finans uyarısı yok', data_get($delivery->workForm->fresh()->delivery_snapshot, 'financial_warning_label'));
    }

    public function test_work_form_pdf_and_public_surfaces_do_not_leak_financial_totals_or_payment_amounts(): void
    {
        ['order' => $order, 'workForm' => $workForm] = $this->createOrderWithWorkForm('SP-FIN-013', 18123.45, 4500, 22623.45, 4524.69, 27148.14);
        $service = app(OrderPaymentService::class);
        $pdfService = app(WorkFormPdfService::class);

        $service->createPayment($order, [
            'amount' => 10000.12,
            'currency' => 'TL',
            'paid_at' => '2026-06-17 11:11:00',
        ], $this->adminUser);

        $adminShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm->fresh()));

        $adminShow->assertOk();
        $adminShow->assertSee('Finans Uyarısı');
        $adminShow->assertSee('Bakiye var');
        $adminShow->assertDontSee('27.148,14 TL');
        $adminShow->assertDontSee('10.000,12 TL');
        $adminShow->assertDontSee('grand_total', false);
        $adminShow->assertDontSee('price_snapshot', false);
        $adminShow->assertDontSee('group_code', false);

        $pdfHtml = $pdfService->renderHtml($workForm->fresh());
        $this->assertStringContainsString('Bakiye var', $pdfHtml);
        $this->assertStringNotContainsString('27.148,14 TL', $pdfHtml);
        $this->assertStringNotContainsString('10.000,12 TL', $pdfHtml);
        $this->assertStringNotContainsString('grand_total', $pdfHtml);
        $this->assertStringNotContainsString('price_snapshot', $pdfHtml);
        $this->assertStringNotContainsString('group_code', $pdfHtml);
    }

    private function createOrderWithWorkForm(
        string $documentNumber,
        float $productTotal,
        float $printTotal,
        float $subtotal,
        float $vatTotal,
        float $grandTotal,
        string $invoiceStatus = 'fatura'
    ): array {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'source_quote_number' => 'TK-' . substr($documentNumber, 3),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => $invoiceStatus,
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => $productTotal,
            'print_total' => $printTotal,
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'grand_total' => $grandTotal,
            'vat_breakdown_json' => $invoiceStatus === 'fatura'
                ? [
                    ['rate' => 10, 'total' => round($vatTotal * 0.6, 2), 'scope' => 'product'],
                    ['rate' => 20, 'total' => round($vatTotal * 0.4, 2), 'scope' => 'print'],
                ]
                : [],
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Finans Test Ürünü',
            'product_code' => 'FIN-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Finans test kalemi',
            'product_snapshot' => [
                'product_name' => 'Finans Test Ürünü',
                'product_code' => 'FIN-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'manual_unit_price' => true,
                'product_total' => $productTotal,
                'print_total' => $printTotal,
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grandTotal,
                'vat_breakdown' => $invoiceStatus === 'fatura'
                    ? [
                        ['rate' => 10, 'total' => round($vatTotal * 0.6, 2), 'scope' => 'product'],
                        ['rate' => 20, 'total' => round($vatTotal * 0.4, 2), 'scope' => 'print'],
                    ]
                    : [],
                'unit_price' => 181.23,
                'line_total' => $productTotal,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 0,
                'local_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 200,
            'discount_rate' => 5,
            'unit_price' => 181.23,
            'line_total' => $productTotal,
            'has_print' => false,
            'print_total' => $printTotal,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);
        $delivery = $workForm->delivery;

        return compact('order', 'item', 'workForm', 'delivery');
    }
}
