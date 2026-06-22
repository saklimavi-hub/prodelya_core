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
use App\Services\OrderPaymentService;
use App\Services\WorkFormCreationService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceUiTest extends TestCase
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

    public function test_finance_index_renders_real_order_rows_sidebar_and_snapshot_totals(): void
    {
        ['order' => $order] = $this->createFinanceOrder('SP-FUI-001', 18000, 4500, 22500, 4500, 27000, 'fatura');

        app(OrderPaymentService::class)->createPayment($order, [
            'amount' => 10000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-15 10:00:00',
            'payment_reference' => 'EFT-12345',
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'));

        $response->assertOk();
        $response->assertSee('Finans / Tahsilat');
        $response->assertSee(route('admin.finance.index'), false);
        $response->assertSee($order->document_number);
        $response->assertSee($order->source_quote_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('Fatura');
        $response->assertSee('18.000,00 TL');
        $response->assertSee('4.500,00 TL');
        $response->assertSee('22.500,00 TL');
        $response->assertSee('27.000,00 TL');
        $response->assertSee('10.000,00 TL');
        $response->assertSee('17.000,00 TL');
        $response->assertSee('Kısmi Ödeme');
        $response->assertSee('Bakiye var');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_finance_show_renders_vat_breakdown_payment_rows_and_delivery_warning(): void
    {
        ['order' => $order] = $this->createFinanceOrder('SP-FUI-002', 18000, 4500, 22500, 4500, 27000, 'fatura');
        $service = app(OrderPaymentService::class);

        $service->createPayment($order, [
            'amount' => 10000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-15 10:00:00',
            'payment_reference' => 'EFT-12345',
            'payment_note' => 'Ön ödeme',
        ], $this->adminUser);

        $cancelled = $service->createPayment($order, [
            'amount' => 2500,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_CASH,
            'paid_at' => '2026-06-16 11:00:00',
            'payment_note' => 'Yanlış kayıt',
        ], $this->adminUser);
        $service->cancelPayment($cancelled, $this->adminUser, 'Yanlış kayıt');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $response->assertOk();
        $response->assertSee('Finans Detayı');
        $response->assertSee('Fatura');
        $response->assertSee('Kısmi Ödeme');
        $response->assertSee('Ürün');
        $response->assertSee('Baskı');
        $response->assertSee('2.700,00 TL');
        $response->assertSee('1.800,00 TL');
        $response->assertSee('Tahsilat');
        $response->assertSee('Havale');
        $response->assertSee('EFT-12345');
        $response->assertSee('Ön ödeme');
        $response->assertSee('İptal / Hesap dışı');
        $response->assertSee('Bakiye var');
        $response->assertSee('Tahsilat Kaydet');
        $response->assertSee(route('admin.finance.payments.store', $order), false);
        $response->assertSee(route('admin.finance.mark-paid', $order), false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
    }

    public function test_finance_show_for_fis_order_displays_zero_vat_and_explanation_without_confusing_payment_status(): void
    {
        ['order' => $order] = $this->createFinanceOrder('SP-FUI-003', 8750, 0, 8750, 0, 8750, 'fis');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));

        $response->assertOk();
        $response->assertSee('Fiş');
        $response->assertSee('Ödeme Bekliyor');
        $response->assertSee('0,00 TL');
        $response->assertSee('Fiş seçildiği için KDV hesaplanmaz');
    }

    public function test_unauthorized_user_and_tenant_external_order_receive_403_on_finance_routes(): void
    {
        ['order' => $order] = $this->createFinanceOrder('SP-FUI-004', 5000, 0, 5000, 1000, 6000, 'fatura');
        $unauthorizedUser = $this->createUserWithRole('delivery');

        $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'))
            ->assertForbidden();

        $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertForbidden();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Second Tenant Finance',
            'legal_name' => 'Second Tenant Finance Ltd.',
            'slug' => 'second-tenant-finance',
            'panel_subdomain' => 'second-tenant-finance',
            'status' => 'active',
        ]);

        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FOREIGN-FIN',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'grand_total' => 1000,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $foreignOrder))
            ->assertForbidden();
    }

    public function test_public_work_form_pdf_and_delivery_surfaces_do_not_show_financial_totals_or_warning_details(): void
    {
        ['order' => $order, 'workForm' => $workForm, 'delivery' => $delivery] = $this->createFinanceOrder('SP-FUI-005', 18000, 4500, 22500, 4500, 27000, 'fatura');

        app(OrderPaymentService::class)->createPayment($order, [
            'amount' => 10000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-15 10:00:00',
        ], $this->adminUser);

        $deliveryShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery->fresh()));

        $deliveryShow->assertOk();
        $deliveryShow->assertSee('Bakiye var');
        $deliveryShow->assertDontSee('17.000,00 TL');
        $deliveryShow->assertDontSee('27.000,00 TL');

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm->fresh()));

        $workFormShow->assertOk();
        $workFormShow->assertSee('Bakiye var');
        $workFormShow->assertDontSee('17.000,00 TL');
        $workFormShow->assertDontSee('27.000,00 TL');

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh());
        $this->assertStringContainsString('Bakiye var', $pdfHtml);
        $this->assertStringNotContainsString('17.000,00 TL', $pdfHtml);
        $this->assertStringNotContainsString('27.000,00 TL', $pdfHtml);

        $publicResponse = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicResponse->assertOk();
        $publicResponse->assertDontSee('Bakiye var');
        $publicResponse->assertDontSee('Ödeme bekliyor');
        $publicResponse->assertDontSee('17.000,00 TL');
        $publicResponse->assertDontSee('27.000,00 TL');
    }

    private function createFinanceOrder(
        string $documentNumber,
        float $productTotal,
        float $printTotal,
        float $subtotal,
        float $vatTotal,
        float $grandTotal,
        string $invoiceStatus
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
            'product_name' => 'Finans UI Test Ürünü',
            'product_code' => 'FIN-UI-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Finans UI test kalemi',
            'product_snapshot' => [
                'product_name' => 'Finans UI Test Ürünü',
                'product_code' => 'FIN-UI-001',
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'product_total' => $productTotal,
                'print_total' => $printTotal,
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grandTotal,
                'unit_price' => 180,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 0,
                'local_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 190,
            'unit_price' => 180,
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
            'name' => 'Unauthorized Finance User',
            'email' => 'unauthorized-finance-' . $roleKey . '@prodelya.local',
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
