<?php

namespace Tests\Feature;

use App\Models\StandardProduct;
use App\Models\StandardProductImage;
use App\Models\StandardProductVariant;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubStagingAndBuildSafetyTest extends TestCase
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

    public function test_preview_contains_stage_and_build_forms_for_safe_verification(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareFixtureForSource($source);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/preview?limit=50&filter=all");

        $response->assertOk();
        $response->assertSee('Staging’e Aktar');
        $response->assertSee('Standard Ürüne Dönüştür');
        $response->assertSee("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", false);
        $response->assertSee("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products", false);
    }

    public function test_preview_shows_stock_value_and_does_not_treat_zero_as_missing(): void
    {
        $akdeniz = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareFixtureForSource($akdeniz);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$akdeniz->id}/preview?limit=all&filter=all");

        $response->assertOk();
        $response->assertSee('Stok: 2.531');
        $response->assertSee('Stok: 0');

        $ilpen = $this->findSourceBySupplierCode('ILPEN');
        $this->prepareFixtureForSource($ilpen);

        $ilpenResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$ilpen->id}/preview?limit=all&filter=all");

        $ilpenResponse->assertOk();
        $ilpenResponse->assertSee('Stok: 70.255');
        $ilpenResponse->assertDontSee('Stok bilgisi gelmedi');
    }

    public function test_stage_preview_route_preserves_list_price_warning_and_gallery_fields_for_safe_supplier_fixtures(): void
    {
        $sources = [
            'YENI-NESIL' => $this->findSourceBySupplierCode('YENI-NESIL'),
            'ETKIN' => $this->findSourceBySupplierCode('ETKIN'),
            'AKDENIZ' => $this->findSourceBySupplierCode('AKDENIZ'),
            'ILPEN' => $this->findSourceBySupplierCode('ILPEN'),
        ];

        foreach ($sources as $source) {
            $this->prepareFixtureForSource($source);

            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                    'confirm_stage' => '1',
                ]);

            $response->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");
            $response->assertSessionHas('success');
        }

        $yeniNesil = SupplierProductRaw::query()->where('supplier_source_id', $sources['YENI-NESIL']->id)->where('supplier_product_code', '415604')->firstOrFail();
        $this->assertEquals(240.0, $yeniNesil->normalized_payload['list_price']);
        $this->assertEquals(5325.0, $yeniNesil->normalized_payload['stock_quantity']);
        $this->assertEquals(5325.0, $yeniNesil->normalized_payload['total_variant_stock_quantity']);
        $this->assertNull($yeniNesil->normalized_payload['purchase_price']);
        $this->assertSame('240.00', (string) $yeniNesil->source_price);
        $this->assertSame('resim1', $yeniNesil->normalized_payload['image_source_field']);
        $this->assertCount(3, $yeniNesil->normalized_payload['gallery_images']);
        $this->assertEquals(0.0, (float) $yeniNesil->normalized_payload['usd_price']);

        $etkin = SupplierProductRaw::query()->where('supplier_source_id', $sources['ETKIN']->id)->where('supplier_product_code', '0506-L')->firstOrFail();
        $this->assertEquals(19.9, $etkin->normalized_payload['list_price']);
        $this->assertTrue((bool) $etkin->normalized_payload['supplier_warning_flag']);
        $this->assertSame('supplier_special_price_warning', $etkin->normalized_payload['supplier_warning_type']);
        $this->assertSame('https://example.test/kalem.jpg', $etkin->normalized_payload['image_url']);

        $akdenizNormal = SupplierProductRaw::query()->where('supplier_source_id', $sources['AKDENIZ']->id)->where('supplier_product_code', 'PB-4007')->firstOrFail();
        $this->assertEquals(986.0, $akdenizNormal->normalized_payload['list_price']);
        $this->assertEquals(2531.0, $akdenizNormal->normalized_payload['variant_stock_quantity']);
        $this->assertEquals(542.3, $akdenizNormal->normalized_payload['purchase_price']);
        $this->assertFalse((bool) $akdenizNormal->normalized_payload['net_price_warning']);
        $this->assertSame('discounted_list_price', $akdenizNormal->normalized_payload['pricing_policy_type']);
        $this->assertSame('https://example.test/pb-4007-stok.jpg', $akdenizNormal->normalized_payload['image_url']);
        $this->assertCount(3, $akdenizNormal->normalized_payload['gallery_images']);

        $akdenizNet = SupplierProductRaw::query()->where('supplier_source_id', $sources['AKDENIZ']->id)->where('supplier_product_code', 'F-112-32')->firstOrFail();
        $this->assertEquals(280.55, $akdenizNet->normalized_payload['list_price']);
        $this->assertSame(0.0, (float) $akdenizNet->normalized_payload['variant_stock_quantity']);
        $this->assertTrue((bool) $akdenizNet->normalized_payload['net_price_warning']);
        $this->assertTrue((bool) $akdenizNet->normalized_payload['price_policy_warning']);
        $this->assertSame('net_price', $akdenizNet->normalized_payload['pricing_policy_type']);
        $this->assertTrue(collect($akdenizNet->normalized_payload['warnings'] ?? [])->contains(fn (string $warning) => str_contains($warning, 'standart iskonto uygulanmamalı')));

        $ilpen = SupplierProductRaw::query()->where('supplier_source_id', $sources['ILPEN']->id)->firstOrFail();
        $this->assertEquals(284.0, $ilpen->normalized_payload['list_price']);
        $this->assertEquals(70255.0, $ilpen->normalized_payload['stock_quantity']);
        $this->assertEquals(70255.0, $ilpen->normalized_payload['total_variant_stock_quantity']);
        $this->assertSame('ResimUrl', $ilpen->normalized_payload['image_source_field']);
        $this->assertCount(1, $ilpen->normalized_payload['gallery_images']);
        $this->assertCount(2, $ilpen->variants);

        $ilpenFallbackVariant = SupplierProductVariantRaw::query()->where('supplier_source_id', $sources['ILPEN']->id)->where('variant_stock_code', '1173 BEYAZ')->firstOrFail();
        $this->assertTrue((bool) $ilpenFallbackVariant->normalized_payload['image_fallback_used']);
        $this->assertSame('ilpen-parent.jpg', $ilpenFallbackVariant->normalized_payload['variant_image_url']);
    }

    public function test_build_source_route_preserves_list_price_meta_and_warnings_without_overwriting_with_net_price(): void
    {
        foreach ([
            $this->findSourceBySupplierCode('YENI-NESIL'),
            $this->findSourceBySupplierCode('ETKIN'),
            $this->findSourceBySupplierCode('AKDENIZ'),
            $this->findSourceBySupplierCode('ILPEN'),
        ] as $source) {
            $this->prepareFixtureForSource($source);

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                    'confirm_stage' => '1',
                ])
                ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

            $buildResponse = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products");

            $buildResponse->assertRedirect('/admin/super-admin/product-data-hub/raw-products');
            $buildResponse->assertSessionHas('success');
        }

        $yeniNesil = StandardProduct::query()->where('standard_product_code', 'YN-415604')->firstOrFail();
        $this->assertEquals(240.0, (float) $yeniNesil->min_purchase_price);
        $this->assertEquals(5325.0, (float) $yeniNesil->total_stock_quantity);
        $this->assertEquals(5325.0, data_get($yeniNesil->meta, 'stock_snapshot.stock_quantity'));
        $this->assertEquals(240.0, data_get($yeniNesil->source_summary, '0.list_price'));
        $this->assertCount(3, data_get($yeniNesil->meta, 'gallery_images', []));

        $etkin = StandardProduct::query()->where('standard_product_code', 'ET-0506')->firstOrFail();
        $this->assertEquals(1250.0, (float) $etkin->total_stock_quantity);
        $this->assertTrue((bool) data_get($etkin->meta, 'price_snapshot.supplier_warning_flag'));
        $this->assertSame('supplier_special_price_warning', data_get($etkin->meta, 'price_snapshot.supplier_warning_type'));

        $akdenizNormal = StandardProduct::query()->where('standard_product_code', 'AK-PB-4007')->firstOrFail();
        $this->assertEquals(986.0, (float) $akdenizNormal->min_purchase_price);
        $this->assertEquals(2531.0, (float) $akdenizNormal->total_stock_quantity);
        $this->assertEquals(2531.0, data_get($akdenizNormal->meta, 'stock_snapshot.stock_quantity'));
        $this->assertEquals(986.0, data_get($akdenizNormal->meta, 'price_snapshot.list_price'));
        $this->assertEquals(542.3, data_get($akdenizNormal->meta, 'price_snapshot.net_price'));
        $this->assertFalse((bool) data_get($akdenizNormal->meta, 'price_snapshot.net_price_warning'));
        $this->assertSame('discounted_list_price', data_get($akdenizNormal->meta, 'price_snapshot.pricing_policy_type'));
        $this->assertCount(3, data_get($akdenizNormal->meta, 'gallery_images', []));

        $akdenizNet = StandardProduct::query()->where('standard_product_code', 'AK-F-112-32')->firstOrFail();
        $this->assertEquals(280.55, (float) $akdenizNet->min_purchase_price);
        $this->assertEquals(0.0, (float) $akdenizNet->total_stock_quantity);
        $this->assertTrue((bool) data_get($akdenizNet->meta, 'price_snapshot.net_price_warning'));
        $this->assertTrue((bool) data_get($akdenizNet->meta, 'price_snapshot.price_policy_warning'));
        $this->assertSame('net_price', data_get($akdenizNet->meta, 'price_snapshot.pricing_policy_type'));

        $ilpen = StandardProduct::query()->where('standard_product_code', 'IL-1173')->firstOrFail();
        $this->assertCount(2, $ilpen->variants);
        $this->assertEquals(284.0, data_get($ilpen->meta, 'price_snapshot.list_price'));
        $this->assertEquals(70255.0, (float) $ilpen->total_stock_quantity);
        $this->assertEquals(70255.0, data_get($ilpen->meta, 'stock_snapshot.stock_quantity'));
        $this->assertEquals(70255.0, data_get($ilpen->source_summary, '0.total_variant_stock_quantity'));

        $ilpenFallbackVariant = StandardProductVariant::query()->where('generated_variant_code', 'IL-1173-BEYAZ')->firstOrFail();
        $this->assertTrue((bool) $ilpenFallbackVariant->image_fallback_used);
        $this->assertEquals(50460.0, (float) $ilpenFallbackVariant->stock_quantity);
        $this->assertEquals(50460.0, data_get($ilpenFallbackVariant->meta, 'stock_snapshot.stock_quantity'));
        $this->assertContains('ilpen-parent.jpg', data_get($ilpenFallbackVariant->meta, 'gallery_images', []));
    }

    public function test_staging_and_standard_build_routes_are_idempotent_for_repeated_safe_imports(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareFixtureForSource($source);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ])
            ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $rawProductCount = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count();
        $rawVariantCount = SupplierProductVariantRaw::query()->where('supplier_source_id', $source->id)->count();

        $secondStage = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ]);

        $secondStage->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");
        $secondStage->assertSessionHas('success', fn (string $message) => str_contains($message, 'güncellenen kayıt'));
        $this->assertSame($rawProductCount, SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count());
        $this->assertSame($rawVariantCount, SupplierProductVariantRaw::query()->where('supplier_source_id', $source->id)->count());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products")
            ->assertRedirect('/admin/super-admin/product-data-hub/raw-products');

        $standardProductCount = StandardProduct::query()->count();
        $standardVariantCount = StandardProductVariant::query()->count();
        $standardImageCount = StandardProductImage::query()->count();

        $secondBuild = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products");

        $secondBuild->assertRedirect('/admin/super-admin/product-data-hub/raw-products');
        $secondBuild->assertSessionHas('success', fn (string $message) => str_contains($message, 'güncellenen ürün'));
        $this->assertSame($standardProductCount, StandardProduct::query()->count());
        $this->assertSame($standardVariantCount, StandardProductVariant::query()->count());
        $this->assertSame($standardImageCount, StandardProductImage::query()->count());
    }

    private function prepareFixtureForSource(SupplierSource $source): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-safe-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        [$content, $nodePath] = match ($source->supplier?->code) {
            'YENI-NESIL' => [$this->yeniNesilFixtureXml(), 'urunler'],
            'ETKIN' => [$this->etkinFixtureXml(), 'urun'],
            'AKDENIZ' => [$this->akdenizFixtureXml(), 'RECORD'],
            'ILPEN' => [$this->ilpenFixtureXml(), 'Urun'],
            default => throw new \RuntimeException('Test fixture tanımlanmamış source supplier code: ' . ($source->supplier?->code ?? 'unknown')),
        };

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = $nodePath;

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function yeniNesilFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <urunler>
        <uid>4110</uid>
        <kod>415604</kod>
        <kodgrup>4156</kodgrup>
        <isim>SUNGURLU KIRMIZI İKİLİ KALEM SETİ</isim>
        <renk>Kırmızı</renk>
        <stok>5325</stok>
        <toplamstok>5325</toplamstok>
        <stokkod>415604 KIRMIZI</stokkod>
        <resim1>https://example.test/415604-001.jpg</resim1>
        <resim2>https://example.test/415604-002.jpg</resim2>
        <resim3>https://example.test/415604-003.jpg</resim3>
        <fiyat>240.00</fiyat>
        <dolar_fiyat>0.00</dolar_fiyat>
        <kdv>20.00</kdv>
        <kategori>İkili Kalem Setleri</kategori>
        <turuncu>0</turuncu>
    </urunler>
