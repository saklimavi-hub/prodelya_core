<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductsMenuContext;
use Tests\TestCase;

class LocalProductsAuthorizedRouteSmokeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductsMenuContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalProductsMenuContext('local-products-authorized-smoke');
    }

    public function test_authorized_tenant_admin_can_open_all_local_product_routes(): void
    {
        $this->getOnCentralHost('/admin/catalog/local-products')
            ->assertOk()
            ->assertSee('Ürün Listem');

        $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock')
            ->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock')
            ->assertOk()
            ->assertSee('Katalog Ürünleri');

        $this->getOnCentralHost('/admin/catalog/local-products/create')
            ->assertOk()
            ->assertSee('Yeni Ürün Ekle');

        $this->getOnCentralHost('/admin/catalog/local-products/import')
            ->assertOk()
            ->assertSee('Dosyadan Ürün Aktar');
    }
}
