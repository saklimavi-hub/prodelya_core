<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubPreviewQualityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_preview_supports_all_configured_limits(): void
    {
        $source = $this->makeAkdenizFixtureSource();

        foreach (['50', '100', '250', '500', 'all'] as $limit) {
            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit={$limit}&filter=all");

            $response->assertOk();
            $response->assertSeeText('Kaynak Önizleme');
            $response->assertSeeText('Toplam okunan');
            $response->assertSeeText('Gösterilen');
            $response->assertSeeText('PB-4007');
            $response->assertSeeText('F-112-32');
        }
    }

    public function test_preview_filters_show_warning_missing_and_parse_quality_groups(): void
    {
        $source = $this->makeAkdenizFixtureSource();

        $warningResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=warning");

        $warningResponse->assertOk();
        $warningResponse->assertSeeText('Net fiyat uyarısı');
        $warningResponse->assertDontSeeText('Kırmızı Ürün');
        $warningResponse->assertDontSeeText('Turuncu Ürün');

        $missingImageResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=missing-image");

        $missingImageResponse->assertOk();
        $missingImageResponse->assertSeeText('Görsel eksik');

        $missingPriceResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=missing-price");

        $missingPriceResponse->assertOk();
        $missingPriceResponse->assertSeeText('Fiyat eksik');

        $netPriceResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=net-price-warning");

        $netPriceResponse->assertOk();
        $netPriceResponse->assertSeeText('standart iskonto uygulanmamalı');

        $supplierWarningResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=supplier-warning");

        $supplierWarningResponse->assertOk();
        $supplierWarningResponse->assertSeeText('özel fiyat/iskonto uyarılı');

        $parseErrorResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=parse-error");

        $parseErrorResponse->assertOk();
        $parseErrorResponse->assertSeeText('Seçilen limit veya filtre için ürün bulunamadı.');
    }

    public function test_preview_shows_turkish_limit_warning_and_button_routes(): void
    {
        $source = $this->makeAkdenizFixtureSource();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=999&filter=all");

        $response->assertOk();
        $response->assertSeeText('Geçersiz limit seçildi. Varsayılan 50 kayıt gösterildi.');
        $response->assertSee("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all", false);
        $response->assertSee("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", false);
        $response->assertSee("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products", false);
        $response->assertSee('/admin/super-admin/product-data-hub/sources', false);
    }

    public function test_ilpen_missing_product_code_is_softened_when_generated_code_is_safe(): void
    {
        $source = $this->makeIlpenFixtureSource();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=warning");

        $response->assertOk();
        $response->assertSeeText('İlpen profilinde ana ürün kodu ürün kartı seviyesinde gelmiyor. Kod bilgisi varyasyon/grup alanlarından türetildiği için bu kayıt kritik hata olarak değerlendirilmedi.');
        $response->assertSeeText('Kod türetildi');
        $response->assertDontSeeText('Bu kayıtta ürün veya varyant kodu üretilemedi. Standard ürüne dönüştürmeden önce alan eşlemesi kontrol edilmelidir.');
    }

    public function test_ilpen_variant_code_missing_stays_critical(): void
    {
        $source = $this->makeIlpenFixtureSource(true);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=warning");

        $response->assertOk();
        $response->assertSeeText('Bu kayıtta ürün veya varyant kodu üretilemedi. Standard ürüne dönüştürmeden önce alan eşlemesi kontrol edilmelidir.');
        $response->assertSeeText('Kritik hata');
    }

    public function test_ilpen_image_fallback_is_info_not_missing_image(): void
    {
        $source = $this->makeIlpenFixtureSource();

        $warningResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=warning");

        $warningResponse->assertOk();
        $warningResponse->assertSeeText('Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.');

        $missingImageResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=missing-image");

        $missingImageResponse->assertOk();
        $missingImageResponse->assertSeeText('Seçilen limit veya filtre için ürün bulunamadı.');
    }

    public function test_missing_purchase_price_discount_or_usd_does_not_trigger_missing_price_when_list_price_exists(): void
    {
        $source = $this->makeIlpenFixtureSource();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=all&filter=missing-price");

        $response->assertOk();
        $response->assertSeeText('Seçilen limit veya filtre için ürün bulunamadı.');
    }

    private function makeAkdenizFixtureSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Akdeniz Test',
            'code' => 'AKDENIZ-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Akdeniz Preview Test',
            'config' => [
                'format' => 'xml',
            ],
            'status' => 'active',
        ]);

        $fixturesDir = storage_path('framework/testing/product-data-hub-preview');
        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $this->akdenizFixtureXml());

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['product_node_path'] = 'RECORD';
        $config['source_file_path'] = $filePath;
        $config['profile_key'] = 'AKDENIZ';
        $source->forceFill([
            'url' => null,
            'config' => $config,
        ])->save();

        return $source;
    }

    private function makeIlpenFixtureSource(bool $withoutVariantCode = false): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'İlpen Test',
            'code' => 'ILPEN-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'İlpen Preview Test',
            'config' => [
                'format' => 'xml',
            ],
            'status' => 'active',
        ]);

        $fixturesDir = storage_path('framework/testing/product-data-hub-preview');
        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $this->ilpenFixtureXml($withoutVariantCode));

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['product_node_path'] = 'Urun';
        $config['source_file_path'] = $filePath;
        $config['profile_key'] = 'ILPEN';
        $source->forceFill([
            'url' => null,
            'config' => $config,
        ])->save();

        return $source;
    }

    private function akdenizFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <RECORD>
        <urun_id>9001</urun_id>
        <urunkodu>PB-4007</urunkodu>
        <urunattr_id>PB-4007-1</urunattr_id>
        <urunattrgr>PB-4007</urunattrgr>
        <urunattradi>PB-4007 Siyah</urunattradi>
        <urunadi>Powerbank</urunadi>
        <listefiyati>986.00</listefiyati>
        <listefiyatkapali>986.00</listefiyatkapali>
        <iskonto>0.45</iskonto>
        <netfiyat>542.30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>15</stokmiktar>
        <stokresim>https://example.test/pb-main.jpg</stokresim>
        <urunresim>https://example.test/pb-gallery.jpg</urunresim>
        <kategori>Powerbank</kategori>
    </RECORD>
    <RECORD>
        <urun_id>9002</urun_id>
        <urunkodu>F-112-32</urunkodu>
        <urunattr_id>F-112-32-1</urunattr_id>
        <urunattrgr>F-112-32</urunattrgr>
        <urunattradi>F-112-32 Metal</urunattradi>
        <urunadi>Metal Usb Bellek</urunadi>
        <listefiyati>280.55</listefiyati>
        <listefiyatkapali>0</listefiyatkapali>
        <iskonto>0.00</iskonto>
        <netfiyat>280.55</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>11</stokmiktar>
        <stokresim>https://example.test/net-main.jpg</stokresim>
        <fiyataciklamasi>Fiyat alınız.</fiyataciklamasi>
        <discat_name>1 - Usb Bellekler NET</discat_name>
        <urun_turuncu>1</urun_turuncu>
        <kategori>Usb Bellekler</kategori>
    </RECORD>
    <RECORD>
        <urun_id>9003</urun_id>
        <urunkodu></urunkodu>
        <urunattr_id></urunattr_id>
        <urunattrgr></urunattrgr>
        <urunattradi>Eksik Satir</urunattradi>
        <urunadi></urunadi>
        <listefiyati>0</listefiyati>
        <listefiyatkapali>0</listefiyatkapali>
        <iskonto>0</iskonto>
        <netfiyat>0</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>0</stokmiktar>
        <stokresim></stokresim>
        <kategori></kategori>
    </RECORD>
