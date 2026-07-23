<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsSupplierStockListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_supplier_stock_route_lists_only_operational_supplier_local_products(): void
    {
        $supplier = $this->makeSupplierWithAccess();

        $own = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'OWN-SUP-001',
            'product_name' => 'Own Product',
            'source_summary' => [],
        ]);

        $projectionOnly = $this->makeCatalogProduct([
            'product_code' => 'SUP-PROJ-001',
            'product_name' => 'Projection Only Product',
            'local_stock_quantity' => 1000,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $operational = $this->makeCatalogProduct([
            'product_code' => 'SUP-OPS-001',
            'product_name' => 'Operational Supplier Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($operational, 12);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');

        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');
        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText($operational->product_code);
        $catalogResponse->assertDontSeeText($projectionOnly->product_code);
        $catalogResponse->assertDontSeeText($own->product_code);
    }
}
