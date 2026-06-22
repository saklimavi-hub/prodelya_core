<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\ProductPageGalleryEnrichmentService;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDataHubSourceParsingTest extends TestCase
{
    use RefreshDatabase;

    public function test_yeni_nesil_xml_is_parsed_into_rows(): void
    {
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');

        $result = app(SourceParserService::class)->parse($source, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <urunler>
        <uid>406323</uid>
        <kod>406323</kod>
        <kodgrup>4063</kodgrup>
        <stokkod>4063 Turkuaz</stokkod>
        <renk>Turkuaz</renk>
        <ebat>750 ML</ebat>
        <turuncu>1</turuncu>
        <stok>240</stok>
        <toplamstok>240</toplamstok>
        <resim1>yn-image.jpg</resim1>
        <fiyat>38</fiyat>
        <dolar_fiyat>1.40</dolar_fiyat>
        <kdv>20</kdv>
        <kategori>Termos Matara</kategori>
    </urunler>
    <urunler>
        <uid>406324</uid>
        <kod>406324</kod>
        <kodgrup>4063</kodgrup>
        <stokkod>4063 Siyah</stokkod>
        <renk>Siyah</renk>
        <stok>120</stok>
        <toplamstok>360</toplamstok>
        <resim1>yn-image-2.jpg</resim1>
        <fiyat>40</fiyat>
        <kdv>20</kdv>
        <kategori>Termos Matara</kategori>
    </urunler>
</ROOT>
XML);

        $this->assertTrue($result['ok']);
        $this->assertSame('xml', $result['content_type']);
        $this->assertSame('urunler', $result['node_path']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('406323', $result['rows'][0]['kod']);
    }

    public function test_akdeniz_record_xml_is_parsed_into_rows(): void
    {
        $source = $this->makeSource('AKDENIZ', 'Akdeniz', 'RECORD');

        $result = app(SourceParserService::class)->parse($source, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <RECORD>
        <urun_id>9001</urun_id>
        <urunkodu>509-BK Siyah</urunkodu>
        <urunattr_id>A1</urunattr_id>
        <urunattrgr>509-BK</urunattrgr>
        <urunattradi>Siyah</urunattradi>
        <urunadi>Kurumsal Mug</urunadi>
        <pure_prodname>Kurumsal Mug</pure_prodname>
        <listefiyati>110,00</listefiyati>
        <listefiyatkapali>95,00</listefiyatkapali>
        <iskonto>15</iskonto>
        <netfiyat>85,00</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>320</stokmiktar>
        <stokresim>ak-variant.jpg</stokresim>
        <urunresim>urunresim.jpg</urunresim>
        <urunresim1>urunresim1.jpg</urunresim1>
        <urunresim2>urunresim2.jpg</urunresim2>
        <urunresim3>urunresim3.jpg</urunresim3>
        <urunresim4></urunresim4>
        <urunresim5>urunresim2.jpg</urunresim5>
        <urunresim6>urunresim5.jpg</urunresim6>
        <kategori>Kurumsal Setler</kategori>
    </RECORD>
</ROOT>
XML);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('509-BK Siyah', $result['rows'][0]['urunkodu']);

        $preview = app(PreviewParserService::class)->previewSource($source, $result['rows']);

        $this->assertSame([
            'ak-variant.jpg',
            'urunresim.jpg',
            'urunresim1.jpg',
            'urunresim2.jpg',
            'urunresim3.jpg',
            'urunresim5.jpg',
        ], $preview['products'][0]['gallery_images']);
        $this->assertSame('ak-variant.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('stokresim', $preview['products'][0]['image_source_field']);
        $this->assertSame(['stokresim', 'urunresim', 'urunresim1', 'urunresim2', 'urunresim3', 'urunresim6'], $preview['products'][0]['gallery_source_fields']);
        $this->assertCount(6, $preview['products'][0]['gallery_images']);
        $this->assertSame(85.0, $preview['products'][0]['purchase_price']);
        $this->assertSame(85.0, $preview['products'][0]['net_price']);
        $this->assertSame(110.0, $preview['products'][0]['list_price']);
        $this->assertSame(95.0, $preview['products'][0]['closed_list_price']);
        $this->assertSame(15.0, $preview['products'][0]['discount_rate']);
        $this->assertFalse($preview['products'][0]['net_price_warning']);
        $this->assertFalse($preview['products'][0]['price_policy_warning']);
        $this->assertFalse($preview['products'][0]['supplier_warning_flag'] ?? false);
        $this->assertSame('discounted_list_price', $preview['products'][0]['pricing_policy_type']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Akdeniz liste fiyatı satış referansı olarak kullanıldı.')));
        $this->assertSame('ak-variant.jpg', $preview['variants'][0]['variant_image_url']);
        $this->assertSame('stokresim', $preview['variants'][0]['variant_image_source_field']);
        $this->assertFalse($preview['variants'][0]['image_fallback_used']);
        $this->assertSame('Siyah', $preview['variants'][0]['variant_color']);
        $this->assertSame('urunattradi', $preview['variants'][0]['extracted_color_source']);
        $this->assertSame(85.0, $preview['variants'][0]['purchase_price']);
        $this->assertSame(110.0, $preview['variants'][0]['list_price']);
        $this->assertSame(95.0, $preview['variants'][0]['closed_list_price']);
        $this->assertSame(15.0, $preview['variants'][0]['discount_rate']);
        $this->assertFalse($preview['variants'][0]['net_price_warning']);
        $this->assertSame('discounted_list_price', $preview['variants'][0]['pricing_policy_type']);
        $this->assertTrue(collect($preview['variants'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Akdeniz liste fiyatı satış referansı olarak kullanıldı.')));
    }

    public function test_akdeniz_net_price_warning_is_raised_for_net_priced_products(): void
    {
        $source = $this->makeSource('AKDENIZ', 'Akdeniz', 'RECORD');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'urun_id' => '9901',
            'urunkodu' => 'F-112-32',
            'urunattr_id' => 'NET1',
            'urunattrgr' => 'F-112',
            'urunattradi' => 'F-112-32 Metal',
            'urunadi' => 'Metal Usb Bellek',
            'listefiyati' => '280.55',
            'iskonto' => '0.00',
            'netfiyat' => '280.55',
            'kur' => 'TL',
            'kdvorani' => '20',
            'listefiyatkapali' => '0',
            'fiyataciklamasi' => 'Fiyat alınız.',
            'discat_name' => '1 - Usb Bellekler NET',
            'stokresim' => 'net-urun.jpg',
        ]]);

        $this->assertSame(280.55, $preview['products'][0]['list_price']);
        $this->assertSame(280.55, $preview['products'][0]['purchase_price']);
        $this->assertSame(0.0, $preview['products'][0]['discount_rate']);
        $this->assertSame('TL', $preview['products'][0]['currency']);
        $this->assertSame(20.0, $preview['products'][0]['vat_rate']);
        $this->assertTrue($preview['products'][0]['net_price_warning']);
        $this->assertTrue($preview['products'][0]['price_policy_warning']);
        $this->assertSame('net_price', $preview['products'][0]['pricing_policy_type']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'standart iskonto uygulanmamalı')));
        $this->assertTrue($preview['variants'][0]['net_price_warning']);
        $this->assertTrue($preview['variants'][0]['price_policy_warning']);
        $this->assertSame('net_price', $preview['variants'][0]['pricing_policy_type']);
    }

    public function test_ilpen_nested_xml_keeps_variant_structure(): void
    {
        $source = $this->makeSource('ILPEN', 'İlpen', 'Urun');

        $result = app(SourceParserService::class)->parse($source, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <Urun>
        <UrunKartiID>299</UrunKartiID>
        <UrunAdi>Twist Çevirmeli Tükenmez Kalem</UrunAdi>
        <UrunGrupKodu>1173</UrunGrupKodu>
        <ResimUrl>ilpen-parent.jpg</ResimUrl>
        <UrunUrl>https://www.example.com/urun/twist-cevirmeli-kalem</UrunUrl>
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
                <VaryasyonResim></VaryasyonResim>
            </Secenek>
            <Secenek>
                <VaryasyonID>1294</VaryasyonID>
                <StokKodu>1173 SİYAH</StokKodu>
                <StokAdedi>1221</StokAdedi>
                <EkSecenekOzellik>
                    <Ozellik Tanim="Renk" Deger="Siyah">Siyah</Ozellik>
                </EkSecenekOzellik>
                <VaryasyonResim>ilpen-variant-siyah.jpg</VaryasyonResim>
            </Secenek>
        </UrunSecenek>
    </Urun>
</ROOT>
XML);

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['rows'][0]['UrunSecenek']);
        $this->assertIsArray($result['rows'][0]['UrunSecenek']['Secenek']);
        $this->assertCount(3, $result['rows'][0]['UrunSecenek']['Secenek']);
        $this->assertSame('Renk', $result['rows'][0]['UrunSecenek']['Secenek'][0]['EkSecenekOzellik']['Ozellik']['_attributes']['Tanim']);
        $this->assertSame('Beyaz', $result['rows'][0]['UrunSecenek']['Secenek'][0]['EkSecenekOzellik']['Ozellik']['_attributes']['Deger']);
        $this->assertSame('Beyaz', $result['rows'][0]['UrunSecenek']['Secenek'][0]['EkSecenekOzellik']['Ozellik']['_value']);

        $preview = app(PreviewParserService::class)->previewSource($source, $result['rows']);

        $this->assertCount(3, $preview['variants']);
        $this->assertSame('ilpen-parent.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('IL-1173', $preview['products'][0]['generated_product_code']);
        $this->assertSame(71476.0, $preview['products'][0]['stock_quantity']);
        $this->assertSame(71476.0, $preview['products'][0]['total_variant_stock_quantity']);
        $this->assertSame('https://www.example.com/urun/twist-cevirmeli-kalem', $preview['products'][0]['product_url']);
        $this->assertTrue($preview['variants'][0]['image_fallback_used']);
        $this->assertSame('ilpen-parent.jpg', $preview['variants'][0]['variant_image_url']);
        $this->assertSame('ResimUrl', $preview['variants'][0]['variant_image_source_field']);
        $this->assertSame('Beyaz', $preview['variants'][0]['variant_color']);
        $this->assertSame('1173 BEYAZ', $preview['variants'][0]['variant_stock_code']);
        $this->assertSame('IL-1173-BEYAZ', $preview['variants'][0]['generated_variant_code']);
        $this->assertSame(50460.0, $preview['variants'][0]['variant_stock_quantity']);
        $this->assertSame('EkSecenekOzellik.Ozellik[Tanim=Renk].Deger', $preview['variants'][0]['extracted_color_source']);
        $this->assertSame(['ilpen-parent.jpg'], $preview['products'][0]['gallery_images']);
        $this->assertSame(['ilpen-parent.jpg'], array_slice($preview['variants'][0]['gallery_images'], 0, 1));
        $this->assertTrue(collect($preview['variants'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Renk bilgisi varyasyon özelliği/stok kodundan çıkarıldı.')));
        $this->assertTrue(collect($preview['variants'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.')));

        $this->assertTrue($preview['variants'][1]['image_fallback_used']);
        $this->assertSame('Sarı', $preview['variants'][1]['variant_color']);
        $this->assertSame('IL-1173-SARI', $preview['variants'][1]['generated_variant_code']);
        $this->assertSame(19795.0, $preview['variants'][1]['variant_stock_quantity']);

        $this->assertSame('ilpen-variant-siyah.jpg', $preview['variants'][2]['variant_image_url']);
        $this->assertSame('VaryasyonResim', $preview['variants'][2]['variant_image_source_field']);
        $this->assertFalse($preview['variants'][2]['image_fallback_used']);
        $this->assertSame('Siyah', $preview['variants'][2]['variant_color']);
        $this->assertSame('IL-1173-SIYAH', $preview['variants'][2]['generated_variant_code']);
        $this->assertSame(1221.0, $preview['variants'][2]['variant_stock_quantity']);
    }

    public function test_preview_parser_uses_live_source_mode_for_parsed_rows(): void
    {
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');

        $parsed = app(SourceParserService::class)->parse($source, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <urunler>
        <uid>406323</uid>
        <kod>406323</kod>
        <kodgrup>4063</kodgrup>
        <stokkod>4063 Turkuaz</stokkod>
        <renk>Turkuaz</renk>
        <ebat>750 ML</ebat>
        <turuncu>1</turuncu>
        <stok>240</stok>
        <toplamstok>240</toplamstok>
        <resim1>yn-image.jpg</resim1>
        <resim2>yn-image-2.jpg</resim2>
        <resim3>yn-image-3.jpg</resim3>
        <fiyat>38</fiyat>
        <dolar_fiyat>1.40</dolar_fiyat>
        <kdv>20</kdv>
        <kategori>Termos Matara</kategori>
    </urunler>
</ROOT>
XML);

        $preview = app(PreviewParserService::class)->previewSource($source, $parsed['rows']);

        $this->assertSame('live_source', $preview['source_mode']);
        $this->assertSame(1, $preview['stats']['records_read']);
        $this->assertSame(1, $preview['stats']['product_count']);
        $this->assertSame(1, $preview['stats']['variant_count']);
        $this->assertSame('yn-image.jpg', $preview['products'][0]['image_url']);
        $this->assertSame('resim1', $preview['products'][0]['image_source_field']);
        $this->assertSame(['resim1', 'resim2', 'resim3'], $preview['products'][0]['gallery_source_fields']);
        $this->assertCount(3, $preview['products'][0]['gallery_images']);
        $this->assertSame(38.0, $preview['products'][0]['list_price']);
        $this->assertSame(1.4, $preview['products'][0]['alternative_price']);
        $this->assertNull($preview['products'][0]['purchase_price']);
        $this->assertTrue($preview['products'][0]['price_policy_warning']);
        $this->assertTrue($preview['products'][0]['supplier_warning_flag']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Yeni Nesil fiyat alanı liste fiyatı olarak yorumlandı.')));
        $this->assertSame('yn-image.jpg', $preview['variants'][0]['variant_image_url']);
        $this->assertSame('resim1', $preview['variants'][0]['variant_image_source_field']);
        $this->assertSame('Turkuaz', $preview['variants'][0]['variant_color']);
        $this->assertSame(240.0, $preview['variants'][0]['variant_stock_quantity']);
        $this->assertNull($preview['variants'][0]['purchase_price']);
        $this->assertSame(1.4, $preview['products'][0]['usd_price']);
    }

    public function test_json_parser_finds_products_array_automatically(): void
    {
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon', null, 'json');

        $result = app(SourceParserService::class)->parse($source, json_encode([
            'products' => [
                ['urun_kodu' => '0506-L', 'urun_adi' => 'Plastik Kalem Lacivert', 'urun_fiyat' => 9.20],
                ['urun_kodu' => '0506-M', 'urun_adi' => 'Plastik Kalem Mavi', 'urun_fiyat' => 9.40],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue($result['ok']);
        $this->assertSame('products', $result['node_path']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('0506-L', $result['rows'][0]['urun_kodu']);
    }

    public function test_etkin_json_root_object_with_variants_is_normalized_into_parent_and_variant_rows(): void
    {
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon', null, 'json');

        $result = app(SourceParserService::class)->parse($source, json_encode([
            '17899' => [
                'urun_id' => 17899,
                'kategori_id' => 100,
                'kategori_adi' => 'USB Bellekler',
                'urun_kodu' => '8115-S-16GB',
                'urun_kodgrup' => '8115',
                'urun_isim' => 'Metal USB Bellek',
                'urun_baslik' => '8115-S-16GB Metal USB Bellek',
                'urun_aciklama' => 'Metal USB Bellek açıklaması',
                'urun_renk' => 'Siyah',
                'urun_ebat' => '16 GB',
                'toplam_stok' => 2000,
                'urun_fiyat' => '0.000',
                'urun_fiyat_virgul' => '0,000',
                'fiyat_kdv' => 20,
                'kirmiziurun' => 0,
                'urun_trase' => 'https://example.com/trase/8115',
                'katalog_sayfa_no' => 44,
                'resim1' => 'https://example.com/8115-main.jpg',
                'resim2' => 'https://example.com/8115-gallery.jpg',
                'resim3' => '',
                'md5' => 'hash-8115',
                'varyantlar' => [
                    [
                        'urun_id' => 12406,
                        'urun_kodu' => '8115-32GB',
                        'urun_kodgrup' => '8115',
                        'urun_isim' => 'Metal USB Bellek',
                        'urun_baslik' => '8115-32GB Metal USB Bellek',
                        'urun_renk' => '',
                        'urun_ebat' => '32 GB',
                        'toplam_stok' => 2468,
                        'urun_fiyat' => '0.000',
                        'fiyat_kdv' => '20',
                        'kirmiziurun' => 0,
                        'urun_trase' => 'https://example.com/trase/8115',
                        'resim1' => 'https://example.com/8115-32-main.jpg',
                        'resim2' => 'https://example.com/8115-32-gallery.jpg',
                        'md5' => 'hash-8115-32',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('17899', $result['rows'][0]['_root_key']);
        $this->assertIsArray($result['rows'][0]['varyantlar']);

        $preview = app(PreviewParserService::class)->previewSource($source, $result['rows']);

        $this->assertCount(1, $preview['products']);
        $this->assertGreaterThanOrEqual(2, count($preview['variants']));
        $this->assertSame('ET-8115', $preview['products'][0]['generated_product_code']);
        $this->assertSame([
            'https://example.com/8115-main.jpg',
            'https://example.com/8115-gallery.jpg',
        ], $preview['products'][0]['gallery_images']);
        $this->assertSame('https://example.com/trase/8115', $preview['products'][0]['artwork_template_url']);
        $this->assertSame('https://example.com/trase/8115', $preview['products'][0]['product_url']);
        $this->assertFalse($preview['products'][0]['warning_flag']);

        $variantCodes = collect($preview['variants'])->pluck('generated_variant_code')->all();
        $this->assertContains('ET-8115-S-16GB', $variantCodes);
        $this->assertContains('ET-8115-32GB', $variantCodes);

        $parentVariant = collect($preview['variants'])->firstWhere('generated_variant_code', 'ET-8115-S-16GB');
        $childVariant = collect($preview['variants'])->firstWhere('generated_variant_code', 'ET-8115-32GB');

        $this->assertSame('16 GB', $parentVariant['variant_size']);
        $this->assertSame('32 GB', $childVariant['variant_size']);
        $this->assertSame('https://example.com/trase/8115', $childVariant['artwork_template_url']);
        $this->assertFalse($childVariant['warning_flag'] ?? false);
    }

    public function test_etkin_product_code_and_name_fallbacks_work_when_group_code_is_missing(): void
    {
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon', null, 'json');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            '_root_key' => '17801',
            'urun_id' => 17801,
            'kategori_adi' => 'Anahtarlıklar',
            'urun_kodu' => 'CT-3850',
            'urun_kodgrup' => '',
            'urun_isim' => '',
            'urun_baslik' => 'CT-3850 Anahtarlık',
            'toplam_stok' => 15,
            'urun_fiyat' => '12,50',
            'fiyat_kdv' => 20,
            'resim1' => 'https://example.com/ct-3850.jpg',
            'varyantlar' => [],
        ]]);

        $this->assertSame('ET-CT-3850', $preview['products'][0]['generated_product_code']);
        $this->assertSame('CT-3850 Anahtarlık', $preview['products'][0]['product_name']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Grup kodu bulunamadı')));
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Ürün adı fallback alanından üretildi.')));
    }

    public function test_etkin_variant_color_can_fall_back_to_parent_color_and_gallery_collects_multiple_images(): void
    {
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon', null, 'json');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'urun_id' => 17899,
            'kategori_adi' => 'USB Bellekler',
            'urun_kodu' => '8115-S-16GB',
            'urun_kodgrup' => '8115',
            'urun_isim' => 'Metal USB Bellek',
            'urun_renk' => 'Siyah',
            'urun_ebat' => '16 GB',
            'toplam_stok' => 2000,
            'urun_fiyat' => '0.000',
            'fiyat_kdv' => 20,
            'resim1' => 'https://example.com/1.jpg',
            'resim2' => 'https://example.com/2.jpg',
            'resim3' => 'https://example.com/3.jpg',
            'urun_trase' => 'https://example.com/trase/8115',
            'varyantlar' => [
                [
                    'urun_id' => 12406,
                    'urun_kodu' => '8115-32GB',
                    'urun_kodgrup' => '8115',
                    'urun_isim' => 'Metal USB Bellek',
                    'urun_baslik' => '8115-32GB Metal USB Bellek',
                    'urun_renk' => '',
                    'urun_ebat' => '32 GB',
                    'toplam_stok' => 2468,
                    'urun_fiyat' => '0.000',
                    'fiyat_kdv' => '20',
                    'kirmiziurun' => 0,
                    'resim1' => 'https://example.com/8115-32-main.jpg',
                    'md5' => 'hash-8115-32',
                ],
            ],
        ]]);

        $this->assertCount(3, $preview['products'][0]['gallery_images']);
        $parentVariant = collect($preview['variants'])->firstWhere('generated_variant_code', 'ET-8115-S-16GB');
        $childVariant = collect($preview['variants'])->firstWhere('generated_variant_code', 'ET-8115-32GB');

        $this->assertSame('Siyah', $parentVariant['variant_color']);
        $this->assertSame('16 GB', $parentVariant['variant_size']);
        $this->assertSame('Siyah', $childVariant['variant_color']);
        $this->assertSame('32 GB', $childVariant['variant_size']);
        $this->assertSame('https://example.com/trase/8115', $childVariant['artwork_template_url']);
        $this->assertTrue(collect($childVariant['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Renk bilgisi ürün adı veya parent üründen çıkarıldı.')));
    }

    public function test_product_page_gallery_enrichment_service_filters_non_product_images_and_normalizes_relative_urls(): void
    {
        $service = app(ProductPageGalleryEnrichmentService::class);

        $images = $service->extractImagesFromHtml(<<<'HTML'
<!doctype html>
<html>
    <head>
        <meta property="og:image" content="/urunresimleri/og-main.jpg">
    </head>
    <body>
        <div class="gallery">
            <img src="/urunresimleri/kalem-1.jpg" alt="Ürün 1">
            <img data-src="https://www.example.com/img/products/kalem-2.webp" alt="Ürün 2">
            <a href="/urunresimleri/kalem-3.jpg"><img src="/icons/thumb.jpg" alt="Thumb"></a>
            <img src="/assets/logo.png" alt="Logo">
            <img src="/assets/whatsapp-icon.png" alt="Whatsapp">
        </div>
    </body>
</html>
HTML, 'https://www.example.com/urun/test-urun.html', '.gallery img');

        $this->assertSame([
            'https://www.example.com/urunresimleri/kalem-1.jpg',
            'https://www.example.com/img/products/kalem-2.webp',
            'https://www.example.com/urunresimleri/kalem-3.jpg',
            'https://www.example.com/urunresimleri/og-main.jpg',
        ], $images);
    }

    public function test_preview_parser_can_enrich_gallery_from_product_page_when_source_setting_is_enabled(): void
    {
        Http::fake([
            'https://www.example.com/urun/sungurlu-kirmizi-ikili-kalem-seti-4110.html' => Http::response(<<<'HTML'
<!doctype html>
<html>
    <body>
        <div class="gallery">
            <img src="/urunresimleri/set-1.jpg" alt="Set 1">
            <img src="/urunresimleri/set-2.jpg" alt="Set 2">
            <img src="/img/logo.png" alt="Logo">
        </div>
    </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');
        $source->update([
            'config' => array_merge($source->config ?? [], [
                'enrich_gallery_from_product_page' => true,
                'max_gallery_enrichment_products' => 5,
                'max_gallery_images' => 10,
                'product_page_gallery_selector' => '.gallery img',
            ]),
        ]);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'uid' => '406323',
            'kod' => '406323',
            'kodgrup' => '4063',
            'stokkod' => '4063 Kirmizi',
            'renk' => 'Kirmizi',
            'isim' => 'Sungurlu Kirmizi Ikili Kalem Seti',
            'sayfa' => 'https://www.example.com/urun/sungurlu-kirmizi-ikili-kalem-seti-4110.html',
            'resim1' => 'https://www.example.com/urunresimleri/feed-main.jpg',
            'stok' => '25',
            'toplamstok' => '25',
            'fiyat' => '12.50',
            'kdv' => '20',
            'kategori' => 'Kalem Setleri',
        ]]);

        $this->assertSame('https://www.example.com/urun/sungurlu-kirmizi-ikili-kalem-seti-4110.html', $preview['products'][0]['product_url']);
        $this->assertSame('feed+page', $preview['products'][0]['gallery_origin']);
        $this->assertSame(['resim1'], $preview['products'][0]['gallery_source_fields']);
        $this->assertSame([
            'https://www.example.com/urunresimleri/feed-main.jpg',
            'https://www.example.com/urunresimleri/set-1.jpg',
            'https://www.example.com/urunresimleri/set-2.jpg',
        ], $preview['products'][0]['gallery_images']);
        $this->assertSame([
            'https://www.example.com/urunresimleri/set-1.jpg',
            'https://www.example.com/urunresimleri/set-2.jpg',
        ], $preview['products'][0]['page_gallery_images']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'Ürün sayfasından 2 galeri görseli alındı.')));
    }

    public function test_gallery_enrichment_returns_warning_for_empty_html_without_500(): void
    {
        Http::fake([
            'https://www.example.com/urun/bos-galeri.html' => Http::response('', 200, ['Content-Type' => 'text/html']),
        ]);

        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');
        $source->update([
            'config' => array_merge($source->config ?? [], [
                'enrich_gallery_from_product_page' => true,
                'product_page_gallery_selector' => '.gallery img',
            ]),
        ]);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'uid' => '406400',
            'kod' => '406400',
            'kodgrup' => '4064',
            'isim' => 'Boş Galeri Test Ürünü',
            'sayfa' => 'https://www.example.com/urun/bos-galeri.html',
            'resim1' => 'https://www.example.com/urunresimleri/feed-main.jpg',
            'stok' => '10',
            'toplamstok' => '10',
            'fiyat' => '12.50',
            'kdv' => '20',
            'kategori' => 'Kalem Setleri',
        ]]);

        $this->assertSame('feed', $preview['products'][0]['gallery_origin']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(
            fn (string $warning) => str_contains($warning, 'Ürün sayfası boş döndüğü için galeri zenginleştirilemedi.')
        ));
    }

    public function test_gallery_enrichment_returns_warning_for_invalid_product_url(): void
    {
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');
        $source->update([
            'config' => array_merge($source->config ?? [], [
                'enrich_gallery_from_product_page' => true,
            ]),
        ]);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'uid' => '406401',
            'kod' => '406401',
            'kodgrup' => '4064',
            'isim' => 'Geçersiz URL Test Ürünü',
            'sayfa' => 'not-a-real-url',
            'resim1' => 'https://www.example.com/urunresimleri/feed-main.jpg',
            'stok' => '10',
            'toplamstok' => '10',
            'fiyat' => '12.50',
            'kdv' => '20',
            'kategori' => 'Kalem Setleri',
        ]]);

        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(
            fn (string $warning) => str_contains($warning, 'Ürün sayfası linki bulunmadığı için galeri zenginleştirme yapılmadı.')
                || str_contains($warning, 'Ürün sayfası linki geçersiz olduğu için galeri zenginleştirme yapılamadı.')
        ));
    }

    public function test_gallery_enrichment_returns_warning_for_404_without_crashing(): void
    {
        Http::fake([
            'https://www.example.com/urun/not-found.html' => Http::response('not found', 404),
        ]);

        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');
        $source->update([
            'config' => array_merge($source->config ?? [], [
                'enrich_gallery_from_product_page' => true,
            ]),
        ]);

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'uid' => '406402',
            'kod' => '406402',
            'kodgrup' => '4064',
            'isim' => '404 Test Ürünü',
            'sayfa' => 'https://www.example.com/urun/not-found.html',
            'resim1' => 'https://www.example.com/urunresimleri/feed-main.jpg',
            'stok' => '10',
            'toplamstok' => '10',
            'fiyat' => '12.50',
            'kdv' => '20',
            'kategori' => 'Kalem Setleri',
        ]]);

        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(
            fn (string $warning) => str_contains($warning, 'HTTP durum kodu: 404')
        ));
    }

    public function test_yeni_nesil_preview_keeps_gallery_and_price_standard_for_multi_image_row(): void
    {
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil', 'urunler');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'uid' => '4110',
            'kod' => '415604',
            'kodgrup' => '4156',
            'isim' => 'SUNGURLU KIRMIZI İKİLİ KALEM SETİ',
            'renk' => 'Kırmızı',
            'stok' => '5325',
            'resim1' => '415604-001.jpg',
            'resim2' => '415604-002.jpg',
            'resim3' => '415604-003.jpg',
            'fiyat' => '240.00',
            'dolar_fiyat' => '0.00',
            'kdv' => '20.00',
        ]]);

        $this->assertSame('YN-415604', $preview['products'][0]['generated_product_code']);
        $this->assertSame('SUNGURLU KIRMIZI İKİLİ KALEM SETİ', $preview['products'][0]['product_name']);
        $this->assertSame('Kırmızı', $preview['variants'][0]['variant_color']);
        $this->assertSame(5325.0, $preview['products'][0]['stock_quantity']);
        $this->assertSame(5325.0, $preview['products'][0]['total_variant_stock_quantity']);
        $this->assertSame(5325.0, $preview['variants'][0]['variant_stock_quantity']);
        $this->assertSame(240.0, $preview['products'][0]['list_price']);
        $this->assertFalse($preview['products'][0]['price_policy_warning']);
        $this->assertCount(3, $preview['products'][0]['gallery_images']);
        $this->assertSame('resim1', $preview['products'][0]['image_source_field']);
        $this->assertSame(['resim1', 'resim2', 'resim3'], $preview['products'][0]['gallery_source_fields']);
    }

    public function test_zero_stock_is_preserved_and_not_treated_as_missing(): void
    {
        $source = $this->makeSource('AKDENIZ', 'Akdeniz', 'RECORD');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'urun_id' => '830',
            'urunkodu' => 'F-112-32',
            'urunattr_id' => 'F11232-A',
            'urunattrgr' => 'F-112-32',
            'urunattradi' => 'F-112-32 Metal',
            'urunadi' => 'F-112-32 Metal 32 GB Usb Bellek',
            'listefiyati' => '280.55',
            'iskonto' => '0.00',
            'netfiyat' => '280.55',
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokmiktar' => '0',
        ]]);

        $this->assertSame(0.0, $preview['products'][0]['stock_quantity']);
        $this->assertSame(0.0, $preview['variants'][0]['variant_stock_quantity']);
    }

    public function test_supplier_special_warning_flag_is_normalized_from_red_or_orange_fields(): void
    {
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon', null, 'json');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'urun_id' => 8801,
            'urun_kodu' => 'KR-8801',
            'urun_kodgrup' => 'KR-8801',
            'urun_isim' => 'Özel Fiyatlı Kalem',
            'urun_fiyat' => '19.90',
            'fiyat_kdv' => '20',
            'urun_turuncu' => '1',
            'resim1' => 'https://example.com/kr-8801.jpg',
        ]]);

        $this->assertSame(19.9, $preview['products'][0]['list_price']);
        $this->assertTrue($preview['products'][0]['supplier_warning_flag']);
        $this->assertSame('supplier_special_price_warning', $preview['products'][0]['supplier_warning_type']);
        $this->assertTrue($preview['products'][0]['price_policy_warning']);
        $this->assertTrue(collect($preview['products'][0]['warnings'])->contains(fn (string $warning) => str_contains($warning, 'özel fiyat/iskonto uyarılı')));
    }

    public function test_highest_local_price_becomes_list_price_when_multiple_prices_exist(): void
    {
        $source = $this->makeSource('AKDENIZ', 'Akdeniz', 'RECORD');

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'urun_id' => '9010',
            'urunkodu' => 'PB-4007',
            'urunattr_id' => 'PB-4007-1',
            'urunattrgr' => 'PB-4007',
            'urunattradi' => 'PB-4007 Siyah',
            'urunadi' => 'Promosyon Çanta',
            'listefiyati' => '986.00',
            'listefiyatkapali' => '986.00',
            'iskonto' => '0.45',
            'netfiyat' => '542.30',
            'kur' => 'TL',
            'kdvorani' => '20',
        ]]);

        $this->assertSame(986.0, $preview['products'][0]['list_price']);
        $this->assertSame(542.3, $preview['products'][0]['purchase_price']);
        $this->assertFalse($preview['products'][0]['net_price_warning']);
        $this->assertSame('discounted_list_price', $preview['products'][0]['pricing_policy_type']);
    }

    private function makeSource(string $supplierCode, string $supplierName, ?string $nodePath, string $format = 'xml'): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $supplierName,
            'code' => $supplierCode . '-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => $format === 'json' ? 'api' : 'xml',
            'source_name' => $supplierName . ' ' . strtoupper($format),
            'config' => [
                'format' => $format,
                'product_node_path' => $nodePath,
            ],
            'status' => 'active',
        ]);
    }
}
