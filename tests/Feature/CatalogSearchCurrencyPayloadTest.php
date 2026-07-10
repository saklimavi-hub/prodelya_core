<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CatalogSearchCurrencyPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private Role $tenantOwnerRole;
    private Role $financeRole;
    private StandardCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->financeRole = Role::query()->where('key', 'finance')->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
    }

    public function test_catalog_search_hides_supplier_currency_details_when_multi_currency_is_not_enabled(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['owner'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/catalog/search?q=ET-USD-001-MV'));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('product_code', 'ET-USD-001-MV');

        $this->assertNotNull($row);
        $this->assertSame(140.0, (float) $row['base_price']);
        $this->assertSame('TRY', $row['base_currency']);
        $this->assertSame('converted', $row['conversion_status']);
        $this->assertFalse((bool) $row['multi_currency_enabled']);
        $this->assertFalse((bool) $row['can_view_currency_details']);
        $this->assertNull($row['source_price']);
        $this->assertNull($row['source_currency']);
        $this->assertArrayNotHasKey('purchase_price', $row['price_snapshot']);
        $this->assertArrayNotHasKey('source_price', $row['price_snapshot']);
    }

    public function test_live_product_info_hides_sensitive_currency_details_without_module_access(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['owner'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['catalogVariant']->id));

        $response->assertOk()
            ->assertJson([
                'base_price' => 140.0,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
                'multi_currency_enabled' => false,
                'can_view_currency_details' => false,
                'source_price' => null,
                'source_currency' => null,
            ]);
    }

    public function test_finance_user_can_receive_currency_detail_contract_when_module_flag_is_open(): void
    {
        $fixture = $this->makeFixture();
        $tenant = $fixture['tenant'];
        $mock = Mockery::mock(TenantAccessService::class);
        $mock->shouldReceive('canAccessModule')
            ->with(Mockery::on(fn ($candidate) => $candidate instanceof TenantAccount && (int) $candidate->id === (int) $tenant->id), 'multi_currency')
            ->andReturn(true);
        $this->app->instance(TenantAccessService::class, $mock);

        $response = $this->actingAs($fixture['finance'], 'web')
            ->getJson($this->tenantUrl($tenant, '/admin/catalog/search?q=ET-USD-001-MV'));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('product_code', 'ET-USD-001-MV');

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row['multi_currency_enabled']);
        $this->assertTrue((bool) $row['can_view_currency_details']);
        $this->assertSame(4.0, (float) $row['source_price']);
        $this->assertSame('USD', $row['source_currency']);
        $this->assertSame(35.0, (float) $row['applied_rate']);
        $this->assertSame('tcmb', $row['rate_source']);
    }

    private function makeFixture(): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Currency Payload Tenant',
            'legal_name' => 'Currency Payload Tenant A.S.',
            'slug' => 'currency-payload-' . uniqid(),
            'panel_subdomain' => 'currency-payload-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $owner = $this->createTenantUser($tenant, $this->tenantOwnerRole, 'owner');
        $finance = $this->createTenantUser($tenant, $this->financeRole, 'finance');

        $supplier = Supplier::query()->create([
            'name' => 'Currency Supplier',
            'code' => 'CUR-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);

        $currencySnapshot = [
            'source_price' => 4.0,
            'source_currency' => 'USD',
            'base_price' => 140.0,
            'base_currency' => 'TRY',
            'conversion_available' => true,
            'conversion_status' => 'converted',
            'applied_rate' => 35.0,
            'rate_date' => '2026-07-10',
            'rate_source' => 'tcmb',
            'rate_type' => 'forex_selling',
            'is_fallback_rate' => false,
            'is_stale_rate' => false,
            'currency_origin' => 'product_field',
            'currency_status' => 'resolved',
        ];

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'ET-USD-001',
            'sku' => 'ET-USD-001',
            'product_name' => 'USD Kalem',
            'base_product_name' => 'USD Kalem',
            'name' => 'USD Kalem',
            'slug' => 'usd-kalem-' . uniqid(),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'currency' => 'USD',
            'min_purchase_price' => 4.0,
            'max_purchase_price' => 4.0,
            'total_stock_quantity' => 12,
            'supplier_count' => 1,
            'variant_count' => 1,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'ET-USD-001',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 4.0,
                    'purchase_price' => 3.5,
                    'source_price' => 4.0,
                    'source_currency' => 'USD',
                    'currency_origin' => 'product_field',
                    'currency_status' => 'resolved',
                    'currency_snapshot' => $currencySnapshot,
                ],
            ],
            'is_active' => true,
        ]);

        $standardVariant = StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => 'ET-USD-001-MV',
            'generated_variant_code' => 'ET-USD-001-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'stock_quantity' => 12,
            'min_purchase_price' => 4.0,
            'max_purchase_price' => 4.0,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => 'ET-USD-001-MV',
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 140.0,
                    'purchase_price' => 3.5,
                    'source_price' => 4.0,
                    'source_currency' => 'USD',
                    'currency_origin' => 'product_field',
                    'currency_status' => 'resolved',
                    'currency_snapshot' => $currencySnapshot,
                ],
            ],
        ]);

        $catalogProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-USD-' . strtoupper(substr(uniqid(), -6)),
            'name' => 'USD Kalem',
            'product_code' => 'ET-USD-001',
            'product_name' => 'USD Kalem',
            'slug' => 'tenant-usd-kalem-' . uniqid(),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'display_price' => 140.0,
            'sale_price' => 140.0,
            'currency' => 'TRY',
            'total_stock_quantity' => 12,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 12,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'ET-USD-001',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 140.0,
                    'purchase_price' => 3.5,
                    'source_price' => 4.0,
                    'source_currency' => 'USD',
                    'currency_origin' => 'product_field',
                    'currency_status' => 'resolved',
                    'currency_snapshot' => $currencySnapshot,
                ],
                'is_parent' => false,
                'is_sellable' => true,
            ],
            'is_active' => true,
            'stock_quantity' => 12,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $catalogVariant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'standard_product_variant_id' => $standardVariant->id,
            'variant_code' => 'ET-USD-001-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'display_price' => 140.0,
            'currency' => 'TRY',
            'stock_quantity' => 12,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 12,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => 'ET-USD-001-MV',
            ],
            'meta' => [
                'quote_search_visible' => true,
                'price_snapshot' => [
                    'list_price' => 140.0,
                    'purchase_price' => 3.5,
                    'source_price' => 4.0,
                    'source_currency' => 'USD',
                    'currency_origin' => 'product_field',
                    'currency_status' => 'resolved',
                    'currency_snapshot' => $currencySnapshot,
                ],
            ],
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
        ]);

        return compact('tenant', 'owner', 'finance', 'catalogProduct', 'catalogVariant');
    }

    private function createTenantUser(TenantAccount $tenant, Role $role, string $prefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($prefix) . ' User',
            'email' => $prefix . '-' . uniqid() . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
