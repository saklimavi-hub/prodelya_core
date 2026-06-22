<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\FinanceSummaryService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceEndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_finance_end_to_end_smoke_flow_keeps_delivery_warning_public_safe_and_snapshot_totals_intact(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $workForm = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $delivery = $workForm->delivery()->firstOrFail();
        $snapshotTotals = [
            'product_total' => (float) $order->product_total,
            'print_total' => (float) $order->print_total,
            'subtotal' => (float) $order->subtotal,
            'vat_total' => (float) $order->vat_total,
            'grand_total' => (float) $order->grand_total,
            'invoice_status' => $order->invoice_status,
            'currency' => $order->currency,
            'vat_breakdown_json' => $order->vat_breakdown_json,
        ];

        $initialSummary = app(FinanceSummaryService::class)->summarizeOrder($order->fresh(['payments', 'customer']));
        $this->assertSame(FinanceSummaryService::STATUS_PAYMENT_PENDING, $initialSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->financial_warning);

        $financeShowInitial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $financeShowInitial->assertOk();
        $financeShowInitial->assertSee('Fatura');
        $financeShowInitial->assertSee('Ödeme Bekliyor');

        $deliveryShowInitial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $deliveryShowInitial->assertOk();
        $deliveryShowInitial->assertSee('Ödeme bekliyor');
        $deliveryShowInitial->assertDontSee('grand_total', false);
        $deliveryShowInitial->assertDontSee('paid_total', false);
        $deliveryShowInitial->assertDontSee('balance_due', false);
        $deliveryShowInitial->assertDontSee('price_snapshot', false);
        $deliveryShowInitial->assertDontSee('group_code', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => '100.00',
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'paid_at' => '2026-06-13T11:00',
                'payment_reference' => 'SMK-001',
                'payment_note' => 'Smoke kısmi tahsilat',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm']);
        $partialSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $formattedBalanceDue = number_format((float) $partialSummary['balance_due'], 2, ',', '.') . ' TL';
        $formattedPartialPayment = number_format(100, 2, ',', '.') . ' TL';

        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $partialSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->financial_warning);
        $this->assertSame('Bakiye var', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));

        $financeShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $financeShowPartial->assertOk();
        $financeShowPartial->assertSee('Kısmi Ödeme');
        $financeShowPartial->assertSee('Fatura');

        $deliveryShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $deliveryShowPartial->assertOk();
        $deliveryShowPartial->assertSee('Bakiye var');
        $deliveryShowPartial->assertDontSee($formattedBalanceDue, false);
        $deliveryShowPartial->assertDontSee($formattedPartialPayment, false);
        $deliveryShowPartial->assertDontSee('KDV', false);

        $workFormShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $workFormShowPartial->assertOk();
        $workFormShowPartial->assertSee('Bakiye var');
        $workFormShowPartial->assertDontSee($formattedBalanceDue, false);
        $workFormShowPartial->assertDontSee($formattedPartialPayment, false);
        $workFormShowPartial->assertDontSee('grand_total', false);

        $pdfHtmlPartial = app(WorkFormPdfService::class)->renderHtml($workForm->fresh(['tenant', 'attachments', 'activityLogs.attachment']));
        $this->assertStringContainsString('Bakiye var', $pdfHtmlPartial);
        $this->assertStringNotContainsString($formattedBalanceDue, $pdfHtmlPartial);
        $this->assertStringNotContainsString($formattedPartialPayment, $pdfHtmlPartial);
        $this->assertStringNotContainsString('grand_total', $pdfHtmlPartial);
        $this->assertStringNotContainsString('group_code', $pdfHtmlPartial);

        $publicPartial = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicPartial->assertOk();
        $publicPartial->assertDontSee('Ödeme bekliyor');
        $publicPartial->assertDontSee('Bakiye var');
        $publicPartial->assertDontSee('Tahsilat onayı bekleniyor');
        $publicPartial->assertDontSee($formattedBalanceDue, false);
        $publicPartial->assertDontSee($formattedPartialPayment, false);
        $publicPartial->assertDontSee('KDV', false);
        $publicPartial->assertDontSee('group_code', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.mark-paid', $order), [
                'payment_method' => OrderPayment::METHOD_OTHER,
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm']);
        $paidSummary = app(FinanceSummaryService::class)->summarizeOrder($order);

        $this->assertSame(FinanceSummaryService::STATUS_PAID, $paidSummary['payment_status']);
        $this->assertSame(0.0, (float) $paidSummary['balance_due']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_NONE, $delivery->financial_warning);
        $this->assertSame('Finans uyarısı yok', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));

        $deliveryShowPaid = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $deliveryShowPaid->assertOk();
        $deliveryShowPaid->assertSee('Finans uyarısı yok');
        $deliveryShowPaid->assertDontSee($formattedBalanceDue, false);

        $markPaidPayment = $order->payments()
            ->where('payment_note', 'like', '%Ödendi işaretle işlemi ile oluşturuldu.%')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $markPaidPayment]), [
                'cancel_note' => 'Smoke iptal senaryosu',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm']);
        $cancelledSummary = app(FinanceSummaryService::class)->summarizeOrder($order);

        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $cancelledSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->financial_warning);
        $this->assertSame('Bakiye var', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));

        $financeShowCancelled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $financeShowCancelled->assertOk();
        $financeShowCancelled->assertSee('İptal / Hesap dışı');
        $financeShowCancelled->assertSee('Kısmi Ödeme');

        $unauthorizedUser = $this->createUserWithRole('delivery');

        $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertForbidden();

        $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => '50',
                'currency' => 'TL',
            ])
            ->assertForbidden();

        $order = $order->fresh();
        $this->assertSame($snapshotTotals['product_total'], (float) $order->product_total);
        $this->assertSame($snapshotTotals['print_total'], (float) $order->print_total);
        $this->assertSame($snapshotTotals['subtotal'], (float) $order->subtotal);
        $this->assertSame($snapshotTotals['vat_total'], (float) $order->vat_total);
        $this->assertSame($snapshotTotals['grand_total'], (float) $order->grand_total);
        $this->assertSame($snapshotTotals['invoice_status'], $order->invoice_status);
        $this->assertSame($snapshotTotals['currency'], $order->currency);
        $this->assertSame($snapshotTotals['vat_breakdown_json'], $order->vat_breakdown_json);
    }

    private function createQuoteViaHttp(): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Finance smoke payload',
                'items' => [[
                    'product_name' => 'Finans Smoke Ürünü',
                    'product_code' => 'FIN-SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                            'note' => 'Logo baskı',
                        ],
                        [
                            'print_type' => 'Sıcak Baskı',
                            'print_option' => 'Gövde baskı',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'İsim baskı',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        return Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
    }

    private function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Finance Smoke Unauthorized',
            'email' => 'finance-smoke-' . $roleKey . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
