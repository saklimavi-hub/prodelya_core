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

class TenantCatalogQuoteVisibilityLabelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private StandardCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
    }

    public function test_quote_visible_variant_uses_variant_label_and_is_searchable(): void
    {
        $supplier = $this->makeSupplierWithAccess('ETKIN-LABEL');
        $this->makeGroupedCatalogVariant($supplier, 'ET-0506', 'ET-0506-L', true);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?search=ET-0506-L');

        $response->assertOk();
        $response->assertSeeText('ET-0506-L');
        $row = $this->extractCatalogRow($response->getContent(), 'ET-0506-L');
        $this->assertStringContainsString('Satılabilir varyant', $row);
        $this->assertStringContainsString('Teklifte kullanılabilir', $row);
        $this->assertStringNotContainsString('Teklifte kapalı', $row);
        $this->assertStringNotContainsString('Teklifte Kullan', $row);
        $this->assertStringNotContainsString('Teklifte Kapat', $row);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=ET-0506-L')
            ->assertOk()
            ->assertJsonFragment([
                'product_code' => 'ET-0506-L',
                'visible_in_quote' => true,
            ]);
    }

    public function test_quote_closed_variant_stays_closed_in_catalog_and_search(): void
    {
        $supplier = $this->makeSupplierWithAccess('YN-LABEL');
        $this->makeGroupedCatalogVariant($supplier, 'YN-3013', 'YN-3013-L', false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?search=YN-3013-L');

        $response->assertOk();
        $response->assertSeeText('YN-3013-L');
        $row = $this->extractCatalogRow($response->getContent(), 'YN-3013-L');
        $this->assertStringContainsString('Teklifte kapalı', $row);
        $this->assertStringNotContainsString('Teklifte kullanılabilir', $row);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=YN-3013-L')
            ->assertOk()
            ->assertJsonMissing([
                'product_code' => 'YN-3013-L',
            ]);
    }

    public function test_category_waiting_and_zero_stock_do_not_close_quote_visibility_by_themselves(): void
    {
        $supplier = $this->makeSupplierWithAccess('WAIT-LABEL');
        $this->makeGroupedCatalogVariant($supplier, 'ET-0900', 'ET-0900-S', true, [
            'display_price' => 95,
            'stock_quantity' => 0,
            'supplier_stock_quantity' => 0,
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => true,
                'parent_product_code' => 'ET-0900',
                'warnings' => ['Kategori Bekliyor'],
                'category_missing_warning' => true,
                'price_snapshot' => ['list_price' => 95, 'vat_rate' => 20],
            ],
        ], [
            'catalog_status' => 'category_pending',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?search=ET-0900-S');

        $response->assertOk();
        $response->assertSeeText('ET-0900-S');
        $row = $this->extractCatalogRow($response->getContent(), 'ET-0900-S');
        $this->assertStringContainsString('Kategori Bekliyor', $row);
        $this->assertStringContainsString('Stok yok', $row);
        $this->assertStringContainsString('Teklifte kullanılabilir', $row);
        $this->assertStringNotContainsString('Teklifte kapalı', $row);
    }

    private function makeSupplierWithAccess(string $code): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => 'Label Supplier ' . $code,
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

    private function makeGroupedCatalogVariant(
        Supplier $supplier,
        string $parentCode,
        string $variantCode,
        bool $quoteVisible,
        array $variantOverrides = [],
        array $productOverrides = []
    ): void {
        $product = TenantCatalogProduct::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => $parentCode,
            'name' => $parentCode . ' Grup Ürün',
            'product_code' => $parentCode,
            'product_name' => $parentCode . ' Grup Ürün',
            'slug' => strtolower($parentCode),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/' . strtolower($parentCode) . '.jpg',
            'display_price' => 100,
            'sale_price' => 100,
            'currency' => 'TL',
            'total_stock_quantity' => 25,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 25,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => $parentCode,
                'supplier_group_code' => $parentCode,
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'hidden_reason' => 'Grup ürün olarak katalogda görünür, teklifte varyantları satılır.',
            'is_featured' => false,
            'local_stock_priority' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'quote_search_visible' => false,
                'supplier_group_code' => $parentCode,
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
            'is_active' => true,
            'stock_quantity' => 25,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ], $productOverrides));

        TenantCatalogProductVariant::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => null,
            'variant_code' => $variantCode,
            'variant_name' => $variantCode . ' Varyant',
            'variant_color' => 'Siyah',
            'variant_size' => null,
            'image_url' => 'https://example.test/' . strtolower($variantCode) . '.jpg',
            'display_price' => 100,
            'currency' => 'TL',
            'stock_quantity' => 25,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 25,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => $variantCode,
                'supplier_group_code' => $parentCode,
            ],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => $quoteVisible,
                'parent_product_code' => $parentCode,
                'supplier_group_code' => $parentCode,
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
            ],
        ], $variantOverrides));
    }

    private function extractCatalogRow(string $html, string $productCode): string
    {
        $pattern = '/<tr\b[^>]*>.*?' . preg_quote($productCode, '/') . '.*?<\/tr>/si';
        preg_match($pattern, $html, $matches);

        $this->assertNotEmpty($matches, 'Catalog row not found for ' . $productCode);

        return $matches[0];
    }
}
