<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubPozitronJsonProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_pozitron_json_profile_parses_root_array_and_normalizes_parent_and_variants(): void
    {
        $source = $this->makePozitronSource();

        $result = app(SourceParserService::class)->parse($source, json_encode([
            [
                'id' => 1300,
                'urun_sku' => 'K1300',
                'urun_adi' => 'Kutu Set',
                'urun_aciklamasi' => 'Kutu set aciklama',
                'urun_url' => 'https://pozitronpromosyon.com/urun/k1300',
                'kategoriler' => [
                    ['id' => 41, 'ad' => 'Setler', 'slug' => 'setler'],
                ],
                'urun_gorselleri' => [
                    'https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg',
                    'https://pozitronpromosyon.com/uploads/k1300-parent-2.jpg',
                ],
                'urun_fiyati' => '27.50',
                'kdv_orani' => '20',
                'stok_kaynagi' => ['tip' => 'bundle'],
                'bilesenler' => [['sku' => 'A'], ['sku' => 'B']],
                'varyasyonlar' => [
                    [
                        'varyasyon_id' => 9001,
                        'stok_kodu' => 'K1300KR',
                        'renk' => 'Kirmizi',
                        'stok_adedi' => 18,
                        'fiyat' => '28.75',
                        'gorseller' => [
                            'https://pozitronpromosyon.com/uploads/k1300-kirmizi.jpg',
                        ],
                        'urun_url' => 'https://pozitronpromosyon.com/urun/k1300?renk=kirmizi',
                    ],
                    [
                        'varyasyon_id' => 9002,
                        'stok_kodu' => 'K1300SYH',
                        'renk' => 'Siyah',
                        'stok_adedi' => 7,
                        'fiyat' => '29.10',
                        'gorseller' => [],
                        'urun_url' => 'https://pozitronpromosyon.com/urun/k1300?renk=siyah',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue($result['ok']);
        $this->assertSame('json', $result['content_type']);
        $this->assertCount(1, $result['rows']);

        $preview = app(PreviewParserService::class)->previewSource($source, $result['rows']);

        $this->assertSame('POZITRON_JSON', $preview['profile_key']);
        $this->assertSame('live_source', $preview['source_mode']);
        $this->assertSame(1, $preview['stats']['records_read']);
        $this->assertCount(1, $preview['products']);
        $this->assertCount(2, $preview['variants']);

        $product = $preview['products'][0];
        $this->assertSame('K1300', $product['supplier_product_code']);
        $this->assertSame('Kutu Set', $product['product_name']);
        $this->assertSame('Setler', $product['supplier_category_name']);
        $this->assertSame('setler', $product['supplier_category_slug']);
        $this->assertSame('USD', $product['currency']);
        $this->assertSame(27.5, $product['list_price']);
        $this->assertSame('list_price', $product['pricing_policy_type']);
        $this->assertFalse($product['net_price_warning']);
        $this->assertFalse($product['price_policy_warning']);
        $this->assertSame('https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg', $product['image_url']);
        $this->assertSame([
            'https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg',
            'https://pozitronpromosyon.com/uploads/k1300-parent-2.jpg',
        ], $product['gallery_images']);
        $this->assertSame(25.0, $product['total_variant_stock_quantity']);
        $this->assertSame('bundle', data_get($product, 'normalized_payload.stock_source_type'));
        $this->assertSame(2, data_get($product, 'normalized_payload.bundle_component_count'));

        $firstVariant = collect($preview['variants'])->firstWhere('variant_stock_code', 'K1300KR');
        $secondVariant = collect($preview['variants'])->firstWhere('variant_stock_code', 'K1300SYH');

        $this->assertSame('USD', $firstVariant['currency']);
        $this->assertSame(28.75, $firstVariant['list_price']);
        $this->assertSame(18.0, $firstVariant['variant_stock_quantity']);
        $this->assertSame('https://pozitronpromosyon.com/uploads/k1300-kirmizi.jpg', $firstVariant['variant_image_url']);
        $this->assertSame('https://pozitronpromosyon.com/urun/k1300?renk=kirmizi', $firstVariant['variant_product_url']);
        $this->assertSame('list_price', $firstVariant['pricing_policy_type']);
        $this->assertFalse($firstVariant['net_price_warning']);

        $this->assertSame(29.1, $secondVariant['list_price']);
        $this->assertSame(7.0, $secondVariant['variant_stock_quantity']);
        $this->assertSame('https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg', $secondVariant['variant_image_url']);
        $this->assertTrue($secondVariant['image_fallback_used']);
        $this->assertContains('Varyant görseli yok, ana ürün görseli kullanıldı.', $secondVariant['warnings']);
        $this->assertContains('https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg', $secondVariant['variant_gallery_images']);
    }

    public function test_single_variant_product_is_still_normalized_as_sellable_variant(): void
    {
        $source = $this->makePozitronSource();

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'id' => 5,
            'urun_sku' => 'T5',
            'urun_adi' => 'Celik Matara 500 ml',
            'urun_url' => 'https://pozitronpromosyon.com/urun/t5',
            'kategoriler' => [['id' => 8, 'ad' => 'Termos', 'slug' => 'termos']],
            'urun_gorselleri' => ['https://pozitronpromosyon.com/uploads/t5-parent.jpg'],
            'urun_fiyati' => '12.50',
            'kdv_orani' => '20',
            'varyasyonlar' => [[
                'varyasyon_id' => 501,
                'stok_kodu' => 'T5-BEYAZ',
                'renk' => 'Beyaz',
                'stok_adedi' => 42,
                'fiyat' => '12.50',
                'gorseller' => ['https://pozitronpromosyon.com/uploads/t5-beyaz.jpg'],
                'urun_url' => 'https://pozitronpromosyon.com/urun/t5?renk=beyaz',
            ]],
        ]]);

        $this->assertSame(1, $preview['stats']['product_count']);
        $this->assertSame(1, $preview['stats']['variant_count']);
        $this->assertSame('T5-BEYAZ', $preview['variants'][0]['variant_stock_code']);
        $this->assertSame('USD', $preview['variants'][0]['currency']);
        $this->assertSame(42.0, $preview['variants'][0]['variant_stock_quantity']);
    }

    public function test_flat_product_without_variations_is_exposed_as_sellable_flat_variant(): void
    {
        $source = $this->makePozitronSource();

        $preview = app(PreviewParserService::class)->previewSource($source, [[
            'id' => 9,
            'urun_sku' => 'FLAT-9',
            'urun_adi' => 'Tekli Defter',
            'urun_url' => 'https://pozitronpromosyon.com/urun/flat-9',
            'kategoriler' => [['id' => 3, 'ad' => 'Defter', 'slug' => 'defter']],
            'urun_gorselleri' => ['https://pozitronpromosyon.com/uploads/flat-9.jpg'],
            'urun_fiyati' => '4.99',
            'kdv_orani' => '20',
            'varyasyonlar' => [],
        ]]);

        $this->assertCount(1, $preview['products']);
        $this->assertCount(1, $preview['variants']);
        $this->assertSame('FLAT-9', $preview['variants'][0]['variant_stock_code']);
        $this->assertSame(4.99, $preview['variants'][0]['list_price']);
        $this->assertContains('Bu üründe varyasyon listesi boş geldiği için flat/satılabilir ürün olarak değerlendirildi.', $preview['variants'][0]['warnings']);
    }

    public function test_demo_fallback_is_not_reported_as_live_source_for_pozitron_profile(): void
    {
        $source = $this->makePozitronSource();

        $preview = app(PreviewParserService::class)->previewSource($source);

        $this->assertSame('demo_fallback', $preview['source_mode']);
        $this->assertSame('POZITRON_JSON', $preview['profile_key']);
        $this->assertSame('USD', $preview['products'][0]['currency']);
        $this->assertSame('list_price', $preview['products'][0]['pricing_policy_type']);
        $this->assertFalse($preview['products'][0]['net_price_warning']);
    }

    private function makePozitronSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Pozitron Promosyon',
            'code' => 'POZITRON-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Pozitron JSON',
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'source_profile_template' => 'POZITRON_JSON',
                'profile_key' => 'POZITRON',
                'currency' => 'USD',
                'pricing_policy_type' => 'list_price',
                'net_price_warning' => false,
            ],
        ]);
    }
}
