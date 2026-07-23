<?php

namespace Tests\Feature;

use App\Models\TenantCatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductSvgUploadRejectedTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_illustrator_svg_upload_is_rejected_and_not_persisted(): void
    {
        Storage::fake('public');

        $svg = UploadedFile::fake()->createWithContent(
            'illustrator-export.svg',
            <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">
  <script>alert('x')</script>
  <rect width="100" height="100" fill="#f97316"/>
</svg>
SVG
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->from('/admin/catalog/local-products/create')
            ->post('/admin/catalog/local-products', [
                'product_name' => 'SVG Red Ürün',
                'product_code' => 'SVG-REJECT-001',
                'standard_category_id' => $this->category->id,
                'display_price' => 42.5,
                'currency' => 'TL',
                'vat_rate' => 20,
                'local_stock_quantity' => 1,
                'visible_in_catalog' => '1',
                'visible_in_quote' => '1',
                'is_active' => '1',
                'local_stock_priority' => '1',
                'image_upload' => $svg,
            ]);

        $response->assertRedirect('/admin/catalog/local-products/create');
        $response->assertSessionHasErrors([
            'image_upload' => 'SVG dosyaları güvenlik nedeniyle doğrudan yüklenemez. Lütfen PNG, JPG veya WEBP kullanın.',
        ]);

        $this->assertDatabaseMissing('tenant_catalog_products', [
            'product_code' => 'SVG-REJECT-001',
        ]);
        $this->assertNull(TenantCatalogProduct::query()->where('product_code', 'SVG-REJECT-001')->value('image_url'));
        Storage::disk('public')->assertDirectoryEmpty('tenants/' . $this->tenant->id . '/catalog/products');
    }
}
