<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsOwnProductListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_local_products_route_lists_only_own_products(): void
    {
        $supplier = $this->makeSupplierWithAccess();

        $own = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'OWN-LIST-001',
            'product_name' => 'Own Listed Product',
            'source_summary' => [],
        ]);

        $supplierLocal = $this->makeCatalogProduct([
            'product_code' => 'SUP-LIST-001',
            'product_name' => 'Supplier Local Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($supplierLocal, 9);

        $response = $this->getOnCentralHost('/admin/catalog/local-products');

        $response->assertOk();
        $response->assertSeeText('Ürün Listem');
        $response->assertSeeText($own->product_code);
        $response->assertDontSeeText($supplierLocal->product_code);
    }
}
