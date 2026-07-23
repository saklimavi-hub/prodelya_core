<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductsMenuContext;
use Tests\TestCase;

class LocalProductsMenuParentNavigationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductsMenuContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalProductsMenuContext('local-products-menu-parent-nav');
    }

    public function test_sidebar_renders_kendi_urunlerim_parent_title_as_canonical_link(): void
    {
        $response = $this->getOnCentralHost('/admin/catalog/local-products');

        $response->assertOk();
        $response->assertSee('href="' . route('admin.catalog.local-products') . '"', false);
        $response->assertSee('class="pd-sidebar-group-title"', false);
        $response->assertSee('Kendi Ürünlerim');
        $response->assertSee('Ürün Listem');
    }
}
