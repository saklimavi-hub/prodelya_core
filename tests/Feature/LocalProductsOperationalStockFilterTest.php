<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsOperationalStockFilterTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_projection_stock_alone_does_not_classify_as_supplier_local_operational_stock(): void
    {
        $supplier = $this->makeSupplierWithAccess();

        $projectionOnly = $this->makeCatalogProduct([
            'product_code' => 'PROJECTION-ONLY-001',
            'product_name' => 'Projection Only Product',
            'local_stock_quantity' => 1000,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $zeroOperational = $this->makeCatalogProduct([
            'product_code' => 'ZERO-OPS-001',
            'product_name' => 'Zero Operational Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($zeroOperational, 0);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');

        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');
        $catalogResponse->assertOk();
        $catalogResponse->assertDontSeeText($projectionOnly->product_code);
        $catalogResponse->assertDontSeeText($zeroOperational->product_code);
    }
}
