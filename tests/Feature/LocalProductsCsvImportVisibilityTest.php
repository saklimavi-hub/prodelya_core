<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsCsvImportVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_imported_local_products_appear_in_own_list_not_supplier_local_list(): void
    {
        $csv = "urun_kodu,urun_adi,stok,liste_fiyati,para_birimi,kdv_var,katalogda_gorunsun,teklifte_kullanilsin\nIMP-SOURCE-001,Import Source Product,12,44.5,TL,1,1,1\n";
        $file = UploadedFile::fake()->createWithContent('local-products.csv', $csv);

        $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file])
            ->assertRedirect('/admin/catalog/local-products/import');

        $this->postOnCentralHost('/admin/catalog/local-products/import', ['duplicate_policy' => 'update'])
            ->assertRedirect('/admin/catalog/local-products');

        $this->getOnCentralHost('/admin/catalog/local-products')
            ->assertOk()
            ->assertSeeText('IMP-SOURCE-001');

        $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock')
            ->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock')
            ->assertOk()
            ->assertDontSeeText('IMP-SOURCE-001');
    }
}
