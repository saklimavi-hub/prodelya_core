<?php

namespace Tests\Feature;

use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSimpleModeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_supplier_product_raw_standard_product_relation_is_belongs_to(): void
    {
        $this->assertInstanceOf(BelongsTo::class, (new SupplierProductRaw())->standardProduct());
    }

    public function test_supplier_products_page_opens_with_selected_product_id(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Regression Supplier',
            'code' => 'REG-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Regression Source',
            'config' => ['format' => 'xml'],
            'status' => 'active',
        ]);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'sku' => 'REG-100',
            'standard_product_code' => 'REG-100',
            'name' => 'Regression Product',
            'product_name' => 'Regression Product',
            'is_active' => true,
        ]);

        $raw = SupplierProductRaw::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_product_id' => $standardProduct->id,
            'source_product_id' => 'raw-reg-100',
            'source_name' => 'Regression Source Product',
            'supplier_product_code' => 'REG-100',
            'product_name' => 'Regression Raw Product',
            'sync_status' => 'processed',
            'normalized_payload' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/supplier-products?selected_product_id=' . $raw->id)
            ->assertOk()
            ->assertSeeText('Regression Raw Product');
    }

    public function test_category_mappings_default_to_simple_mode_and_hide_review_exports(): void
    {
        $source = $this->makeSupplierSource('Yeni Nesil');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Simple Mode Kalemler',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'product_count' => 2,
            'sample_product_names' => ['SM-100 Kalem', 'SM-101 Kalem'],
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings')
            ->assertOk()
            ->assertSeeText('Basit Mod')
            ->assertSeeText('Hedef Kategori Arama')
            ->assertSeeText('Eşle ve Kaydet')
            ->assertDontSeeText('Manuel Review Listesi')
            ->assertDontSeeText('CSV Export')
            ->assertDontSeeText('JSON Export');
    }

    public function test_category_mappings_advanced_mode_keeps_review_exports_visible(): void
    {
        $source = $this->makeSupplierSource('Yeni Nesil');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Advanced Mode Setler',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'product_count' => 2,
            'sample_product_names' => ['ADV-100 Set'],
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced')
            ->assertOk()
            ->assertSeeText('Manuel Review Listesi')
            ->assertSeeText('CSV Export')
            ->assertSeeText('JSON Export');
    }

    public function test_product_panel_category_link_uses_same_page_drawer_query(): void
    {
        $contents = file_get_contents(resource_path('views/super-admin/product-data-hub/product-panel.blade.php'));

        $this->assertStringContainsString("'category_mapping_product_id' => \$row['standard_product_id']", $contents);
        $this->assertStringContainsString("product-panel/category-mappings/{mapping}", file_get_contents(base_path('routes/web.php')));
    }

    private function makeSupplierSource(string $supplierName): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $supplierName,
            'code' => strtoupper(str_replace(' ', '-', $supplierName)) . '-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $supplierName . ' XML',
            'config' => ['format' => 'xml'],
            'status' => 'active',
        ]);
    }
}
