<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoTenantFullAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const STARTER_HOST = 'starter-demo-check.prodelya.test';

    private User $adminUser;
    private TenantAccount $demoTenant;
    private TenantAccount $starterTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();

        $this->starterTenant = TenantAccount::query()->create([
            'name' => 'Starter Demo Check Tenant',
            'legal_name' => 'Starter Demo Check Tenant Ltd.',
            'slug' => 'starter-demo-check',
            'panel_subdomain' => 'starter-demo-check',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        UserRole::query()->firstOrCreate([
            'user_id' => $this->adminUser->id,
            'role_id' => Role::query()->where('key', 'admin')->value('id'),
            'tenant_account_id' => $this->starterTenant->id,
        ]);
    }

    public function test_demo_tenant_can_access_active_modules_features_and_planned_items_stay_closed(): void
    {
        $access = app(TenantAccessService::class);
        $menu = app(AdminMenuService::class);

        $this->assertTrue($access->canAccessModule($this->demoTenant, 'customer_portal'));
        $this->assertTrue($access->canAccessModule($this->demoTenant, 'quote_customer_approval'));
        $this->assertTrue($access->canAccessModule($this->demoTenant, 'graphic_customer_approval'));
        $this->assertTrue($access->canAccessModule($this->demoTenant, 'notification_center'));

        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'public_quote_approval', 'quote_customer_approval'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'public_graphic_approval', 'graphic_customer_approval'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'customer_login', 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'portal_quotes', 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'portal_orders', 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'notification_logs', 'notification_center'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant, 'whatsapp_links', 'notification_center'));

        $this->assertFalse($access->canAccessModule($this->demoTenant, 'xml_export'));
        $this->assertFalse($access->canAccessModule($this->demoTenant, 'production_qc'));
        $this->assertFalse($access->canAccessModule($this->demoTenant, 'web_quote_widget'));

        $tenantLabels = collect($menu->tenantMenu($this->demoTenant->fresh(), $this->adminUser))
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();

        $this->assertContains('Müşteri Portalı', $tenantLabels);
        $this->assertContains('Bildirim Merkezi', $tenantLabels);
        $this->assertNotContains('Product Data Hub', $tenantLabels);
        $this->assertNotContains('Kalite Kontrol', $tenantLabels);
    }

    public function test_demo_tenant_quote_actions_show_without_module_required_while_normal_starter_tenant_stays_guarded(): void
    {
        $demoQuote = $this->createQuoteForTenant($this->demoTenant, 'TK-DEMO-FULL-0001');
        $starterQuote = $this->createQuoteForTenant($this->starterTenant, 'TK-STARTER-0001');

        $demoResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $demoResponse->assertOk();
        $demoResponse->assertSee($demoQuote->document_number);
        $demoResponse->assertSee('Müşteriye Gönder');
        $demoResponse->assertDontSee('Modül Gerekli');

        $starterResponse = $this->actingAs($this->adminUser)
            ->get('http://' . self::STARTER_HOST . '/admin/promotion-quotes');

        $starterResponse->assertOk();
        $starterResponse->assertSee($starterQuote->document_number);
        $starterResponse->assertDontSee('Müşteriye Gönder');
        $starterResponse->assertDontSee('send-to-customer', false);
    }

    private function createQuoteForTenant(TenantAccount $tenant, string $documentNumber): Order
    {
        $company = Company::query()->firstOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'legal_name' => 'Demo Access Customer ' . $tenant->id,
            ],
            [
                'short_name' => 'Demo Access',
                'status' => 'active',
            ]
        );

        CompanyRole::query()->firstOrCreate([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-19',
            'valid_until' => '2026-06-26',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Demo Access Ürünü',
            'product_code' => 'DEMO-ACCESS-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Demo tenant access test kalemi',
            'product_snapshot' => ['display_name' => 'Demo Access Ürünü'],
            'price_snapshot' => [
                'product_total' => 1000,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 200, 'scope' => 'product'],
                ],
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 250],
            'list_price' => 10,
            'discount_rate' => 0,
            'unit_price' => 10,
            'line_total' => 1000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }
}
