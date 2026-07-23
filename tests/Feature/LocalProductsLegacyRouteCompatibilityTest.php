<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsLegacyRouteCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_legacy_local_products_routes_keep_working_with_separated_surfaces(): void
    {
        $own = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'LEGACY-OWN-001',
            'product_name' => 'Legacy Own Product',
            'source_summary' => [],
        ]);

        $this->getOnCentralHost('/admin/catalog/local-products')
            ->assertOk()
            ->assertSeeText('Ürün Listem');

        $this->getOnCentralHost('/admin/catalog/local-products/create')
            ->assertOk()
            ->assertSeeText('Yeni Ürün Ekle');

        $this->getOnCentralHost('/admin/catalog/local-products/import')
            ->assertOk()
            ->assertSeeText('Dosyadan Ürün Aktar');

        $this->getOnCentralHost('/admin/catalog/local-products?edit=' . $own->id)
            ->assertOk()
            ->assertSeeText('Kendi Ürünümü Düzenle');
    }
}
