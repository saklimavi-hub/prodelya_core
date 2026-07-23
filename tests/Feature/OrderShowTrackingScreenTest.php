<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShowTrackingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
    }

    public function test_orders_show_renders_as_tracking_screen_with_module_links(): void
    {
        $order = $this->createConvertedOrder();
        $order->load(['workForms', 'procurements', 'printProductions', 'deliveries']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee($order->document_number);
        $response->assertSee((string) $order->customer?->legal_name);
        $response->assertSee('Genel Özet');
        $response->assertSee('İş Formu');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertSee('Finans');
        $response->assertSee(route('admin.work-forms.show', $order->workForms->first()), false);
        $response->assertSee(route('admin.graphics.show', $order->workForms->first()), false);
        $response->assertSee(route('admin.orders.show', ['order' => $order, 'tab' => 'tedarik']), false);
        $response->assertSee(route('admin.orders.show', ['order' => $order, 'tab' => 'uretim']), false);
        $response->assertSee(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']), false);
        $response->assertSee(route('admin.finance.show', $order), false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('stock_snapshot', false);
    }

    public function test_quote_record_is_not_shown_on_orders_show_route(): void
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-ROUTE-0001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'grand_total' => 1200,
            'created_by' => $this->adminUser->id,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $quote))
            ->assertNotFound();
    }

    public function test_operations_user_does_not_see_financial_totals_or_finance_link(): void
    {
        $order = $this->createConvertedOrder();
        $graphicUser = $this->createUserWithRole('graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee($order->document_number);
        $response->assertDontSee('Müşteri Borcu');
        $response->assertDontSee('Kalan Bakiye');
        $response->assertDontSee(route('admin.finance.show', $order), false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('Tahsilat');
        $response->assertDontSee('4,70 TL');
        $response->assertDontSee('500,00 TL');
    }

    public function test_sales_user_can_see_financial_summary_on_tracking_screen(): void
    {
        $order = $this->createConvertedOrder();
        $salesUser = $this->createUserWithRole('sales');

        $response = $this->actingAs($salesUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $response->assertOk();
        $response->assertSee('Müşteri Borcu');
        $response->assertSee('Tahsil Edilen');
        $response->assertSee('Kalan Bakiye');
        $response->assertSee(route('admin.finance.show', $order), false);
    }

    public function test_tenant_mismatch_is_forbidden_for_order_show(): void
    {
        $order = $this->createConvertedOrder();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-order',
            'panel_subdomain' => 'other-tenant-order',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $order->tenant_account_id = $otherTenant->id;
        $order->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order))
            ->assertForbidden();
    }

    private function createConvertedOrder(): Order
    {
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Order show payload',
                'items' => [
                    [
                        'product_name' => 'Smoke Test Kalem',
                        'product_code' => 'SMOKE-001',
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
                                'print_type' => 'Lazer',
                                'print_option' => 'Gövde baskı',
                                'production_type' => 'Dış üretim / Fason',
                                'subcontractor_company_id' => $partner->id,
                                'print_quantity' => '100',
                                'print_unit_price' => '10',
                                'note' => 'İsim baskı',
                            ],
                        ],
                    ],
                    [
                        'product_name' => 'İkinci Test Ürün',
                        'product_code' => 'SECOND-002',
                        'quantity' => '50',
                        'unit' => 'Adet',
                        'list_price' => '11.00',
                        'discount_rate' => '20',
                        'unit_price' => '8.80',
                        'manual_unit_price' => '0',
                        'vat_rate' => '20',
                        'has_print' => '0',
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Order Show ' . ucfirst($roleKey),
            'email' => 'order-show-' . $roleKey . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->customer->tenant_account_id,
        ]);

        return $user;
    }
}
