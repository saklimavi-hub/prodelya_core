<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506SupplierLocalListAfterCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_supplier_local_list_shows_exact_et0506_rows_after_correction(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();

        $extraSupplier = $this->makeSupplierWithAccess('AK850');
        $extraProduct = $this->makeCatalogProduct([
            'product_code' => 'AK-850',
            'product_name' => 'AK-850 Test Urun',
            'source_summary' => [['supplier_id' => $extraSupplier->id, 'supplier_name' => $extraSupplier->name]],
        ]);
        $this->makeCatalogVariant($extraProduct, ['variant_code' => 'AK-850-A', 'variant_name' => 'AK-850-A Test']);
        $this->makeCatalogVariant($extraProduct, ['variant_code' => 'AK-850-B', 'variant_name' => 'AK-850-B Test']);
        $this->makeLegacyUnassignedOperationalStock($extraProduct, 28);

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--apply' => true]
        ))->assertSuccessful();

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('ET-0506-MV Plastik Kalem Mavi');
        $catalogResponse->assertSeeText('ET-0506-K Plastik Kalem Kırmızı');
        $catalogResponse->assertDontSeeText('1 kayıt / 2.000 adet');
        $catalogResponse->assertSeeText('1 kayıt / 28 adet');
    }
}
