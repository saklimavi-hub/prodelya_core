<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureRouteEnforcementTest extends TestCase
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
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'feature-guarded',
            'slug' => 'feature-guarded',
        ])->save();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereIn('module_key', ['product_data_hub', 'quote_customer_approval'])
            ->delete();

        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'portal_enabled', false, 'boolean');

        if (!Route::has('test.feature-customer-approval')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'feature.enabled:customer_quote_approval'])
                ->get('/admin/test/feature-customer-approval', fn () => response('feature-ok', 200))
                ->name('test.feature-customer-approval');
        }
    }

    public function test_feature_routes_return_403_when_disabled_and_open_when_enabled(): void
    {
        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'product_data_hub',
            'feature_key' => 'tenant_catalog_projection',
            'is_enabled' => true,
        ]);

        $exports = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.exports'));

        $exports->assertForbidden();
        $exports->assertSee('aktif değil');
        $exports->assertDontSee('Stack trace');

        $featureDisabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/feature-customer-approval');

        $featureDisabled->assertForbidden();
        $featureDisabled->assertSee('aktif değil');

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
        ]);

        $featureEnabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/feature-customer-approval');

        $featureEnabled->assertOk();
        $featureEnabled->assertSee('feature-ok');
    }

    public function test_real_quote_send_route_is_protected_by_feature_middleware(): void
    {
        $customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'customer_company_id' => $customer->id,
            'document_number' => 'QTE-FEATURE-001',
            'document_type' => 'quote',
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-17',
            'valid_until' => '2026-06-24',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'notes' => 'Feature route enforcement quote',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
            'product_total' => 100,
            'print_total' => 0,
            'vat_breakdown_json' => [
                ['rate' => 20, 'total' => 20, 'scope' => 'product'],
            ],
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Feature Test Kalem',
            'product_code' => 'FEATURE-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Feature route test item',
            'product_snapshot' => ['display_name' => 'Feature Test Kalem'],
            'price_snapshot' => [
                'product_total' => 100,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 20, 'scope' => 'product'],
                ],
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 20],
            'list_price' => 10,
            'discount_rate' => 0,
            'unit_price' => 10,
            'line_total' => 100,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $disabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), []);

        $disabled->assertForbidden();

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
        ]);

        $enabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), []);

        $enabled->assertStatus(302);
    }
}
