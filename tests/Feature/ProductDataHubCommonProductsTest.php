<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubCommonProductsTest extends TestCase
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

    public function test_common_products_page_opens_and_default_limit_is_50(): void
    {
        $supplier = $this->makeSupplier('COMMON-LIMIT', 'Limit Supplier');

        $this->makeFlatProduct($supplier, 'BULK-001', 'Bulk Product 001');
        for ($index = 2; $index <= 55; $index++) {
            $this->makeFlatProduct(
                $supplier,
                'BULK-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Bulk Product ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT)
            );
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products');

        $response->assertOk();
        $response->assertSeeText('Prodelya Ortak Ürün Havuzu');
        $response->assertSeeText('Toplam kayıt: 55');
        $response->assertSeeText('Gösterilen: 50');
    }

    public function test_common_products_limit_and_supplier_filters_work(): void
    {
        $supplierA = $this->makeSupplier('COMMON-SUP-A', 'Etkin');
        $supplierB = $this->makeSupplier('COMMON-SUP-B', 'Akdeniz');

        $this->makeFlatProduct($supplierA, 'ETA-001', 'Etkin Kalem');
        $this->makeFlatProduct($supplierB, 'AKA-001', 'Akdeniz Kalem');

        $allResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?limit=all&supplier=Etkin');

        $allResponse->assertOk();
        $allResponse->assertSeeText('ETA-001');
        $allResponse->assertDontSeeText('AKA-001');
        $allResponse->assertSeeText('Tüm ürünleri göstermek ekranı yavaşlatabilir.');
    }

    public function test_product_type_and_sellable_filters_work(): void
    {
        $supplier = $this->makeSupplier('COMMON-TYPE', 'Type Supplier');
        $parent = $this->makeParentProduct($supplier, 'ET-0506', 'ET-0506 Plastik Kalem');
        $this->makeVariant($parent, 'ET-0506-L', 'Lacivert', 10, 12.5);
        $this->makeFlatProduct($supplier, 'FLAT-001', 'Flat Ürün');

        $parentResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?product_type=parent&sellable=catalog_group');

        $parentResponse->assertOk();
        $parentResponse->assertSeeText('ET-0506');
        $parentResponse->assertDontSeeText('FLAT-001');
        $parentResponse->assertSeeText('Sadece katalog grup');
    }

    public function test_common_pool_can_show_parent_and_variants_for_same_group_code(): void
    {
        $supplier = $this->makeSupplier('ETKIN-0506', 'Etkin');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $parent = $this->makeParentProduct($supplier, 'ET-0506', 'ET-0506 Plastik Kalem', $category->id);
        $variantL = $this->makeVariant($parent, 'ET-0506-L', 'Lacivert', 15, 10.5);
        $variantS = $this->makeVariant($parent, 'ET-0506-S', 'Siyah', 18, 10.5);
        $variantMv = $this->makeVariant($parent, 'ET-0506-MV', 'Mavi', 22, 10.5);

        $catalogParent = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $parent->id,
            'tenant_sku' => 'ET-0506',
            'name' => $parent->display_name,
            'product_code' => 'ET-0506',
            'product_name' => $parent->display_name,
            'slug' => 'et-0506',
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/et-0506.jpg',
            'display_price' => 10.5,
            'sale_price' => 10.5,
            'currency' => 'TL',
            'total_stock_quantity' => 55,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 55,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'quote_search_visible' => false,
                'supplier_group_code' => '0506',
                'warning_snapshot' => [],
                'price_snapshot' => ['list_price' => 10.5, 'vat_rate' => 20],
            ],
            'is_active' => true,
            'stock_quantity' => 55,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        foreach ([$variantL, $variantS, $variantMv] as $variant) {
            TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'tenant_catalog_product_id' => $catalogParent->id,
                'standard_product_variant_id' => $variant->id,
                'variant_code' => $variant->generated_variant_code,
                'variant_name' => $variant->display_name,
                'variant_color' => $variant->variant_color,
                'variant_size' => $variant->variant_size,
                'image_url' => 'https://example.test/' . $variant->generated_variant_code . '.jpg',
                'display_price' => 10.5,
                'currency' => 'TL',
                'stock_quantity' => $variant->stock_quantity,
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => $variant->stock_quantity,
                'safe_stock_quantity' => 0,
                'visible_in_catalog' => true,
                'is_active' => true,
                'source_summary' => ['supplier_group_code' => '0506'],
                'meta' => [
                    'quote_search_visible' => true,
                    'is_variant' => true,
                    'is_sellable' => true,
                    'parent_product_code' => 'ET-0506',
                    'parent_product_name' => $parent->display_name,
                    'price_snapshot' => ['list_price' => 10.5, 'vat_rate' => 20],
                    'variant_attributes' => [],
                ],
            ]);
        }

        $poolResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?q=0506&limit=all');

        $poolResponse->assertOk();
        $poolResponse->assertSeeText('ET-0506');
        $poolResponse->assertSeeText('ET-0506-L');
        $poolResponse->assertSeeText('ET-0506-S');
        $poolResponse->assertSeeText('ET-0506-MV');

    }

    public function test_common_products_uses_standard_display_name(): void
    {
        $supplier = $this->makeSupplier('DISPLAY-NAME', 'Ilpen');
        $product = $this->makeFlatProduct($supplier, 'IL-1852', 'Siyah Kutulu VIP Set');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?q=IL-1852');

        $response->assertOk();
        $response->assertSeeText($product->display_name);
        $response->assertSeeText('IL-1852 Siyah Kutulu VIP Set');
    }

    public function test_common_products_handles_category_string_null_and_model_without_500(): void
    {
        $supplier = $this->makeSupplier('COMMON-CAT', 'Kategori Supplier');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-STRING',
            'sku' => 'CAT-STRING',
            'product_name' => 'String Kategori Ürün',
            'base_product_name' => 'String Kategori Ürün',
            'name' => 'String Kategori Ürün',
            'category' => 'Düz Kategori Metni',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => [],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-STRING-ID',
            'sku' => 'CAT-STRING-ID',
            'product_name' => 'String Kategori ID Ürün',
            'base_product_name' => 'String Kategori ID Ürün',
            'name' => 'String Kategori ID Ürün',
            'standard_category_id' => $category->id,
            'category' => 'String Snapshot Kategori',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => [],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-FALLBACK',
            'sku' => 'CAT-FALLBACK',
            'product_name' => 'Fallback Kategori Ürün',
            'base_product_name' => 'Fallback Kategori Ürün',
            'name' => 'Fallback Kategori Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => ['category_name' => 'Kategori Adi Fallback'],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-MODEL',
            'sku' => 'CAT-MODEL',
            'product_name' => 'Model Kategori Ürün',
            'base_product_name' => 'Model Kategori Ürün',
            'name' => 'Model Kategori Ürün',
            'standard_category_id' => $category->id,
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => [],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?limit=all&q=CAT-');

        $response->assertOk();
        $response->assertSeeText('Düz Kategori Metni');
        $response->assertSeeText('Kategori Adi Fallback');
        $response->assertSeeText($category->full_path);
    }

    public function test_common_products_uses_source_summary_supplier_category_name_fallback(): void
    {
        $supplier = $this->makeSupplier('COMMON-SUMMARY', 'Summary Supplier');

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-SUMMARY',
            'sku' => 'CAT-SUMMARY',
            'product_name' => 'Summary Kategori Ürün',
            'base_product_name' => 'Summary Kategori Ürün',
            'name' => 'Summary Kategori Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_category_name' => 'Tedarikçi Kalemleri',
            ]],
            'meta' => [],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?limit=all&q=CAT-SUMMARY');

        $response->assertOk();
        $response->assertSeeText('Tedarikçi Kalemleri');
    }

    public function test_standard_product_category_display_name_uses_category_name_attribute_fallback(): void
    {
        $product = new StandardProduct();
        $product->forceFill([
            'standard_product_code' => 'DIRECT-CATEGORY',
            'category_name' => 'Doğrudan Kategori Adı',
        ]);

        $this->assertSame('Doğrudan Kategori Adı', $product->category_display_name);
    }

    public function test_standard_products_page_handles_category_fallbacks_without_500(): void
    {
        $supplier = $this->makeSupplier('STANDARD-CAT', 'Standard Supplier');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'STD-STRING',
            'sku' => 'STD-STRING',
            'product_name' => 'Std String Ürün',
            'base_product_name' => 'Std String Ürün',
            'name' => 'Std String Ürün',
            'category' => 'String Standart Kategori',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => [],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'STD-SUMMARY',
            'sku' => 'STD-SUMMARY',
            'product_name' => 'Std Summary Ürün',
            'base_product_name' => 'Std Summary Ürün',
            'name' => 'Std Summary Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_category_name' => 'Summary Standart Kategori',
            ]],
            'meta' => [],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'STD-MODEL',
            'sku' => 'STD-MODEL',
            'product_name' => 'Std Model Ürün',
            'base_product_name' => 'Std Model Ürün',
            'name' => 'Std Model Ürün',
            'standard_category_id' => $category->id,
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'total_stock_quantity' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id]],
            'meta' => [],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products');

        $response->assertOk();
        $response->assertSeeText('String Standart Kategori');
        $response->assertSeeText('Summary Standart Kategori');
        $response->assertSeeText($category->full_path);
    }

    public function test_standard_products_default_limit_is_50_and_pagination_is_visible(): void
    {
        $supplier = $this->makeSupplier('STD-LIMIT', 'Standart Limit Supplier');

        for ($index = 1; $index <= 55; $index++) {
            $this->makeFlatProduct(
                $supplier,
                'STD-LIMIT-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Standart Limit Ürün ' . $index
            );
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?q=STD-LIMIT');

        $response->assertOk();
        $response->assertSeeText('Toplam 55 kayıt');
        $response->assertSeeText('1 - 50 arası gösteriliyor');
        $response->assertSeeText('Sayfa 1 / 2');
        $response->assertSeeText('Sonraki');
    }

    public function test_standard_products_limit_options_and_all_warning_work(): void
    {
        $supplier = $this->makeSupplier('STD-LIMIT-OPTIONS', 'Standart Limit Options');

        for ($index = 1; $index <= 120; $index++) {
            $this->makeFlatProduct(
                $supplier,
                'STD-OPT-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Standart Limit Seçenek Ürün ' . $index
            );
        }

        foreach ([100, 250, 500] as $limit) {
            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get('/admin/super-admin/product-data-hub/standard-products?q=STD-OPT&limit=' . $limit);

            $response->assertOk();
            $response->assertSeeText('Toplam 120 kayıt');
        }

        $allResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?q=STD-OPT&limit=all');

        $allResponse->assertOk();
        $allResponse->assertSeeText('Tüm ürünleri göstermek ekranı yavaşlatabilir.');
        $allResponse->assertSeeText('1 - 120 arası gösteriliyor');
    }

    public function test_standard_products_filters_supplier_product_type_sellable_and_search(): void
    {
        $supplierA = $this->makeSupplier('STD-FILTER-A', 'Standart Etkin');
        $supplierB = $this->makeSupplier('STD-FILTER-B', 'Standart Akdeniz');
        $parent = $this->makeParentProduct($supplierA, 'STD-GROUP-0506', 'STD-GROUP-0506 Plastik Kalem');
        $this->makeVariant($parent, 'STD-GROUP-0506-L', 'Lacivert', 12, 10.5);
        $this->makeFlatProduct($supplierB, 'STD-FLAT-001', 'Akdeniz Flat Ürün');

        $supplierResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=all&supplier=Standart Etkin');

        $supplierResponse->assertOk();
        $supplierResponse->assertSeeText('STD-GROUP-0506');
        $supplierResponse->assertDontSeeText('STD-FLAT-001');

        $variantResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=all&product_type=variant&q=0506-L');

        $variantResponse->assertOk();
        $variantResponse->assertSeeText('STD-GROUP-0506-L');
        $variantResponse->assertDontSeeText('STD-FLAT-001');

        $catalogGroupResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=all&sellable=catalog_group&q=STD-GROUP');

        $catalogGroupResponse->assertOk();
        $catalogGroupResponse->assertSeeText('Grup ürün');
        $catalogGroupResponse->assertSeeText('STD-GROUP-0506');
    }

    public function test_standard_products_filters_price_stock_category_warning_and_tenant_projection_status(): void
    {
        $supplier = $this->makeSupplier('STD-STATUS', 'Standart Status Supplier');
        $product = $this->makeFlatProduct($supplier, 'STD-STATUS-OK', 'Durumlu Ürün');
        $product->forceFill([
            'standard_category_id' => null,
            'min_purchase_price' => null,
            'total_stock_quantity' => 0,
            'warning_flag' => true,
            'meta' => [
                'supplier_warning_flag' => true,
            ],
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=all&q=STD-STATUS&category_status=category_missing&price_status=price_missing&stock_status=out_of_stock&warning_status=red_product&tenant_projection_status=not_projected');

        $response->assertOk();
        $response->assertSeeText('STD-STATUS-OK');
        $response->assertSeeText('Fiyat eksik');
        $response->assertSeeText('Kırmızı ürün');
        $response->assertSeeText('Bekliyor');
    }

    public function test_standard_products_pagination_preserves_filters(): void
    {
        $supplier = $this->makeSupplier('STD-PAGE', 'Standart Page Supplier');

        for ($index = 1; $index <= 60; $index++) {
            $this->makeFlatProduct(
                $supplier,
                'STD-PAGE-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Sayfalı Ürün ' . $index
            );
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?q=STD-PAGE&supplier=Standart Page Supplier&limit=50');

        $response->assertOk();
        $response->assertSee('q=STD-PAGE', false);
        $response->assertSee('supplier=Standart%20Page%20Supplier', false);
        $response->assertSee('limit=50', false);
    }

    private function makeSupplier(string $code, string $name): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code,
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_name' => $name . ' XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => [
                'profile_key' => $code,
                'sync_policy' => ['sync_frequency' => 'daily'],
            ],
        ]);

        return $supplier;
    }

    private function makeFlatProduct(Supplier $supplier, string $code, string $name, ?int $categoryId = null): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => $name,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $code)),
            'standard_category_id' => $categoryId,
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 100,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_product_code' => $code,
                'supplier_group_code' => $code,
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 100,
                    'vat_rate' => 20,
                ],
            ],
        ]);
    }

    private function makeParentProduct(Supplier $supplier, string $code, string $name, ?int $categoryId = null): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => 'Plastik Kalem',
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $code)),
            'standard_category_id' => $categoryId,
            'currency' => 'TL',
            'min_purchase_price' => 10.5,
            'max_purchase_price' => 12.5,
            'total_stock_quantity' => 55,
            'supplier_count' => 1,
            'variant_count' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_product_code' => $code,
                'supplier_group_code' => '0506',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 10.5,
                    'vat_rate' => 20,
                ],
            ],
        ]);
    }

    private function makeVariant(StandardProduct $product, string $code, string $color, int $stock, float $price): StandardProductVariant
    {
        return StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => $code,
            'generated_variant_code' => $code,
            'variant_name' => 'Plastik Kalem',
            'variant_color' => $color,
            'variant_size' => null,
            'variant_attributes' => [],
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'stock_quantity' => $stock,
            'min_purchase_price' => $price,
            'max_purchase_price' => $price,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_group_code' => '0506',
                'variant_stock_code' => $code,
                'supplier_product_code' => $code,
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => $price,
                    'vat_rate' => 20,
                ],
            ],
        ]);
    }
}
