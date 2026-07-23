<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockTemplateContractTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_supplier_local_stock_list_uses_new_template_contract(): void
    {
        $supplier = $this->makeSupplierWithAccess();
        $product = $this->makeCatalogProduct([
            'product_code' => 'SUP-TEMPLATE-001',
            'product_name' => 'Supplier Template Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($product, 22);

        $response = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');
        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('Katalog Ürünleri');
        $catalogResponse->assertSeeText('Filtreler ve Hızlı Geçişler');
        $catalogResponse->assertSeeText('Ürün Listesi');
        $catalogResponse->assertSeeText($product->product_code);
        $catalogResponse->assertSeeText($supplier->name);
        $catalogResponse->assertDontSeeText('Operational');
        $catalogResponse->assertDontSeeText('projection');

        $html = $catalogResponse->getContent();
        $this->assertStringContainsString('pd-hub-table-wrap', $html);
        $this->assertStringContainsString('pd-side-summary', $html);
    }
}