</ROOT>
XML;
    }

    private function etkinFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_id>ET-100</urun_id>
        <urun_kodu>0506-L</urun_kodu>
        <urun_grupkodu>0506</urun_grupkodu>
        <urun_adi>Plastik Kalem Lacivert</urun_adi>
        <urun_resim>https://example.test/kalem.jpg</urun_resim>
        <urun_kategori>Kalemler</urun_kategori>
        <urun_stok>1250</urun_stok>
        <urun_fiyat>19.90</urun_fiyat>
        <urun_kirmizi>1</urun_kirmizi>
    </urun>
</urunler>
XML;
    }

    private function akdenizFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <RECORD>
        <urun_id>1466</urun_id>
        <urunkodu>PB-4007</urunkodu>
        <urunattr_id>PB4007-A</urunattr_id>
        <urunattrgr>PB-4007</urunattrgr>
        <urunattradi>PB-4007 Siyah</urunattradi>
        <urunadi>PB-4007 Siyah 10.000 mAh Powerbank</urunadi>
        <pure_prodname>10.000 mAh Powerbank</pure_prodname>
        <listefiyati>986.00</listefiyati>
        <iskonto>0.45</iskonto>
        <netfiyat>542.30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <listefiyatkapali>986.00</listefiyatkapali>
        <stokmiktar>2531</stokmiktar>
        <stokresim>https://example.test/pb-4007-stok.jpg</stokresim>
        <urunresim>https://example.test/pb-4007-1.jpg</urunresim>
        <urunresim2>https://example.test/pb-4007-2.jpg</urunresim2>
        <discat_name>5 - Teknoloji Ürünleri TL</discat_name>
        <kategori>Teknoloji Ürünleri</kategori>
    </RECORD>
    <RECORD>
        <urun_id>830</urun_id>
        <urunkodu>F-112-32</urunkodu>
        <urunattr_id>F11232-A</urunattr_id>
        <urunattrgr>F-112-32</urunattrgr>
        <urunattradi>F-112-32 Metal</urunattradi>
        <urunadi>F-112-32 Metal 32 GB Usb Bellek</urunadi>
        <pure_prodname>32 GB Usb Bellek</pure_prodname>
        <listefiyati>280.55</listefiyati>
        <iskonto>0.00</iskonto>
        <netfiyat>280.55</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <listefiyatkapali>0</listefiyatkapali>
        <stokmiktar>0</stokmiktar>
        <fiyataciklamasi>Fiyat alınız.</fiyataciklamasi>
        <discat_name>1 - Usb Bellekler NET</discat_name>
        <stokresim>https://example.test/f-112-32-stok.jpg</stokresim>
        <urunresim>https://example.test/f-112-32-1.jpg</urunresim>
        <kategori>Usb Bellekler</kategori>
    </RECORD>
