<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockExactQuantityTotalsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_exact_variant_rows_drive_hero_totals(): void
    {
        $supplier = $this->makeSupplierWithAccess('TOTALS');
        $product = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $blue = $this->makeCatalogVariant($product, ['variant_code' => 'ET-0506-MV', 'variant_name' => 'ET-0506-MV Plastik Kalem Mavi']);
        $red = $this->makeCatalogVariant($product, ['variant_code' => 'ET-0506-K', 'variant_name' => 'ET-0506-K Plastik Kalem Kırmızı']);

        $blueStock = $this->makeOperationalLocalVariantStock($product, $blue, 1000);
        $redStock = $this->makeOperationalLocalVariantStock($product, $red, 1000);
        $blueStock->update(['quantity_reserved' => 100, 'quantity_available' => 900]);
        $redStock->update(['quantity_reserved' => 50, 'quantity_available' => 950]);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('Katalog Ürünleri');
        $catalogResponse->assertSeeText('ET-0506-MV');
        $catalogResponse->assertSeeText('ET-0506-K');
        $catalogResponse->assertDontSeeText('Varyantı belirlenmemiş stok kaydı bulunuyor.');
    }
}
