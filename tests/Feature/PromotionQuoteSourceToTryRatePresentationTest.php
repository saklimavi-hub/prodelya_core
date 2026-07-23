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

class PromotionQuoteSourceToTryRatePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_live_product_info_returns_source_to_try_rate_in_sales_presentation_not_identity_rate(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $tenant = TenantAccount::query()->create([
            'name' => 'Source Rate Tenant',
            'legal_name' => 'Source Rate Tenant A.S.',
            'slug' => 'source-rate-' . uniqid(),
            'panel_subdomain' => 'source-rate-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $finance = User::query()->create([
            'name' => 'Source Rate Finance',
            'email' => 'source-rate-' . uniqid() . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $finance->id,
            'role_id' => $financeRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $finance->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Source Rate Supplier',
            'code' => 'SR-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);

        $snapshot = [
            'source_price' => 9.75,
            'source_currency' => 'USD',
            'base_price' => 457.20,
            'base_currency' => 'TRY',
            'conversion_status' => 'converted',
            'applied_rate' => 46.8923,
            'rate_date' => '2026-07-12',
            'rate_source' => 'tcmb',
            'rate_type' => 'forex_selling',
        ];

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'SR-USD-001',
            'sku' => 'SR-USD-001',
            'product_name' => 'Source Rate Product',
            'base_product_name' => 'Source Rate Product',
            'name' => 'Source Rate Product',
            'slug' => 'source-rate-product-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'currency' => 'USD',
            'min_purchase_price' => 9.75,
            'max_purchase_price' => 9.75,
            'total_stock_quantity' => 3900,
            'supplier_count' => 1,
            'variant_count' => 1,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'SR-USD-001',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 457.20,
                    'source_price' => 9.75,
                    'source_currency' => 'USD',
                    'currency_snapshot' => $snapshot,
                ],
            ],
            'is_active' => true,
        ]);

        $standardVariant = StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => 'SR-USD-001-SY',
            'generated_variant_code' => 'SR-USD-001-SY',
            'variant_name' => 'Siyah',
            'variant_color' => 'Siyah',
            'stock_quantity' => 3900,
            'min_purchase_price' => 9.75,
            'max_purchase_price' => 9.75,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => 'SR-USD-001-SY',
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 457.20,
                    'source_price' => 9.75,
                    'source_currency' => 'USD',
                    'currency_snapshot' => $snapshot,
                ],
            ],
        ]);

        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-SR-' . strtoupper(substr(uniqid(), -6)),
            'name' => 'Source Rate Product',
            'product_code' => 'SR-USD-001',
            'product_name' => 'Source Rate Product',
            'slug' => 'tenant-source-rate-product-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'display_price' => 457.20,
            'sale_price' => 457.20,
            'currency' => 'TRY',
            'total_stock_quantity' => 3900,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 3900,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'SR-USD-001',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 457.20,
                    'source_price' => 9.75,
                    'source_currency' => 'USD',
                    'currency_snapshot' => $snapshot,
                ],
                'is_parent' => false,
                'is_sellable' => true,
            ],
            'is_active' => true,
            'stock_quantity' => 3900,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => $standardVariant->id,
            'variant_code' => 'SR-USD-001-SY',
            'variant_name' => 'Siyah',
            'variant_color' => 'Siyah',
            'display_price' => 457.20,
            'currency' => 'TRY',
            'stock_quantity' => 3900,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 3900,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => 'SR-USD-001-SY',
            ],
            'meta' => [
                'quote_search_visible' => true,
                'price_snapshot' => [
                    'list_price' => 457.20,
                    'source_price' => 9.75,
                    'source_currency' => 'USD',
                    'currency_snapshot' => $snapshot,
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

        $mock = Mockery::mock(TenantAccessService::class);
        $mock->shouldReceive('canAccessModule')->andReturn(true);
        $this->app->instance(TenantAccessService::class, $mock);

        $response = $this->actingAs($finance, 'web')
            ->getJson($this->tenantUrl($tenant, '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $variant->id . '&currency=TRY'));

        $response->assertOk();
        $response->assertJson([
            'source_currency' => 'USD',
            'base_currency' => 'TRY',
            'source_to_base_rate' => 46.8923,
        ]);
        $this->assertSame(46.8923, round((float) data_get($response->json(), 'quote_price_snapshot.sales_presentation.sales_rate'), 4));
        $this->assertNotSame(1.0, round((float) data_get($response->json(), 'quote_price_snapshot.sales_presentation.sales_rate'), 4));
        $this->assertSame('tcmb', data_get($response->json(), 'source_to_base_rate_source'));
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
