<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsNoDuplicateSourceClassificationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_supplier_local_product_does_not_duplicate_across_lists(): void
    {
        $supplier = $this->makeSupplierWithAccess();

        $product = $this->makeCatalogProduct([
            'product_code' => 'NO-DUP-001',
            'product_name' => 'No Duplicate Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($product, 8);

        $ownResponse = $this->getOnCentralHost('/admin/catalog/local-products');
        $supplierResponse = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');

        $ownResponse->assertOk()->assertDontSeeText('NO-DUP-001');
        $supplierResponse->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');
        $catalogResponse->assertOk()
            ->assertSeeText('NO-DUP-001')
            ->assertDontSeeText('Kendi Ürünüm');
    }
}
