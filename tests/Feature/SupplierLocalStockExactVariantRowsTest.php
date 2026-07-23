<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockExactVariantRowsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_supplier_local_stock_exact_variant_rows_are_rendered_without_parent_aggregate(): void
    {
        $supplier = $this->makeSupplierWithAccess('ETKIN');
        $product = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'local_stock_quantity' => 2000,
        ]);

        $blue = $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-MV',
            'variant_name' => 'ET-0506-MV Plastik Kalem Mavi',
            'variant_color' => 'Mavi',
            'local_stock_quantity' => 1000,
        ]);
        $red = $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-K',
            'variant_name' => 'ET-0506-K Plastik Kalem Kırmızı',
            'variant_color' => 'Kırmızı',
            'local_stock_quantity' => 1000,
        ]);

        $this->makeOperationalLocalVariantStock($product, $blue, 1000);
        $this->makeOperationalLocalVariantStock($product, $red, 1000);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('ET-0506-MV');
        $catalogResponse->assertSeeText('ET-0506-K');
        $catalogResponse->assertDontSeeText('ET-0506 Plastik Kalem 2.000');
        $this->assertStringContainsString(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $blue->id]), $catalogResponse->getContent());
        $this->assertStringContainsString(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $red->id]), $catalogResponse->getContent());
    }
}
