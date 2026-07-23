<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockFlatProductRowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_flat_supplier_product_can_still_render_as_product_level_row(): void
    {
        $supplier = $this->makeSupplierWithAccess('FLAT');
        $product = $this->makeCatalogProduct([
            'product_code' => 'FLAT-001',
            'product_name' => 'Flat Supplier Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $this->makeOperationalLocalStock($product, 12);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('FLAT-001');
        $catalogResponse->assertSeeText('Flat Supplier Product');
        $this->assertStringContainsString(route('admin.catalog.show', $product), $catalogResponse->getContent());
    }
}
