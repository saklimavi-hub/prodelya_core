<?php

namespace Tests\Feature;

use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Support\ProductDisplayNameFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductDisplayNameStandardTest extends TestCase
{
    use RefreshDatabase;

    public function test_akdeniz_color_suffix_code_is_cleaned_for_display_name(): void
    {
        $formatted = ProductDisplayNameFormatter::format([
            'product_code' => 'AK-1020-LACIVERT',
            'sku' => 'AK-1020-LACIVERT',
            'raw_product_name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'supplier_name' => 'Akdeniz Promosyon',
        ]);

        $this->assertSame('AK-1020', $formatted['display_code']);
        $this->assertSame('AK-1020-LACIVERT', $formatted['sku']);
        $this->assertSame('AK-1020 Lacivert Metal Tükenmez Rubber Gövde Kalem', $formatted['display_name']);
        $this->assertStringContainsString('AK-1020-LACIVERT', $formatted['search_text']);
    }

    public function test_akdeniz_knife_variant_removes_repeated_name_and_color(): void
    {
        $formatted = ProductDisplayNameFormatter::format([
            'product_code' => 'AK-3008-11-KIRMIZI',
            'raw_product_name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
        ]);

        $this->assertSame('AK-3008-11 Kırmızı 11 Fonksiyonlu Çakı', $formatted['display_name']);
        $this->assertSame(1, substr_count($formatted['display_name'], 'Kırmızı'));
        $this->assertSame(1, substr_count($formatted['display_name'], 'AK-3008-11'));
    }

    public function test_akdeniz_lighter_keeps_clean_sku_in_search_but_not_display_suffix(): void
    {
        $formatted = ProductDisplayNameFormatter::format([
            'product_code' => 'AK-YMN-224-BEYAZ',
            'raw_product_name' => 'Beyaz L-Lighter Midi Taşlı Siboplu Çakmak Beyaz',
        ]);

        $this->assertSame('AK-YMN-224 Beyaz L-Lighter Midi Taşlı Siboplu Çakmak', $formatted['display_name']);
        $this->assertStringContainsString('AK-YMN-224-BEYAZ', $formatted['search_text']);
        $this->assertStringNotContainsString('AK-YMN-224-BEYAZ Beyaz', $formatted['display_name']);
    }

    public function test_other_suppliers_keep_sales_friendly_variant_names(): void
    {
        $this->assertSame('ET-0506-K Plastik Kalem Kırmızı', ProductDisplayNameFormatter::variant(
            'ET-0506-K',
            'Plastik Kalem',
            '0506-K Plastik Kalem',
            'Kırmızı',
            extraCodes: ['0506']
        ));

        $this->assertSame('IL-6556 Yeşil A5 Sert Kapaklı Defter 14,2x21 cm', ProductDisplayNameFormatter::variant(
            'IL-6556',
            'A5 Sert Kapaklı Defter 14,2x21 CM',
            'IL-6556 Yeşil A5 Sert Kapaklı Defter 14,2x21 CM',
            'Yeşil'
        ));

        $this->assertSame('YN-209025 Akdere Taba Tarihli Ajanda 15x21 cm', ProductDisplayNameFormatter::variant(
            'YN-209025',
            'AKDERE TABA TARİHLİ AJANDA 15X21 CM',
            'YN-209025 AKDERE TABA TARİHLİ AJANDA 15X21 CM',
            'Taba'
        ));
    }

    public function test_group_code_stays_in_search_text_but_not_display_name(): void
    {
        $formatted = ProductDisplayNameFormatter::format([
            'product_code' => 'ET-0506-K',
            'sku' => 'ET-0506-K',
            'supplier_group_code' => '0506',
            'raw_product_name' => '0506 Plastik Kalem',
            'color' => 'Kırmızı',
        ]);

        $this->assertSame('ET-0506-K Plastik Kalem Kırmızı', $formatted['display_name']);
        $this->assertStringNotContainsString(' 0506 ', ' ' . $formatted['display_name'] . ' ');
        $this->assertStringContainsString('0506', $formatted['search_text']);
    }

    public function test_audit_display_names_command_is_read_only(): void
    {
        $supplier = Supplier::query()->updateOrCreate(
            ['code' => 'AKDENIZ'],
            [
                'name' => 'Akdeniz Promosyon',
                'status' => 'active',
            ]
        );

        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'AK-1020-LACIVERT',
            'sku' => 'AK-1020-LACIVERT',
            'product_name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_group_code' => '1020',
                'supplier_product_code' => '1020',
            ]],
        ]);
        $before = $product->fresh()->getAttributes();

        $this->artisan('product-data-hub:audit-product-display-names --supplier=AKDENIZ --examples --only-problems')
            ->expectsOutputToContain('Product Data Hub ürün adı audit tamamlandı; veri değiştirilmedi.')
            ->assertSuccessful();

        $after = $product->fresh()->getAttributes();
        $this->assertSame($before['product_name'], $after['product_name']);
        $this->assertSame($before['standard_product_code'], $after['standard_product_code']);
        $this->assertSame($before['source_summary'], $after['source_summary']);
    }

    public function test_audit_command_classifies_rows_and_exports_expected_files(): void
    {
        $supplier = Supplier::query()->updateOrCreate(
            ['code' => 'AKDENIZ'],
            [
                'name' => 'Akdeniz Promosyon',
                'status' => 'active',
            ]
        );

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'AK-1020-LACIVERT',
            'sku' => 'AK-1020-LACIVERT',
            'product_name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'name' => '1020 Turuncu Metal Tükenmez Rubber Gövde Kalem AK-1020-LACIVERT Lacivert',
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => 11,
                'supplier_group_code' => '1020',
                'supplier_product_code' => '1020',
            ]],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'AK-EMPTY',
            'sku' => 'AK-EMPTY',
            'product_name' => 'Belirtilmedi',
            'name' => 'Belirtilmedi',
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => 12,
            ]],
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'AK-LONG',
            'sku' => 'AK-LONG',
            'product_name' => 'Kurumsal Promosyon Etkinliklerinde Kullanıma Uygun Çok Bölmeli Ultra Dayanıklı Organizasyon Seyahat Ofis Masaüstü Seti Ve Aksesuar Çözümü',
            'name' => 'Kurumsal Promosyon Etkinliklerinde Kullanıma Uygun Çok Bölmeli Ultra Dayanıklı Organizasyon Seyahat Ofis Masaüstü Seti Ve Aksesuar Çözümü',
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => 13,
            ]],
        ]);

        $exportDir = storage_path('app/product-data-hub/display-name-audit');
        File::deleteDirectory($exportDir);

        $this->artisan('product-data-hub:audit-product-display-names --supplier=AKDENIZ --export')
            ->expectsOutputToContain('Kritik hata')
            ->expectsOutputToContain('Review gerekli')
            ->expectsOutputToContain('Kabul edilebilir aday')
            ->expectsOutputToContain('Temiz')
            ->assertSuccessful();

        $this->assertFileExists($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_summary.csv');
        $this->assertFileExists($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_critical.csv');
        $this->assertFileExists($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_review.csv');
        $this->assertFileExists($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_examples.json');

        $summaryCsv = File::get($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_summary.csv');
        $this->assertStringContainsString('critical_count', $summaryCsv);
        $this->assertStringContainsString('review_count', $summaryCsv);
        $this->assertStringContainsString('acceptable_candidate_count', $summaryCsv);
        $this->assertStringContainsString('clean_count', $summaryCsv);

        $examplesJson = json_decode(File::get($exportDir . DIRECTORY_SEPARATOR . 'display_name_audit_examples.json'), true);
        $this->assertSame(3, data_get($examplesJson, 'summary.total'));
        $this->assertSame(1, data_get($examplesJson, 'summary.critical_count'));
        $this->assertSame(1, data_get($examplesJson, 'summary.review_count'));
        $this->assertSame(1, data_get($examplesJson, 'summary.acceptable_candidate_count'));
        $this->assertCount(1, data_get($examplesJson, 'supplier_examples', []));
        $this->assertNotEmpty(data_get($examplesJson, 'akdeniz_detailed_audit', []));
    }
}
