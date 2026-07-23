<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsTemplateContractTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_own_product_list_uses_new_template_contract(): void
    {
        $own = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'OWN-TEMPLATE-001',
            'product_name' => 'Own Template Product',
            'source_summary' => [],
        ]);

        $response = $this->getOnCentralHost('/admin/catalog/local-products');

        $response->assertOk();
        $response->assertSeeText('Ürün Listem');
        $response->assertSeeText('Yeni Ürün Ekle');
        $response->assertSeeText('Dosyadan Ürün Aktar');
        $response->assertSeeText('Katalog görünür');
        $response->assertSeeText($own->product_code);
        $response->assertDontSeeText('own_product');
        $response->assertDontSeeText('projection');
        $response->assertDontSeeText('shared field catalog');

        $html = $response->getContent();
        $this->assertStringContainsString('pd-local-product-hero', $html);
        $this->assertStringContainsString('pd-local-product-stat-strip', $html);
        $this->assertStringContainsString('pd-local-product-summary-card', $html);
    }
}
