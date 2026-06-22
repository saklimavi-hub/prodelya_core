<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProductPanelTest extends TestCase
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

    public function test_tenant_product_panel_opens_and_uses_clean_display_name(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $supplier = $this->grantSupplierAccess('Akdeniz Promosyon', 'AKDENIZ-PANEL');

        $product = $this->makeCatalogProduct([
            'product_code' => 'AK-1020-LACIVERT',
            'product_name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'standard_category_id' => $category->id,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_group_code' => '1020',
                'supplier_product_code' => '1020',
            ]],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel?search=AK-1020-LACIVERT');

        $response->assertOk();
        $response->assertSeeText('Sade Ürün Paneli');
        $response->assertSeeText($product->display_code);
        $response->assertSeeText('AK-1020 Lacivert Metal Tükenmez Rubber Gövde Kalem');
    }

    public function test_tenant_product_panel_hides_group_code_and_parent_rows_but_shows_sellable_variant(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $supplier = $this->grantSupplierAccess('Etkin Promosyon', 'ETKIN-PANEL');

        $this->makeCatalogProduct([
            'product_code' => 'ET-GRP-0506',
            'product_name' => 'ET-GRP-0506 Grup Ürün',
            'standard_category_id' => $category->id,
            'visible_in_quote' => false,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_group_code' => 'GRP-ONLY-0506',
                'supplier_product_code' => '0506',
            ]],
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
        ]);

        $parent = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => '0506 Plastik Kalem',
            'standard_category_id' => $category->id,
            'visible_in_quote' => false,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_group_code' => 'GRP-ONLY-0506',
                'supplier_product_code' => '0506',
            ]],
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $parent->id,
            'variant_code' => 'ET-0506-K',
            'variant_name' => '0506 Plastik Kalem',
            'variant_color' => 'Kırmızı',
            'variant_size' => null,
            'image_url' => 'https://example.test/et-0506-k.jpg',
            'display_price' => 48,
            'currency' => 'TL',
            'stock_quantity' => 18,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 18,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_group_code' => 'GRP-ONLY-0506',
                'supplier_product_code' => 'ET-0506-K',
            ],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'parent_product_code' => 'ET-0506',
                'variant_color' => 'Kırmızı',
                'price_snapshot' => ['list_price' => 48, 'vat_rate' => 20],
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel');

        $response->assertOk();
        $response->assertSeeText('ET-0506-K');
        $response->assertSeeText('Kırmızı');
        $response->assertDontSee('>Kategori Eşle<', false);
        $response->assertDontSeeText('ET-GRP-0506 Grup Ürün');
        $response->assertDontSeeText('GRP-ONLY-0506');
    }

    public function test_tenant_product_panel_filters_supplier_category_status_group_search_and_limit(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $supplierA = $this->grantSupplierAccess('Yeni Nesil', 'YN-PANEL');
        $supplierB = $this->grantSupplierAccess('İlpen', 'IL-PANEL');

        $this->makeCatalogProduct([
            'product_code' => 'YN-209025',
            'product_name' => 'YN-209025 AKDERE TABA TARİHLİ AJANDA 15X21 CM',
            'standard_category_id' => $category->id,
            'source_summary' => [[
                'supplier_id' => $supplierA->id,
                'supplier_name' => $supplierA->name,
                'supplier_group_code' => 'GRP-YN-209025',
            ]],
        ]);

        $waiting = $this->makeCatalogProduct([
            'product_code' => 'IL-0001',
            'product_name' => 'Kategori Bekleyen Ürün',
            'standard_category_id' => null,
            'source_summary' => [[
                'supplier_id' => $supplierB->id,
                'supplier_name' => $supplierB->name,
                'supplier_group_code' => 'GRP-IL-0001',
            ]],
            'meta' => [
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'price_snapshot' => ['list_price' => 32, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
        ]);

        for ($index = 0; $index < 55; $index++) {
            $this->makeCatalogProduct([
                'product_code' => 'PAGE-' . $index,
                'product_name' => 'Sayfa Ürün ' . $index,
                'standard_category_id' => $category->id,
                'source_summary' => [[
                    'supplier_id' => $supplierA->id,
                    'supplier_name' => $supplierA->name,
                    'supplier_group_code' => 'GRP-PAGE',
                ]],
            ]);
        }

        $supplierResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel?supplier=' . $supplierA->id . '&limit=500');

        $supplierResponse->assertOk();
        $supplierResponse->assertSeeText('YN-209025');
        $supplierResponse->assertDontSeeText($waiting->display_code);

        $categoryWaitingResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel?category_status=category_waiting');

        $categoryWaitingResponse->assertOk();
        $categoryWaitingResponse->assertSeeText($waiting->display_name);

        $groupSearchResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel?search=GRP-YN-209025');

        $groupSearchResponse->assertOk();
        $groupSearchResponse->assertSeeText('YN-209025');

        $limitResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/product-panel?search=PAGE-&limit=50');

        $limitResponse->assertOk();
        $limitResponse->assertSeeText('Toplam 55 kayıt');
    }

    private function grantSupplierAccess(string $name, string $code): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        return $supplier;
    }

    private function makeCatalogProduct(array $attributes = []): TenantCatalogProduct
    {
        $defaults = [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => $attributes['product_name'] ?? 'Katalog Ürünü',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Katalog Ürünü',
            'slug' => 'katalog-urunu-' . uniqid(),
            'standard_category_id' => null,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/product.jpg',
            'display_price' => 100,
            'sale_price' => 100,
            'currency' => 'TL',
            'total_stock_quantity' => 50,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 50,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'hidden_reason' => null,
            'is_featured' => false,
            'local_stock_priority' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
            'is_active' => true,
            'stock_quantity' => 50,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ];

        return TenantCatalogProduct::query()->create(array_merge($defaults, $attributes));
    }
}
