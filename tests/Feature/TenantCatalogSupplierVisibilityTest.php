<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCatalogSupplierVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private Role $tenantOwnerRole;
    private StandardCategory $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $this->tenant = TenantAccount::query()->create([
            'name' => 'Katalog Supplier Tenant',
            'legal_name' => 'Katalog Supplier Tenant',
            'slug' => 'catalog-supplier-tenant',
            'panel_subdomain' => 'catalog-supplier-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $this->owner = User::query()->create([
            'name' => 'Catalog Owner',
            'email' => 'catalog-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);
        $this->supplier = Supplier::query()->create([
            'name' => 'Visibility Supplier',
            'code' => 'VIS-SUP',
            'status' => 'active',
        ]);
    }

    public function test_projected_supplier_products_are_visible_in_catalog_and_quote_search_when_flags_are_open(): void
    {
        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $this->supplier->id,
            'standard_product_code' => 'VIS-001',
            'sku' => 'VIS-001',
            'product_name' => 'Visibility Ürün',
            'base_product_name' => 'Visibility Ürün',
            'name' => 'Visibility Ürün',
            'slug' => 'visibility-urun',
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/vis-001.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 120,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $this->supplier->id,
                'supplier_name' => $this->supplier->name,
                'supplier_product_code' => 'VIS-001',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 100,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
        ])->assertExitCode(0);

        $catalogResponse = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/catalog/supplier-products'));

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('VIS-001');
        $catalogResponse->assertSeeText('Visibility Supplier');

        $searchResponse = $this->actingAs($this->owner, 'web')
            ->getJson($this->tenantUrl('/admin/catalog/search?q=VIS-001'));

        $searchResponse->assertOk();
        $searchResponse->assertJsonFragment([
            'product_code' => 'VIS-001',
            'tenant_catalog_product_id' => TenantCatalogProduct::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('standard_product_id', $standardProduct->id)
                ->value('id'),
        ]);
    }

    public function test_visible_in_quote_flag_controls_quote_search_without_hiding_catalog_listing(): void
    {
        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $this->supplier->id,
            'standard_product_code' => 'VIS-002',
            'sku' => 'VIS-002',
            'product_name' => 'Quote Kapalı Ürün',
            'base_product_name' => 'Quote Kapalı Ürün',
            'name' => 'Quote Kapalı Ürün',
            'slug' => 'quote-kapali-urun',
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/vis-002.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 120,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $this->supplier->id,
                'supplier_name' => $this->supplier->name,
                'supplier_product_code' => 'VIS-002',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 100,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => false,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
        ])->assertExitCode(0);

        $catalogProduct = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $standardProduct->id)
            ->firstOrFail();

        $this->assertTrue($catalogProduct->visible_in_catalog);
        $this->assertFalse($catalogProduct->visible_in_quote);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/catalog/supplier-products'))
            ->assertOk()
            ->assertSeeText('VIS-002');

        $this->actingAs($this->owner, 'web')
            ->getJson($this->tenantUrl('/admin/catalog/search?q=VIS-002'))
            ->assertOk()
            ->assertJsonMissing(['product_code' => 'VIS-002']);

        $this->actingAs($this->owner, 'web')
            ->getJson($this->tenantUrl('/admin/catalog/search?q=VIS-002&only_quote_visible=0'))
            ->assertOk()
            ->assertJsonFragment(['product_code' => 'VIS-002']);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
