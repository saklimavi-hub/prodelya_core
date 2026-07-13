<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDetailOperationalFlowUxTest extends TestCase
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

    public function test_order_detail_renders_operational_flow_screen_with_safe_tracking_helper(): void
    {
        $order = $this->createConvertedOrder();
        $order->load(['workForms', 'procurements', 'printProductions', 'deliveries']);
        $workForm = $order->workForms->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Genel Özet');
        $response->assertSee('Sipariş Kalemleri');
        $response->assertSee('Sipariş Akışı');
        $response->assertSee('Grafik, tedarik, üretim ve teslimat aynı akışta; finans ayrı hatta izlenir.');
        $response->assertSee('Yardımcı İşlemler');
        $response->assertSee('Aktif Odak');
        $response->assertSee('Hızlı İşlemler');
        $response->assertSee('Şu an');
        $response->assertSee('Sıradaki işlem');
        $response->assertSee('İş Formu');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertSee('Finans');
        $response->assertSee('Geçmiş');
        $response->assertSee('data-sticky-layout="true"', false);
        $response->assertSee('data-sticky-sidebar="true"', false);
        $response->assertSee($order->document_number);
        $response->assertSee((string) $order->customer?->legal_name);
        $response->assertDontSee($workForm->public_tracking_token, false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);

        $trackingRedirect = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id]));

        $trackingRedirect->assertRedirect(route('public.work-forms.track', ['token' => $workForm->public_tracking_token]));
    }

    public function test_operations_user_does_not_see_financial_fields_on_operational_flow_screen(): void
    {
        $order = $this->createConvertedOrder();
        $graphicUser = $this->createUserWithRole('graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Genel Özet');
        $response->assertDontSee('Açık Bakiye');
        $response->assertDontSee('Kalan Bakiye');
        $response->assertDontSee('Finans Özeti');
        $response->assertDontSee(route('admin.finance.show', $order), false);
        $response->assertDontSee('<th>Tutar</th>', false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('Tahsilat');
        $response->assertDontSee('500,00 TL');
    }

    public function test_tracking_helper_is_scoped_to_same_order_and_tenant(): void
    {
        $order = $this->createConvertedOrder();
        $workForm = $order->fresh('workForms')->workForms->firstOrFail();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-tracking-helper',
            'panel_subdomain' => 'other-tenant-tracking-helper',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $order->update(['tenant_account_id' => $otherTenant->id]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id]))
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
                'notes' => 'Order detail operational flow payload',
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
            'name' => 'Order Detail ' . ucfirst($roleKey),
            'email' => 'order-detail-' . $roleKey . '@prodelya.local',
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
