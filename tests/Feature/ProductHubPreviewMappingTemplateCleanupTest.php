<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubPreviewMappingTemplateCleanupTest extends TestCase
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

    public function test_preview_screen_clearly_distinguishes_live_source_and_demo_fallback(): void
    {
        $liveSource = $this->createCustomXmlSource('template-live.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_kodu>TM-100</urun_kodu>
        <urun_adi>Canli Kaynak Kalem</urun_adi>
        <alis_fiyati>12.50</alis_fiyati>
        <kategori_adi>Kalemler</kategori_adi>
        <stok>40</stok>
    </urun>
</urunler>
XML);

        $demoSource = SupplierSource::query()->create([
            'supplier_id' => $liveSource->supplier_id,
            'source_type' => 'xml',
            'source_name' => 'Demo Preview Source',
            'status' => 'active',
            'config' => [
                'profile_key' => 'CUSTOM',
                'format' => 'xml',
                'product_node_path' => 'urun',
            ],
        ]);

        $liveResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.preview', $liveSource));

        $demoResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.preview', $demoSource));

        $liveResponse->assertOk();
        $liveResponse->assertSeeText('Canlı kaynak okundu');
        $liveResponse->assertSeeText('Önizleme durumu');
        $liveResponse->assertSeeText('Canlı Kaynak');

        $demoResponse->assertOk();
        $demoResponse->assertSeeText('Demo veri gösteriliyor');
        $demoResponse->assertSeeText('Canlı kaynak okunamadı, örnek veya demo veri gösteriliyor.');
        $demoResponse->assertDontSeeText('Canlı kaynak okundu');
    }

    public function test_preview_screen_groups_required_gaps_and_advanced_actions(): void
    {
        $source = $this->createCustomXmlSource('template-missing.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_adi>Eksik Kodlu Urun</urun_adi>
        <kategori_adi>Defterler</kategori_adi>
    </urun>
</urunler>
XML);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.preview', $source));

        $response->assertOk();
        $response->assertSeeText('Zorunlu Eksikler ve Uyarılar');
        $response->assertSeeText('Zorunlu eşleme eksikleri var.');
        $response->assertSeeText('Gelişmiş İşlemler');
        $response->assertSeeText('Staging’e Aktar');
        $response->assertSeeText('Standart Ürün Havuzuna Al');
        $response->assertSeeText('Teknik Test');
    }

    public function test_mapping_screen_shows_compact_columns_and_filters(): void
    {
        $source = $this->createCustomXmlSource('template-mapping.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_kodu>TM-200</urun_kodu>
        <urun_adi>Alan Esleme Testi</urun_adi>
        <kategori_adi>Defterler</kategori_adi>
        <resim>https://example.test/defter.jpg</resim>
        <alis_fiyati>18.00</alis_fiyati>
    </urun>
</urunler>
XML);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', $source));

        $response->assertOk();
        $response->assertSeeText('Abone Firma bu verileri değiştiremez.');
        $response->assertSeeText('Kaynak Alan');
        $response->assertSeeText('Örnek Değer');
        $response->assertSeeText('Önerilen Standart Alan');
        $response->assertSeeText('Seçilen Standart Alan');
        $response->assertSeeText('Durum');
        $response->assertSeeText('Zorunlu Eksikler');
        $response->assertSeeText('Eşlenmiş');
        $response->assertSeeText('Eşlenmemiş');
        $response->assertSeeText('Önerilenler');
        $response->assertSeeText('Fiyat / Stok');
        $response->assertSeeText('Görsel');
        $response->assertSeeText('Kategori');
        $response->assertSeeText('Varyant');
    }

    public function test_source_create_and_edit_pages_use_clear_section_headings(): void
    {
        $source = $this->createCustomXmlSource('template-edit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_kodu>TM-300</urun_kodu>
        <urun_adi>Duzenleme Testi</urun_adi>
        <alis_fiyati>11.00</alis_fiyati>
    </urun>
</urunler>
XML);

        $createResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.create'));

        $editResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.edit', $source));

        $createResponse->assertOk();
        $createResponse->assertSeeText('Kaynak Kimliği');
        $createResponse->assertSeeText('Profil ve Parsing');
        $createResponse->assertSeeText('Bağlantı ve Güvenlik');
        $createResponse->assertSeeText('Önizleme ve Alan Eşleme Hazırlığı');
        $createResponse->assertSeeText('Sync Davranışı');

        $editResponse->assertOk();
        $editResponse->assertSeeText('Kaynak Kimliği');
        $editResponse->assertSeeText('Profil ve Parsing');
        $editResponse->assertSeeText('Bağlantı ve Güvenlik');
        $editResponse->assertSeeText('Gelişmiş Ayarlar');
        $editResponse->assertSeeText('Sync Davranışı');
        $editResponse->assertSeeText('Abone Firma bu URL’yi değiştiremez.');
    }

    public function test_product_hub_preview_and_mapping_css_stays_on_standard_radii(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression('/\.pd-form-shell \.pd-input,[^}]*border-radius:\s*var\(--pd-radius-control\);/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-form-shell \.pd-badge,[^}]*border-radius:\s*var\(--pd-radius-pill\);/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-hub-preview-shell \.pd-badge,[^}]*border-radius:\s*var\(--pd-radius-pill\);/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-form-shell \.pd-badge,[^}]*999px;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-hub-preview-shell \.pd-badge,[^}]*999px;/s', $css);
    }

    private function createCustomXmlSource(string $fileName, string $xml): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Template Cleanup Supplier',
            'code' => 'TPL-' . uniqid(),
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
            'source_name' => 'Template Cleanup XML',
            'url' => null,
            'status' => 'active',
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_profile_template' => 'CUSTOM',
                'format' => 'xml',
                'product_node_path' => 'urun',
                'source_file_path' => $fixturePath,
                'supplier_prefix' => 'TPL',
            ],
        ]);
    }
}
