<?php

namespace Tests\Feature;

use App\Models\TenantCatalogProductImage;
use App\Models\TenantCatalogProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class TenantCatalogProductDetailTemplateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_product_detail_uses_compact_gallery_template_without_sensitive_leaks(): void
    {
        $product = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-DETAIL-001',
            'product_name' => 'Detay Local Ürün',
            'product_url' => 'https://example.test/detay-local-urun',
            'image_url' => 'https://example.test/product-main.webp',
            'description' => "İlk satır<br>İkinci satır",
            'tenant_attributes' => [
                'catalog_images' => [
                    'https://example.test/product-main.webp',
                    'https://example.test/product-side.webp',
                ],
            ],
        ]);
        $this->makeOperationalLocalStock($product, 14);

        TenantCatalogProductImage::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'image_url' => 'https://example.test/product-main.webp',
            'image_type' => 'tenant_local_product',
            'sort_order' => 0,
            'is_primary' => true,
            'fallback_used' => false,
            'visible_in_catalog' => true,
            'meta' => [],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => 'LOCAL-DETAIL-001-BLUE',
            'variant_name' => 'Detay Local Ürün Mavi',
            'variant_color' => 'Mavi',
            'image_url' => 'https://example.test/product-variant.webp',
            'display_price' => 125.5,
            'currency' => 'TRY',
            'stock_quantity' => 8,
            'local_stock_quantity' => 8,
            'supplier_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'variant_attributes' => [
                    'color' => 'Mavi',
                    'measure' => '11 cm',
                    'dimensions' => '145 x 12 mm',
                ],
            ],
        ]);

        TenantCatalogProductImage::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'image_url' => 'https://example.test/product-variant.webp',
            'image_type' => 'variant',
            'sort_order' => 1,
            'is_primary' => true,
            'fallback_used' => false,
            'visible_in_catalog' => true,
            'meta' => [],
        ]);

        $response = $this->getOnCentralHost('/admin/catalog/' . $product->id);

        $response->assertOk();
        $response->assertSeeText('Ürün Detayı');
        $response->assertSeeText('Detay Local Ürün');
        $response->assertSeeText('Kendi Ürünüm');
        $response->assertSeeText('Sticky Özet');
        $response->assertSeeText('Ürün ID');
        $response->assertSeeText('Ürün URL');
        $response->assertSeeText('Ürün Detay URL');
        $response->assertSeeText('Ürün Ölçü');
        $response->assertSeeText('Ürün Ebat');
        $response->assertSeeText('İlk satır');
        $response->assertSeeText('İkinci satır');
        $response->assertDontSeeText('<br>');
        $response->assertDontSeeText('file_path');
        $response->assertDontSeeText('group_code');
        $response->assertDontSeeText('C:\\');
        $response->assertDontSeeText('operational ledger');
        $response->assertDontSeeText('legacy scalar');
        $response->assertDontSeeText('Ürün Tedarikçi');

        $html = $response->getContent();
        $this->assertSame(3, substr_count($html, 'data-catalog-detail-thumb'));
        $this->assertSame(1, substr_count($html, 'data-catalog-detail-main-image'));
        $this->assertStringContainsString('pd-catalog-detail-main-image pd-allow-large', $html);
        $this->assertStringContainsString('data-catalog-detail-image-fallback', $html);
        $this->assertStringContainsString('pd-catalog-detail-thumb-grid', $html);
        $this->assertStringContainsString('pd-local-product-detail-fields', $html);
    }
}
