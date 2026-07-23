<?php

namespace Tests\Feature;

use App\Models\TenantCatalogProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductDetailGalleryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_gallery_normalizes_localhost_storage_image_to_current_host_and_hides_broken_contract_markup(): void
    {
        $product = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-GALLERY-001',
            'product_name' => 'Local Gallery Ürün',
            'image_url' => 'http://localhost/storage/tenants/2/catalog/products/999/test-image.png',
            'tenant_attributes' => [
                'catalog_images' => [
                    'http://localhost/storage/tenants/2/catalog/products/999/test-image.png',
                    'http://localhost/storage/tenants/2/catalog/products/999/test-image.png',
                    'http://localhost/storage/tenants/2/catalog/products/999/test-image-side.png',
                ],
            ],
        ]);

        TenantCatalogProductImage::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'image_url' => 'http://localhost/storage/tenants/2/catalog/products/999/test-image.png',
            'image_type' => 'tenant_local_product',
            'sort_order' => 0,
            'is_primary' => true,
            'fallback_used' => false,
            'visible_in_catalog' => true,
            'meta' => [],
        ]);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/' . $product->id);

        $response->assertOk();
        $response->assertDontSee('http://localhost/storage/tenants/2/catalog/products/999/test-image.png', false);
        $response->assertSee('/storage/tenants/2/catalog/products/999/test-image.png', false);
        $response->assertSee('data-local-product-gallery', false);
        $response->assertSee('data-catalog-detail-main-image', false);
        $response->assertSee('data-catalog-detail-image-fallback', false);
        $response->assertSee('pd-local-product-gallery-main', false);
        $response->assertSee('pd-local-product-gallery-thumb', false);
        $response->assertSee('data-gallery-thumb-fallback', false);
        $response->assertDontSeeText('C:\\');
    }

    public function test_gallery_deduplicates_images_and_keeps_clean_placeholder_contract(): void
    {
        $product = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-GALLERY-002',
            'product_name' => 'Dedupe Ürün',
            'image_url' => 'https://example.test/gallery-main.webp',
            'tenant_attributes' => [
                'catalog_images' => [
                    'https://example.test/gallery-main.webp',
                    'https://example.test/gallery-side.webp',
                    'https://example.test/gallery-side.webp',
                ],
            ],
        ]);

        TenantCatalogProductImage::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'image_url' => 'https://example.test/gallery-main.webp',
            'image_type' => 'tenant_local_product',
            'sort_order' => 0,
            'is_primary' => true,
            'fallback_used' => false,
            'visible_in_catalog' => true,
            'meta' => [],
        ]);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/' . $product->id);
        $html = $response->getContent();

        $response->assertOk();
        $this->assertSame(2, substr_count($html, 'data-catalog-detail-thumb'));
        $this->assertSame(2, substr_count($html, 'data-gallery-thumb-fallback'));
        $this->assertStringContainsString('data-catalog-detail-main-image', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringContainsString('pd-local-product-gallery-fallback', $html);
        $this->assertStringContainsString('pd-local-product-gallery-thumb-fallback', $html);
        $this->assertStringContainsString('Görsel yüklenemedi.', $html);
        $this->assertStringNotContainsString('file_path', $html);
    }
}