</ROOT>
XML;
    }

    private function ilpenFixtureXml(bool $withoutVariantCode = false): string
    {
        $stockCode = $withoutVariantCode ? '' : '1173 BEYAZ';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <Urun>
        <UrunKartiID>299</UrunKartiID>
        <UrunAdi>Twist Çevirmeli Tükenmez Kalem</UrunAdi>
        <UrunGrupKodu>1173</UrunGrupKodu>
        <ResimUrl>https://example.test/ilpen-parent.jpg</ResimUrl>
        <SatisFiyati>145.00</SatisFiyati>
        <ParaBirimi>TL</ParaBirimi>
        <KdvOrani>20</KdvOrani>
        <KategoriMain>Kalemler</KategoriMain>
        <KategoriSub>Metal Kalemler</KategoriSub>
        <UrunSecenek>
            <Secenek>
                <VaryasyonID>1288</VaryasyonID>
                <StokKodu>{$stockCode}</StokKodu>
                <StokAdedi>50460</StokAdedi>
                <EkSecenekOzellik>
                    <Ozellik Tanim="Renk" Deger="Beyaz">Beyaz</Ozellik>
                </EkSecenekOzellik>
                <VaryasyonResim></VaryasyonResim>
            </Secenek>
        </UrunSecenek>
    </Urun>
</ROOT>
XML;
    }
}
