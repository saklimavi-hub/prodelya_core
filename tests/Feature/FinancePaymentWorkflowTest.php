<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\FinanceSummaryService;
use App\Services\WorkFormCreationService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancePaymentWorkflowTest extends TestCase
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

    public function test_authorized_user_sees_payment_form_and_unauthorized_user_gets_403_for_store(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FPW-001', 18000, 4500, 22500, 4500, 27000);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $show->assertOk();
        $show->assertSee('Tahsilat Kaydet');
        $show->assertSee('Ödendi İşaretle');

        $unauthorizedUser = $this->createUserWithRole('delivery');

        $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '1000',
                'currency' => 'TL',
            ])
            ->assertForbidden();
    }

    public function test_partial_payment_creates_order_payment_updates_summary_and_keeps_snapshot_totals_intact(): void
    {
        ['order' => $order, 'workForm' => $workForm, 'delivery' => $delivery] = $this->createOrderWithWorkForm('SP-FPW-002', 18000, 4500, 22500, 4500, 27000);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '10000',
                'currency' => 'TL',
                'payment_method' => 'havale',
                'paid_at' => '2026-06-15T10:00',
                'payment_reference' => 'EFT-555',
                'payment_note' => 'Kısmi ödeme',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'deliveries.workForm']);
        $summary = app(FinanceSummaryService::class)->summarizeOrder($order);

        $this->assertCount(1, $order->payments);
        $this->assertSame(18000.0, (float) $order->product_total);
        $this->assertSame(4500.0, (float) $order->print_total);
        $this->assertSame(22500.0, (float) $order->subtotal);
        $this->assertSame(4500.0, (float) $order->vat_total);
        $this->assertSame(27000.0, (float) $order->grand_total);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $summary['payment_status']);
        $this->assertSame(17000.0, $summary['balance_due']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->fresh()->financial_warning);
        $this->assertSame('Bakiye var', data_get($workForm->fresh()->delivery_snapshot, 'financial_warning_label'));

        $deliveryShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery->fresh()));

        $deliveryShow->assertOk();
        $deliveryShow->assertSee('Bakiye var');
        $deliveryShow->assertDontSee('17.000,00 TL');
    }

    public function test_payment_store_rejects_currency_mismatch(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FPW-003', 5000, 0, 5000, 1000, 6000);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.finance.show', $order))
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '1000',
                'currency' => 'USD',
            ])
            ->assertRedirect(route('admin.finance.show', $order))
            ->assertSessionHasErrors('currency');

        $this->assertCount(0, $order->fresh()->payments);
    }

    public function test_mark_paid_creates_balance_due_payment_and_second_run_does_not_create_duplicate(): void
    {
        ['order' => $order, 'delivery' => $delivery] = $this->createOrderWithWorkForm('SP-FPW-004', 18000, 4500, 22500, 4500, 27000);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '10000',
                'currency' => 'TL',
                'payment_method' => 'havale',
                'paid_at' => '2026-06-15T10:00',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.mark-paid', $order), [
                'payment_method' => 'diger',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments']);
        $summary = app(FinanceSummaryService::class)->summarizeOrder($order);

        $this->assertCount(2, $order->payments);
        $this->assertSame(FinanceSummaryService::STATUS_PAID, $summary['payment_status']);
        $this->assertSame(0.0, $summary['balance_due']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_NONE, $delivery->fresh()->financial_warning);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.mark-paid', $order), [
                'payment_method' => 'diger',
            ])
            ->assertRedirect(route('admin.finance.show', $order))
            ->assertSessionHas('success', 'Sipariş zaten ödenmiş görünüyor.');

        $this->assertCount(2, $order->fresh()->payments);
    }

    public function test_cancel_payment_removes_it_from_summary_and_cannot_be_repeated(): void
    {
        ['order' => $order, 'delivery' => $delivery] = $this->createOrderWithWorkForm('SP-FPW-005', 12000, 3000, 15000, 3000, 18000);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '9000',
                'currency' => 'TL',
                'payment_method' => 'nakit',
                'paid_at' => '2026-06-15T09:00',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $payment = $order->fresh()->payments()->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]), [
                'cancel_note' => 'Yanlış giriş',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $payment = $payment->fresh();
        $summary = app(FinanceSummaryService::class)->summarizeOrder($order->fresh());

        $this->assertNotNull($payment->cancelled_at);
        $this->assertSame(FinanceSummaryService::STATUS_PAYMENT_PENDING, $summary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->fresh()->financial_warning);

        $financeShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $financeShow->assertOk();
        $financeShow->assertSee('İptal / Hesap dışı');
        $financeShow->assertSee('Summary hesabına dahil edilmez.');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.finance.show', $order))
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]))
            ->assertRedirect(route('admin.finance.show', $order))
            ->assertSessionHasErrors('payment');
    }

    public function test_refund_payment_reduces_summary_and_public_surfaces_stay_financially_safe(): void
    {
        ['order' => $order, 'workForm' => $workForm] = $this->createOrderWithWorkForm('SP-FPW-006', 10000, 0, 10000, 0, 10000, 'fis');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '10000',
                'currency' => 'TL',
                'paid_at' => '2026-06-15T09:00',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'iade',
                'amount' => '2000',
                'currency' => 'TL',
                'paid_at' => '2026-06-16T09:00',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $summary = app(FinanceSummaryService::class)->summarizeOrder($order->fresh());
        $this->assertSame(2000.0, $summary['refunded_total']);
        $this->assertSame(8000.0, $summary['net_paid_total']);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $summary['payment_status']);

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh());
        $this->assertStringNotContainsString('8.000,00 TL', $pdfHtml);
        $this->assertStringNotContainsString('2.000,00 TL', $pdfHtml);

        $publicResponse = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicResponse->assertOk();
        $publicResponse->assertDontSee('Bakiye var');
        $publicResponse->assertDontSee('8.000,00 TL');
        $publicResponse->assertDontSee('2.000,00 TL');
    }

    public function test_tenant_external_order_and_payment_actions_return_403(): void
    {
        ['order' => $order] = $this->createOrderWithWorkForm('SP-FPW-007', 5000, 0, 5000, 1000, 6000);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => 'tahsilat',
                'amount' => '1000',
                'currency' => 'TL',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $payment = $order->fresh()->payments()->firstOrFail();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Foreign Finance Tenant',
            'legal_name' => 'Foreign Finance Tenant Ltd.',
            'slug' => 'foreign-finance-tenant',
            'panel_subdomain' => 'foreign-finance-tenant',
            'status' => 'active',
        ]);

        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FPW-FOREIGN',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'grand_total' => 1000,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $foreignOrder), [
                'payment_type' => 'tahsilat',
                'amount' => '1000',
                'currency' => 'TL',
            ])
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.finance.payments.cancel', ['order' => $foreignOrder, 'payment' => $payment]))
            ->assertForbidden();
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

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Finans Workflow Ürünü',
            'product_code' => 'FIN-WF-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Finans workflow test kalemi',
            'product_snapshot' => [
                'product_name' => 'Finans Workflow Ürünü',
                'product_code' => 'FIN-WF-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'product_total' => $productTotal,
                'print_total' => $printTotal,
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grandTotal,
                'unit_price' => 100,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 0,
                'local_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 120,
            'unit_price' => 100,
            'line_total' => $productTotal,
            'has_print' => false,
            'print_total' => $printTotal,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)
            ->createForOrder($order, $this->adminUser)
            ->first()
            ->fresh(['delivery']);

        $delivery = $workForm->delivery->fresh();

        return compact('order', 'workForm', 'delivery');
    }

    private function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Finance Workflow Unauthorized',
            'email' => 'finance-workflow-' . $roleKey . '@prodelya.local',
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
