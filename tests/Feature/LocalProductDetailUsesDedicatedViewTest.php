<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductDetailUsesDedicatedViewTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_local_product_detail_routes_use_dedicated_local_product_view(): void
    {
        $product = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'DETAIL-LOCAL-001',
            'product_name' => 'Local Detail Product',
            'source_summary' => [],
        ]);

        $dedicatedResponse = $this->getOnCentralHost('/admin/catalog/local-products/' . $product->id);
        $dedicatedResponse->assertOk();
        $dedicatedResponse->assertViewIs('admin.catalog.local-products.show');

        $genericResponse = $this->getOnCentralHost('/admin/catalog/' . $product->id);
        $genericResponse->assertOk();
        $genericResponse->assertViewIs('admin.catalog.local-products.show');
    }
}
