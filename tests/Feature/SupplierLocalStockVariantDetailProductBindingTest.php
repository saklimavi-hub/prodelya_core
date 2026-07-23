<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockVariantDetailProductBindingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_variant_detail_route_requires_variant_to_belong_to_parent_product(): void
    {
        $supplier = $this->makeSupplierWithAccess('BINDING');
        $productA = $this->makeCatalogProduct([
            'product_code' => 'PROD-A',
            'product_name' => 'Product A',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $productB = $this->makeCatalogProduct([
            'product_code' => 'PROD-B',
            'product_name' => 'Product B',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $variantB = $this->makeCatalogVariant($productB, [
            'variant_code' => 'PROD-B-V1',
            'variant_name' => 'Product B Variant',
        ]);

        $response = $this->getOnCentralHost(route('admin.catalog.variants.show', ['product' => $productA->id, 'variant' => $variantB->id]));

        $response->assertNotFound();
    }
}
