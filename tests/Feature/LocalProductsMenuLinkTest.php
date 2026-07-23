<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductsMenuContext;
use Tests\TestCase;

class LocalProductsMenuLinkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductsMenuContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalProductsMenuContext('local-products-menu-link');
    }

    public function test_kendi_urunlerim_parent_menu_item_resolves_canonical_local_products_href(): void
    {
        $item = $this->findMenuItemByKey($this->tenantMenu(), 'catalog-local-products');

        $this->assertNotNull($item);
        $this->assertSame('admin.catalog.local-products', $item['route'] ?? null);
        $this->assertSame(route('admin.catalog.local-products'), $item['href'] ?? null);
        $this->assertNotSame('#', $item['href'] ?? null);
    }
}
