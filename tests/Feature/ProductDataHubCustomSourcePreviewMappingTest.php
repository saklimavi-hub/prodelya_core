<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\User;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubCustomSourcePreviewMappingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_custom_source_uses_real_xml_product_code_without_fake_et_prefix(): void
    {
        $source = $this->createCustomSource('elmasoylu-real.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>Kod-01</id>
        <name>Minik Gemici Takvim Tek Parça Spiralli</name>
        <category>Gemici Takvim</category>
        <price>41.00</price>
        <stock>500</stock>
        <images>
            <thumbnail>https://www.stokpromosyon.com/images/urunler/gemici_takvim/kod_01_k.jpg</thumbnail>
            <main>https://www.stokpromosyon.com/images/urunler/gemici_takvim/kod_01_b.jpg</main>
        </images>
        <url>https://www.stokpromosyon.com/gemici_takvim/kod_01_gemici_takvim.html</url>
    </urun>
</urunler>
XML);

        $parser = app(SourceParserService::class)->parse($source, file_get_contents($source->config['source_file_path']));
        $preview = app(PreviewParserService::class)->previewSource($source, $parser['rows']);

        $this->assertSame('CUSTOM', $preview['profile_key']);
        $this->assertSame('Kod-01', $preview['products'][0]['supplier_product_code']);
        $this->assertSame('EL-KOD-01', $preview['products'][0]['generated_product_code']);
        $this->assertSame('Minik Gemici Takvim Tek Parça Spiralli', $preview['products'][0]['product_name']);
        $this->assertSame('Gemici Takvim', $preview['products'][0]['supplier_category_name']);
        $this->assertSame('https://www.stokpromosyon.com/images/urunler/gemici_takvim/kod_01_b.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('https://www.stokpromosyon.com/gemici_takvim/kod_01_gemici_takvim.html', $preview['products'][0]['product_url']);
        $this->assertStringNotContainsString('ET-KOD', $preview['products'][0]['generated_product_code']);
    }

    public function test_custom_source_prefix_fallback_uses_el_when_product_code_mapping_is_missing(): void
    {
        $source = $this->createCustomSource('elmasoylu-fallback.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <name>Fallback Test Ürünü</name>
        <price>18.00</price>
        <stock>10</stock>
    </urun>
</urunler>
XML);

        $preview = app(PreviewParserService::class)->previewSource($source, [
            ['name' => 'Fallback Test Ürünü', 'price' => '18.00', 'stock' => '10'],
        ]);

        $this->assertSame('EL', $preview['profile_notes']['supplier_prefix']);
        $this->assertSame('EL', $preview['products'][0]['generated_product_code']);
        $this->assertTrue($preview['products'][0]['temporary_product_code']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'geçici kod üretildi')));
        $this->assertFalse(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Geçici prefix ET')));
    }

    public function test_custom_source_variant_image_fallback_and_missing_image_warning_are_reported(): void
    {
        $source = $this->createCustomSource('elmasoylu-images.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>Kod-02</id>
        <name>Gorsel Fallback Test</name>
        <price>22.00</price>
        <stock>8</stock>
        <images>
            <main>https://www.stokpromosyon.com/images/kod_02.jpg</main>
        </images>
    </urun>
</urunler>
XML);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'id' => 'Kod-02',
            'name' => 'Gorsel Fallback Test',
            'price' => '22.00',
            'stock' => '8',
            'images' => ['main' => 'https://www.stokpromosyon.com/images/kod_02.jpg'],
            'variant_color' => 'Gri',
        ]]);

        $this->assertSame('https://www.stokpromosyon.com/images/kod_02.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('https://www.stokpromosyon.com/images/kod_02.jpg', $preview['variants'][0]['variant_image_url']);
        $this->assertTrue($preview['variants'][0]['image_fallback_used']);
        $this->assertTrue(collect($preview['variants'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'ana ürün görseli kullanıldı')));

        $missingImagePreview = app(PreviewParserService::class)->previewSource($source, [[
            'id' => 'Kod-03',
            'name' => 'Gorsel Yok Test',
            'price' => '22.00',
            'stock' => '8',
        ]]);

        $this->assertNull($missingImagePreview['products'][0]['image_url']);
        $this->assertTrue(collect($missingImagePreview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'ana görsel de bulunamadı')));
    }

    public function test_relative_image_url_is_normalized_with_source_base_url(): void
    {
        $source = $this->createCustomSource('elmasoylu-relative.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>Kod-04</id>
        <name>Relative Görsel</name>
        <price>11.00</price>
        <stock>2</stock>
        <images>
            <main>/images/urunler/kod-04.jpg</main>
        </images>
        <url>/urun/kod-04.html</url>
    </urun>
</urunler>
XML);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'id' => 'Kod-04',
            'name' => 'Relative Görsel',
            'price' => '11.00',
            'stock' => '2',
            'images' => ['main' => '/images/urunler/kod-04.jpg'],
            'url' => '/urun/kod-04.html',
        ]]);

        $this->assertSame('https://stokpromosyon.com/images/urunler/kod-04.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('https://stokpromosyon.com/urun/kod-04.html', $preview['products'][0]['product_url']);
    }

    public function test_preview_disables_staging_when_required_fields_or_temporary_codes_exist(): void
    {
        $source = $this->createCustomSource('elmasoylu-stage-guard.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <name>Eksik Kodlu Ürün</name>
        <price>30.00</price>
    </urun>
</urunler>
XML);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.preview', $source));

        $response->assertOk();
        $response->assertSeeText('Staging’e Aktar şu an kilitli.');
        $response->assertSeeText('Zorunlu alanlar tamam değil');
        $response->assertSeeText('geçici kod üretildi');
        $response->assertSee('disabled', false);
    }

    private function createCustomSource(string $fileName, string $xml): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'ELMA-SOYLU',
            'code' => 'ELMASOYLU-' . uniqid(),
            'status' => 'active',
        ]);

        $fixturePath = storage_path('app/testing/' . $fileName);
        if (!is_dir(dirname($fixturePath))) {
            mkdir(dirname($fixturePath), 0777, true);
        }

        file_put_contents($fixturePath, $xml);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'ELMA-SOYLU XML',
            'url' => 'https://stokpromosyon.com/urunler.xml',
            'status' => 'active',
            'config' => [
                'profile_key' => 'CUSTOM',
                'format' => 'xml',
                'product_node_path' => 'urun',
                'source_file_path' => $fixturePath,
                'supplier_prefix' => 'EL',
                'generated_code_template' => '{PREFIX}-{SUPPLIER_PRODUCT_CODE}',
                'generated_variant_code_template' => '{PREFIX}-{VARIANT_STOCK_CODE}',
            ],
        ]);
    }
}
