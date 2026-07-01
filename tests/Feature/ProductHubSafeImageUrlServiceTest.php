<?php

namespace Tests\Feature;

use App\Services\ProductDataHub\ProductHubSafeImageUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubSafeImageUrlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_super_admin_preview_context_can_return_safe_supplier_image_url(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);

        $resolved = $service->resolveFromSnapshot([
            'image_url' => 'https://supplier-images.example.invalid/products/test-image.jpg',
        ], 'super_admin_preview');

        $this->assertSame('https://supplier-images.example.invalid/products/test-image.jpg', $resolved);
    }

    public function test_customer_facing_contexts_block_supplier_external_image_urls(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);
        $snapshot = ['image_url' => 'https://supplier-images.example.invalid/products/customer-facing.jpg'];

        $this->assertNull($service->resolveFromSnapshot($snapshot, 'public_tracking'));
        $this->assertNull($service->resolveFromSnapshot($snapshot, 'work_form_pdf'));
        $this->assertNull($service->resolveFromSnapshot($snapshot, 'email'));
        $this->assertNull($service->resolveFromSnapshot($snapshot, 'whatsapp'));
        $this->assertNull($service->resolveFromSnapshot($snapshot, 'customer_portal'));
    }

    public function test_tenant_catalog_context_can_temporarily_allow_external_supplier_image(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);

        $resolved = $service->resolveFromSnapshot([
            'image_url' => 'https://supplier-images.example.invalid/products/tenant-catalog.jpg',
        ], 'tenant_catalog');

        $this->assertSame('https://supplier-images.example.invalid/products/tenant-catalog.jpg', $resolved);
    }

    public function test_customer_facing_context_accepts_prodelya_controlled_url(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);
        $url = url('/storage/products/catalog-safe-image.jpg');

        $resolved = $service->resolveFromSnapshot([
            'image_url' => $url,
        ], 'public_tracking');

        $this->assertSame($url, $resolved);
    }

    public function test_product_or_detail_urls_are_not_used_as_image_candidates(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);

        $resolved = $service->resolveFromSnapshot([
            'product_url' => 'https://supplier.example.invalid/product-page',
            'detail_url' => 'https://supplier.example.invalid/product-detail',
        ], 'public_tracking');

        $this->assertNull($resolved);
    }

    public function test_admin_context_rejects_external_urls_blocked_by_safe_source_policy(): void
    {
        $service = app(ProductHubSafeImageUrlService::class);

        $resolved = $service->resolveFromSnapshot([
            'image_url' => 'http://127.0.0.1/private-image.jpg',
        ], 'super_admin_preview');

        $this->assertNull($resolved);
    }
}
