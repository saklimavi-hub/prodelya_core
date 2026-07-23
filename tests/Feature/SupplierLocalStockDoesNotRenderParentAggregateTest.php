<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockDoesNotRenderParentAggregateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_varianted_product_with_only_legacy_product_level_stock_is_excluded_from_normal_list(): void
    {
        $supplier = $this->makeSupplierWithAccess('LEGACY');
        $product = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-MV',
            'variant_name' => 'ET-0506-MV Plastik Kalem Mavi',
            'variant_color' => 'Mavi',
            'local_stock_quantity' => 1000,
        ]);
        $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-K',
            'variant_name' => 'ET-0506-K Plastik Kalem Kırmızı',
            'variant_color' => 'Kırmızı',
            'local_stock_quantity' => 1000,
        ]);

        $this->makeLegacyUnassignedOperationalStock($product, 2000);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertDontSeeText('ET-0506-MV');
        $catalogResponse->assertDontSeeText('ET-0506-K');
        $catalogResponse->assertDontSeeText('ET-0506 Plastik Kalem');
        $catalogResponse->assertSeeText('Varyantı belirlenmemiş stok kaydı bulunuyor.');
        $catalogResponse->assertSeeText('1 kayıt / 2.000 adet');
    }
}
