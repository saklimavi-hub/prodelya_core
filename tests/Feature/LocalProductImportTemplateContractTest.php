<?php

namespace Tests\Feature;

use App\Services\TenantCatalog\LocalProductFieldCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductImportTemplateContractTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_import_surface_is_csv_only_and_uses_shared_field_catalog_labels(): void
    {
        $response = $this->getOnCentralHost('/admin/catalog/local-products/import');

        $response->assertOk();
        $response->assertSeeText('Dosyadan Ürün Aktar');
        $response->assertSeeText('CSV');
        $response->assertDontSeeText('XLSX');
        $response->assertDontSeeText('XML');
        $response->assertSeeText('Ürün Görseli / Galeri');
        $response->assertSeeText('Ürün Kodu / SKU');
        $response->assertSeeText('Ürün URL');
        $response->assertSeeText('Ürün Ölçü');
        $response->assertSeeText('Ürün Ebat');
        $response->assertDontSeeText('Ürün Tedarikçi');
        $response->assertDontSeeText('Ürün Detay URL');
        $response->assertDontSeeText('Ürün ID');
        $response->assertDontSeeText('own_product');
        $response->assertDontSeeText('shared field catalog');

        $html = $response->getContent();
        $this->assertStringContainsString('pd-local-product-stepper', $html);
        $this->assertStringContainsString('pd-local-product-dropzone', $html);
        $this->assertStringContainsString('Alan Karşılıkları', $html);
    }

    public function test_csv_template_route_uses_shared_field_catalog_headers(): void
    {
        $response = $this->getOnCentralHost('/admin/catalog/local-products/import/template');

        $response->assertOk();

        $firstLine = explode("\n", trim($response->getContent()))[0];
        $expected = implode(',', app(LocalProductFieldCatalogService::class)->csvTemplateHeaders());

        $this->assertSame($expected, $firstLine);
    }
}
