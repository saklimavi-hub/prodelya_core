<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockVariantDetailRouteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_variant_row_opens_exact_variant_detail_route(): void
    {
        $supplier = $this->makeSupplierWithAccess('DETAIL');
        $product = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $variant = $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-MV',
            'variant_name' => 'ET-0506-MV Plastik Kalem Mavi',
            'variant_color' => 'Mavi',
            'local_stock_quantity' => 1000,
        ]);
        $this->makeOperationalLocalVariantStock($product, $variant, 1000);

        $response = $this->getOnCentralHost(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $variant->id]));

        $response->assertOk();
        $response->assertSeeText('ET-0506-MV Plastik Kalem Mavi');
        $response->assertSeeText('Varyant Detayı');
        $response->assertSeeText('Ürün ailesi: ET-0506 Plastik Kalem');
        $response->assertSeeText('1.000');
    }
}
