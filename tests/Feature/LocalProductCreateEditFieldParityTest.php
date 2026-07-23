<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductCreateEditFieldParityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_create_and_edit_surface_share_field_catalog_and_upload_contract(): void
    {
        $product = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'FORM-TEMPLATE-001',
            'product_name' => 'Form Template Product',
            'product_url' => 'https://example.test/form-template-product',
            'source_summary' => [],
        ]);

        $createResponse = $this->getOnCentralHost('/admin/catalog/local-products/create');
        $editResponse = $this->getOnCentralHost('/admin/catalog/local-products?edit=' . $product->id);

        foreach ([$createResponse, $editResponse] as $response) {
            $response->assertOk();
            $response->assertSeeText('Ürün Adı');
            $response->assertSeeText('Ürün Kodu / SKU');
            $response->assertSeeText('Ürün URL');
            $response->assertSeeText('Ürün Ölçü');
            $response->assertSeeText('Ürün Ebat');
            $response->assertSeeText('Ürün Görseli / Galeri');
            $response->assertSeeText('Bilgisayardan Görsel Seç');
            $response->assertSeeText('Ürün Stok');
            $response->assertSeeText('Katalogda Görünsün');
            $response->assertDontSeeText('Ürün Tedarikçi');
            $response->assertDontSeeText('Ürün ID');
            $response->assertDontSeeText('Ürün Detay URL');
        }

        $this->assertStringContainsString('form action="' . route('admin.catalog.local-products.store') . '" method="POST" enctype="multipart/form-data" class="pd-local-product-form"', $createResponse->getContent());
    }
}
