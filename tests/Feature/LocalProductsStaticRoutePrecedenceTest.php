<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductsMenuContext;
use Tests\TestCase;

class LocalProductsStaticRoutePrecedenceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductsMenuContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalProductsMenuContext('local-products-route-precedence');
    }

    public function test_static_local_product_routes_are_not_swallowed_by_dynamic_product_route(): void
    {
        $this->getOnCentralHost('/admin/catalog/local-products/create')
            ->assertOk()
            ->assertSee('Yeni Ürün Ekle');

        $this->getOnCentralHost('/admin/catalog/local-products/import')
            ->assertOk()
            ->assertSee('Dosyadan Ürün Aktar');

        $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock')
            ->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock')
            ->assertOk()
            ->assertSee('Katalog Ürünleri');
    }
}