</ROOT>
XML;
    }

    private function ilpenFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <Urun>
        <UrunKartiID>1173</UrunKartiID>
        <UrunAdi>Twist Çevirmeli Tükenmez Kalem</UrunAdi>
        <UrunGrupKodu>1173</UrunGrupKodu>
        <ResimUrl>ilpen-parent.jpg</ResimUrl>
        <SatisFiyati>284</SatisFiyati>
        <AlisFiyati>250</AlisFiyati>
        <KdvOrani>20</KdvOrani>
        <ParaBirimi>TL</ParaBirimi>
        <UrunSecenek>
            <Secenek>
                <VaryasyonID>1288</VaryasyonID>
                <StokKodu>1173 BEYAZ</StokKodu>
                <StokAdedi>50460</StokAdedi>
                <EkSecenekOzellik>
                    <Ozellik Tanim="Renk" Deger="Beyaz">Beyaz</Ozellik>
                </EkSecenekOzellik>
                <VaryasyonResim></VaryasyonResim>
            </Secenek>
            <Secenek>
                <VaryasyonID>1289</VaryasyonID>
                <StokKodu>1173 SARI</StokKodu>
                <StokAdedi>19795</StokAdedi>
                <EkSecenekOzellik>
                    <Ozellik Tanim="Renk" Deger="Sarı">Sarı</Ozellik>
                </EkSecenekOzellik>
                <VaryasyonResim>ilpen-variant-sari.jpg</VaryasyonResim>
            </Secenek>
        </UrunSecenek>
    </Urun>
</ROOT>
XML;
    }

    private function findSourceBySupplierCode(string $supplierCode): SupplierSource
    {
        return SupplierSource::query()
            ->with('supplier')
            ->whereHas('supplier', fn ($query) => $query->where('code', $supplierCode))
            ->orderBy('id')
            ->firstOrFail();
    }
}
