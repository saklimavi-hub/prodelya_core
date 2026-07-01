<?php

namespace Tests\Feature;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProductDataHub\VariantHealthScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubVariantHealthTest extends TestCase
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

    public function test_group_code_search_returns_all_sellable_variants_and_hides_parent(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-ET', 'Health Etkin');
        $parent = $this->parentProduct($supplier, 'ET-9900', '9900', 'Health Plastik Kalem');

        $catalogParent = $this->catalogParent($supplier, $source, $parent, '9900', false);

        foreach ([
            ['ET-9900-L', 'Lacivert'],
            ['ET-9900-S', 'Siyah'],
            ['ET-9900-MV', 'Mavi'],
        ] as [$code, $color]) {
            $variant = $this->standardVariant($parent, $code, $color);
            $this->catalogVariant($catalogParent, $variant, $supplier, $source, '9900', $code, $color);
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=9900');

        $response->assertOk();
        $response->assertJsonMissing(['product_code' => 'ET-9900']);
        $response->assertJsonFragment(['product_code' => 'ET-9900-L']);
        $response->assertJsonFragment(['product_code' => 'ET-9900-S']);
        $response->assertJsonFragment(['product_code' => 'ET-9900-MV']);

        $payload = collect($response->json());
        $this->assertCount(3, $payload->whereIn('product_code', ['ET-9900-L', 'ET-9900-S', 'ET-9900-MV']));
        $this->assertFalse($payload->contains(fn (array $row) => array_key_exists('group_product_code', $row)));
    }

    public function test_flat_product_remains_visible_in_quote_search(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-FLAT', 'Health Flat Supplier');
        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'HF-1001',
            'sku' => 'HF-1001',
            'product_name' => 'Flat Satılabilir Ürün',
            'base_product_name' => 'Flat Satılabilir Ürün',
            'name' => 'Flat Satılabilir Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 15,
            'total_stock_quantity' => 12,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => '1001',
            ]],
        ]);

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $product->id,
            'tenant_sku' => 'HF-1001',
            'name' => $product->display_name,
            'product_code' => 'HF-1001',
            'product_name' => $product->display_name,
            'slug' => 'hf-1001',
            'display_price' => 15,
            'currency' => 'TL',
            'total_stock_quantity' => 12,
            'supplier_stock_quantity' => 12,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_source_id' => $source->id]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'meta' => ['price_snapshot' => ['list_price' => 15, 'vat_rate' => 20]],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=HF-1001');

        $response->assertOk();
        $response->assertJsonFragment(['product_code' => 'HF-1001']);
    }

    public function test_variant_health_scanner_reports_projection_mismatch(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-MISMATCH', 'Health Mismatch Supplier');
        $parent = $this->parentProduct($supplier, 'HM-7700', '7700', 'Mismatch Grup Ürün');
        $raw = SupplierProductRaw::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'raw-7700',
            'source_sku' => '7700',
            'source_name' => 'Mismatch Grup Ürün',
            'supplier_product_code' => '7700',
            'supplier_group_code' => '7700',
            'product_name' => 'Mismatch Grup Ürün',
            'standard_product_id' => $parent->id,
        ]);
        $parent->forceFill([
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'raw_product_id' => $raw->id,
                'supplier_group_code' => '7700',
            ]],
        ])->save();

        foreach (['HM-7700-A', 'HM-7700-B', 'HM-7700-C'] as $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());
            SupplierProductVariantRaw::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_raw_id' => $raw->id,
                'standard_product_variant_id' => $variant->id,
                'supplier_group_code' => '7700',
                'variant_code' => $code,
                'generated_variant_code' => $code,
            ]);
        }

        $catalogParent = $this->catalogParent($supplier, $source, $parent, '7700', false);
        $this->catalogVariant($catalogParent, $parent->variants()->first(), $supplier, $source, '7700', 'HM-7700-A', 'A');

        $rows = app(VariantHealthScanner::class)->scanSupplier($supplier, $this->tenant, 10);
        $report = $rows->firstWhere('group_code', '7700');

        $this->assertNotNull($report);
        $this->assertSame(3, $report['raw_variant_count']);
        $this->assertSame(3, $report['standard_variant_count']);
        $this->assertSame(1, $report['tenant_catalog_variant_count']);
        $this->assertTrue($report['has_missing_projection']);
        $this->assertContains('projection_missing', $report['mismatch_types']);
        $this->assertSame('needs_review', $report['status']);
    }

    public function test_variant_health_scanner_does_not_count_flat_products_as_review_groups(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-FLAT-SCAN', 'Health Flat Scanner');

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'HFS-1001',
            'sku' => 'HFS-1001',
            'product_name' => 'Flat Scanner Ürün',
            'base_product_name' => 'Flat Scanner Ürün',
            'name' => 'Flat Scanner Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 10,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => '1001',
            ]],
        ]);

        $rows = app(VariantHealthScanner::class)->scanSupplier($supplier, $this->tenant, 10);

        $this->assertTrue($rows->isEmpty());
    }

    public function test_variant_health_scanner_marks_complete_parent_variant_set_as_healthy(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-OK', 'Health Complete Supplier');
        $category = $this->standardCategory();
        $parent = $this->parentProduct($supplier, 'HC-8800', '8800', 'Tam Grup Ürün');
        $parent->forceFill(['standard_category_id' => $category->id])->save();
        $catalogParent = $this->catalogParent($supplier, $source, $parent, '8800', false);

        foreach (['HC-8800-A', 'HC-8800-B'] as $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());
            $this->catalogVariant($catalogParent, $variant, $supplier, $source, '8800', $code, str($code)->afterLast('-')->toString());
        }

        $rows = app(VariantHealthScanner::class)->scanSupplier($supplier, $this->tenant, 10);
        $report = $rows->firstWhere('group_code', '8800');

        $this->assertNotNull($report);
        $this->assertSame('healthy', $report['status']);
        $this->assertSame(['healthy'], $report['mismatch_types']);
        $this->assertFalse($report['repair_candidate']);
    }

    public function test_variant_health_scanner_reports_search_visibility_missing(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-SEARCH', 'Health Search Supplier');
        $category = $this->standardCategory();
        $parent = $this->parentProduct($supplier, 'HS-6600', '6600', 'Search Grup Ürün');
        $parent->forceFill(['standard_category_id' => $category->id])->save();
        $catalogParent = $this->catalogParent($supplier, $source, $parent, '6600', false);

        foreach (['HS-6600-A', 'HS-6600-B'] as $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());
            $tenantVariant = $this->catalogVariant($catalogParent, $variant, $supplier, $source, '6600', $code, str($code)->afterLast('-')->toString());
            $tenantVariant->forceFill(['meta' => array_merge($tenantVariant->meta, ['quote_search_visible' => false])])->save();
        }

        $report = app(VariantHealthScanner::class)
            ->scanSupplier($supplier, $this->tenant, 10)
            ->firstWhere('group_code', '6600');

        $this->assertContains('search_visibility_missing', $report['mismatch_types']);
        $this->assertSame(2, $report['missing_in_search_count']);
        $this->assertTrue($report['repair_candidate']);
    }

    public function test_variant_health_scanner_blocks_category_missing_from_safe_repair(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-CAT-BLOCK', 'Health Category Block Supplier');
        $parent = $this->parentProduct($supplier, 'HCB-4400', '4400', 'Kategori Eksik Grup');
        $catalogParent = $this->catalogParent($supplier, $source, $parent, '4400', false);

        foreach (['HCB-4400-A', 'HCB-4400-B'] as $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());
            $this->catalogVariant($catalogParent, $variant, $supplier, $source, '4400', $code, str($code)->afterLast('-')->toString());
        }

        $report = app(VariantHealthScanner::class)
            ->scanSupplier($supplier, $this->tenant, 10)
            ->firstWhere('group_code', '4400');

        $this->assertContains('category_blocked', $report['mismatch_types']);
        $this->assertFalse($report['repair_candidate']);
        $this->assertStringContainsString('Kategori', $report['blocked_reason']);
    }

    public function test_variant_health_scanner_marks_safe_projection_repair_candidate(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-SAFE', 'Health Safe Supplier');
        $category = $this->standardCategory();
        $parent = $this->parentProduct($supplier, 'HSF-3300', '3300', 'Güvenli Repair Grup');
        $parent->forceFill(['standard_category_id' => $category->id])->save();
        $catalogParent = $this->catalogParent($supplier, $source, $parent, '3300', false);

        foreach (['HSF-3300-A', 'HSF-3300-B', 'HSF-3300-C'] as $index => $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());

            if ($index === 0) {
                $this->catalogVariant($catalogParent, $variant, $supplier, $source, '3300', $code, str($code)->afterLast('-')->toString());
            }
        }

        $report = app(VariantHealthScanner::class)
            ->scanSupplier($supplier, $this->tenant, 10)
            ->firstWhere('group_code', '3300');

        $this->assertContains('projection_missing', $report['mismatch_types']);
        $this->assertSame(2, $report['missing_in_projection_count']);
        $this->assertTrue($report['repair_candidate']);
    }

    public function test_projection_repair_command_is_dry_run_and_does_not_change_data(): void
    {
        [$supplier, $source] = $this->supplierWithAccess('HEALTH-COMMAND', 'Health Command Supplier');
        $category = $this->standardCategory();
        $parent = $this->parentProduct($supplier, 'HCM-2200', '2200', 'Command Grup');
        $parent->forceFill(['standard_category_id' => $category->id])->save();
        $catalogParent = $this->catalogParent($supplier, $source, $parent, '2200', false);

        foreach (['HCM-2200-A', 'HCM-2200-B'] as $index => $code) {
            $variant = $this->standardVariant($parent, $code, str($code)->afterLast('-')->toString());

            if ($index === 0) {
                $this->catalogVariant($catalogParent, $variant, $supplier, $source, '2200', $code, str($code)->afterLast('-')->toString());
            }
        }

        $before = TenantCatalogProductVariant::query()->count();

        $this->artisan('product-data-hub:repair-projections', [
            '--source' => $source->id,
            '--group' => '2200',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame($before, TenantCatalogProductVariant::query()->count());
    }

    public function test_common_and_standard_product_pages_open_with_filters_and_pagination(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?limit=50&q=ET')
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?q=ET&limit=50');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=50&q=ET')
            ->assertOk()
            ->assertSeeText('Standart Ürün Listesi')
            ->assertSee('value="50"', false);
    }

    private function supplierWithAccess(string $code, string $name): array
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code,
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_name' => $name . ' XML',
            'source_type' => 'xml',
            'profile_key' => $code,
            'status' => 'active',
            'is_active' => true,
            'lifecycle_state' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'supplier_id' => $supplier->id],
            [
                'is_active' => true,
                'can_view_products' => true,
                'visible_in_catalog' => true,
                'can_use_in_quotes' => true,
                'price_multiplier' => 1,
            ]
        );

        return [$supplier, $source];
    }

    private function parentProduct(Supplier $supplier, string $code, string $groupCode, string $name): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => $name,
            'name' => $name,
            'currency' => 'TL',
            'min_purchase_price' => 20,
            'total_stock_quantity' => 300,
            'variant_count' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_group_code' => $groupCode]],
        ]);
    }

    private function standardVariant(StandardProduct $parent, string $code, string $color): StandardProductVariant
    {
        return StandardProductVariant::query()->create([
            'standard_product_id' => $parent->id,
            'variant_code' => $code,
            'generated_variant_code' => $code,
            'variant_name' => $color,
            'variant_color' => $color,
            'stock_quantity' => 100,
            'min_purchase_price' => 20,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => ['supplier_group_code' => data_get($parent->source_summary, '0.supplier_group_code')],
        ]);
    }

    private function catalogParent(Supplier $supplier, SupplierSource $source, StandardProduct $parent, string $groupCode, bool $visibleInQuote): TenantCatalogProduct
    {
        return TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $parent->id,
            'tenant_sku' => $parent->standard_product_code,
            'name' => $parent->display_name,
            'product_code' => $parent->standard_product_code,
            'product_name' => $parent->display_name,
            'slug' => str($parent->standard_product_code)->lower()->slug()->toString(),
            'display_price' => 20,
            'currency' => 'TL',
            'total_stock_quantity' => 300,
            'supplier_stock_quantity' => 300,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_group_code' => $groupCode,
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => $visibleInQuote,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'supplier_group_code' => $groupCode,
            ],
        ]);
    }

    private function catalogVariant(TenantCatalogProduct $catalogParent, StandardProductVariant $variant, Supplier $supplier, SupplierSource $source, string $groupCode, string $code, string $color): TenantCatalogProductVariant
    {
        return TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $catalogParent->id,
            'standard_product_variant_id' => $variant->id,
            'variant_code' => $code,
            'variant_name' => $color,
            'variant_color' => $color,
            'display_price' => 20,
            'currency' => 'TL',
            'stock_quantity' => 100,
            'supplier_stock_quantity' => 100,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_group_code' => $groupCode,
                'variant_stock_code' => $code,
            ],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => true,
                'parent_product_code' => $catalogParent->product_code,
                'supplier_group_code' => $groupCode,
                'price_snapshot' => ['list_price' => 20, 'vat_rate' => 20],
            ],
        ]);
    }

    private function standardCategory(): StandardCategory
    {
        return StandardCategory::query()->firstOrCreate(
            ['code' => 'HEALTH-TEST-CATEGORY'],
            [
                'name' => 'Health Test Kategori',
                'slug' => 'health-test-kategori',
                'path' => 'Health Test Kategori',
                'product_family' => 'promotion',
                'is_active' => true,
                'visible_in_catalog' => true,
            ]
        );
    }
}
