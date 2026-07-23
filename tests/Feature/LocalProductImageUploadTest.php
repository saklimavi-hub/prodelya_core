<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\TenantCatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductImageUploadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_local_product_create_supports_image_upload_on_public_disk(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->post('/admin/catalog/local-products', [
                'product_name' => 'Upload Ürünü',
                'product_code' => 'UPLOAD-001',
                'standard_category_id' => $this->category->id,
                'display_price' => 42.5,
                'currency' => 'TL',
                'vat_rate' => 20,
                'local_stock_quantity' => 5,
                'visible_in_catalog' => '1',
                'visible_in_quote' => '1',
                'is_active' => '1',
                'local_stock_priority' => '1',
                'image_upload' => UploadedFile::fake()->image('upload.webp', 400, 400)->size(250),
            ]);

        $response->assertRedirect('/admin/catalog/local-products');

        $product = TenantCatalogProduct::query()->where('product_code', 'UPLOAD-001')->firstOrFail();
        $this->assertStringContainsString('/storage/tenants/' . $this->tenant->id . '/catalog/products/' . $product->id . '/', (string) $product->image_url);

        $relativePath = ltrim((string) parse_url((string) $product->image_url, PHP_URL_PATH), '/');
        $relativePath = str_starts_with($relativePath, 'storage/') ? substr($relativePath, 8) : $relativePath;
        Storage::disk('public')->assertExists($relativePath);
    }
}
