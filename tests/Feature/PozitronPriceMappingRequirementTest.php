<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\PreviewParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PozitronPriceMappingRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiyat_field_is_enough_for_list_price(): void
    {
        $preview = app(PreviewParserService::class)->previewSource($this->makeSource(), [[
            'id' => 10,
            'urun_sku' => 'PZ-10',
            'urun_adi' => 'Pozitron Ürün',
            'urun_url' => 'https://pozitron.example.test/urun/pz-10',
            'kategoriler' => [['id' => 1, 'ad' => 'Kategori', 'slug' => 'kategori']],
            'varyasyonlar' => [[
                'varyasyon_id' => 100,
                'stok_kodu' => 'PZ-10-KRM',
                'renk' => 'kirmizi',
                'fiyat' => '7.75',
            ]],
        ]]);

        $variant = $preview['variants'][0];

        $this->assertSame(7.75, $variant['list_price']);
        $this->assertSame('USD', $variant['currency']);
        $this->assertSame('list_price', $variant['pricing_policy_type']);
    }

    public function test_fiyat_normal_field_is_enough_for_list_price(): void
    {
        $preview = app(PreviewParserService::class)->previewSource($this->makeSource(), [[
            'id' => 11,
            'urun_sku' => 'PZ-11',
            'urun_adi' => 'Pozitron Ürün',
            'urun_url' => 'https://pozitron.example.test/urun/pz-11',
            'kategoriler' => [['id' => 1, 'ad' => 'Kategori', 'slug' => 'kategori']],
            'varyasyonlar' => [[
                'varyasyon_id' => 101,
                'stok_kodu' => 'PZ-11-KRM',
                'renk' => 'kirmizi',
                'fiyat_normal' => '8.10',
            ]],
        ]]);

        $variant = $preview['variants'][0];

        $this->assertSame(8.10, $variant['list_price']);
        $this->assertSame('USD', $variant['currency']);
        $this->assertSame('list_price', $variant['pricing_policy_type']);
    }

    public function test_missing_both_price_fields_creates_warning(): void
    {
        $preview = app(PreviewParserService::class)->previewSource($this->makeSource(), [[
            'id' => 12,
            'urun_sku' => 'PZ-12',
            'urun_adi' => 'Pozitron Ürün',
            'urun_url' => 'https://pozitron.example.test/urun/pz-12',
            'kategoriler' => [['id' => 1, 'ad' => 'Kategori', 'slug' => 'kategori']],
            'varyasyonlar' => [[
                'varyasyon_id' => 102,
                'stok_kodu' => 'PZ-12-KRM',
                'renk' => 'kirmizi',
            ]],
        ]]);

        $variant = $preview['variants'][0];

        $this->assertNull($variant['list_price']);
        $this->assertContains('Liste fiyatı eksik.', $variant['warnings']);
    }

    public function test_different_fiyat_and_fiyat_normal_create_warning_without_blocking(): void
    {
        $preview = app(PreviewParserService::class)->previewSource($this->makeSource(), [[
            'id' => 13,
            'urun_sku' => 'PZ-13',
            'urun_adi' => 'Pozitron Ürün',
            'urun_url' => 'https://pozitron.example.test/urun/pz-13',
            'kategoriler' => [['id' => 1, 'ad' => 'Kategori', 'slug' => 'kategori']],
            'varyasyonlar' => [[
                'varyasyon_id' => 103,
                'stok_kodu' => 'PZ-13-KRM',
                'renk' => 'kirmizi',
                'fiyat' => '7.75',
                'fiyat_normal' => '8.25',
            ]],
        ]]);

        $variant = $preview['variants'][0];

        $this->assertSame(7.75, $variant['list_price']);
        $this->assertTrue($variant['price_policy_warning']);
        $this->assertContains('Pozitron varyant fiyat alanlarında fark var; fiyat liste fiyatı olarak kullanıldı.', $variant['warnings']);
        $this->assertSame('USD', $variant['currency']);
        $this->assertSame('list_price', $variant['pricing_policy_type']);
    }

    private function makeSource(): SupplierSource
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
            ],
        ]);
    }
}
