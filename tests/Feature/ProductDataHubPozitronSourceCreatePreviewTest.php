<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\PozitronSourceProvisioningService;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDataHubPozitronSourceCreatePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pozitron_supplier_and_source_are_provisioned_idempotently_with_expected_profile_config(): void
    {
        $service = app(PozitronSourceProvisioningService::class);

        $firstRun = $service->ensureSource();
        $secondRun = $service->ensureSource();

        $source = SupplierSource::query()
            ->with('supplier')
            ->where('source_name', 'Pozitron Promosyon JSON')
            ->firstOrFail();

        $this->assertTrue($firstRun['supplier_created']);
        $this->assertTrue($firstRun['source_created']);
        $this->assertFalse($secondRun['supplier_created']);
        $this->assertFalse($secondRun['source_created']);
        $this->assertSame(1, Supplier::query()->where('code', 'POZITRON')->count());
        $this->assertSame(1, Supplier::query()->where('name', 'Pozitron Promosyon')->count());
        $this->assertSame(1, SupplierSource::query()->where('source_name', 'Pozitron Promosyon JSON')->count());
        $this->assertSame('Pozitron Promosyon', $source->supplier->name);
        $this->assertSame('POZITRON', $source->supplier->code);
        $this->assertSame('Pozitron Promosyon JSON', $source->source_name);
        $this->assertSame('api', $source->source_type);
        $this->assertSame('POZITRON_JSON', data_get($source->config, 'source_profile_template'));
        $this->assertSame('POZITRON', data_get($source->config, 'profile_key'));
        $this->assertNotSame('ETKIN', data_get($source->config, 'profile_key'));
        $this->assertSame('json', data_get($source->config, 'ui_source_type'));
        $this->assertSame('json', data_get($source->config, 'format'));
        $this->assertSame('USD', data_get($source->config, 'currency'));
        $this->assertSame('list_price', data_get($source->config, 'pricing_policy_type'));
        $this->assertFalse((bool) data_get($source->config, 'net_price_warning'));
    }

    public function test_existing_wrong_pozitron_source_is_corrected_without_creating_duplicates(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Pozitron Promosyon',
            'code' => 'POZITRON',
            'status' => 'inactive',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Pozitron Promosyon JSON',
            'url' => PozitronSourceProvisioningService::BACKUP_XML_URL,
            'status' => 'inactive',
            'config' => [
                'profile_key' => 'ETKIN',
                'format' => 'xml',
                'ui_source_type' => 'xml',
                'currency' => 'TL',
                'pricing_policy_type' => 'net_price',
                'net_price_warning' => true,
            ],
        ]);

        $result = app(PozitronSourceProvisioningService::class)->ensureSource();

        $source->refresh();

        $this->assertFalse($result['source_created']);
        $this->assertTrue($result['source_updated']);
        $this->assertSame(1, Supplier::query()->where('code', 'POZITRON')->count());
        $this->assertSame(1, Supplier::query()->where('name', 'Pozitron Promosyon')->count());
        $this->assertSame(1, SupplierSource::query()->where('source_name', 'Pozitron Promosyon JSON')->count());
        $this->assertSame('active', $supplier->fresh()->status);
        $this->assertSame('api', $source->source_type);
        $this->assertSame(PozitronSourceProvisioningService::SOURCE_URL, $source->url);
        $this->assertSame('POZITRON_JSON', data_get($source->config, 'source_profile_template'));
        $this->assertSame('POZITRON', data_get($source->config, 'profile_key'));
        $this->assertSame('USD', data_get($source->config, 'currency'));
        $this->assertSame('list_price', data_get($source->config, 'pricing_policy_type'));
        $this->assertFalse((bool) data_get($source->config, 'net_price_warning'));
    }

    public function test_live_preview_reads_sellable_variants_from_pozitron_json_without_running_import_or_staging(): void
    {
        $service = app(PozitronSourceProvisioningService::class);
        $source = $service->ensureSource()['source'];

        Http::fake([
            PozitronSourceProvisioningService::SOURCE_URL => Http::response(json_encode([
                [
                    'id' => 101,
                    'urun_sku' => 'PZ-BUNDLE',
                    'urun_adi' => 'Bundle Termos',
                    'urun_url' => 'https://pozitronpromosyon.com/urun/pz-bundle',
                    'kategoriler' => [['id' => 11, 'ad' => 'Termos', 'slug' => 'termos']],
                    'urun_gorselleri' => ['https://pozitronpromosyon.com/img/pz-bundle-parent.jpg'],
                    'urun_fiyati' => '15.25',
                    'kdv_orani' => '20',
                    'stok_kaynagi' => ['tip' => 'bundle'],
                    'bilesenler' => [['sku' => 'C1'], ['sku' => 'C2']],
                    'varyasyonlar' => [
                        [
                            'varyasyon_id' => 501,
                            'stok_kodu' => 'PZ-BUNDLE-KRM',
                            'renk' => 'Kırmızı',
                            'stok_adedi' => 8,
                            'fiyat' => '16.10',
                            'gorseller' => ['https://pozitronpromosyon.com/img/pz-bundle-kirmizi.jpg'],
                            'urun_url' => 'https://pozitronpromosyon.com/urun/pz-bundle?renk=kirmizi',
                        ],
                        [
                            'varyasyon_id' => 502,
                            'stok_kodu' => 'PZ-BUNDLE-SYH',
                            'renk' => 'Siyah',
                            'stok_adedi' => 5,
                            'fiyat' => '16.30',
                            'gorseller' => [],
                            'urun_url' => 'https://pozitronpromosyon.com/urun/pz-bundle?renk=siyah',
                        ],
                    ],
                ],
                [
                    'id' => 102,
                    'urun_sku' => 'PZ-SINGLE',
                    'urun_adi' => 'Tek Varyant Kupa',
                    'urun_url' => 'https://pozitronpromosyon.com/urun/pz-single',
                    'kategoriler' => [['id' => 12, 'ad' => 'Kupa', 'slug' => 'kupa']],
                    'urun_gorselleri' => ['https://pozitronpromosyon.com/img/pz-single-parent.jpg'],
                    'urun_fiyati' => '7.50',
                    'kdv_orani' => '20',
                    'varyasyonlar' => [[
                        'varyasyon_id' => 503,
                        'stok_kodu' => 'PZ-SINGLE-BYZ',
                        'renk' => 'Beyaz',
                        'stok_adedi' => 12,
                        'fiyat' => '7.50',
                        'gorseller' => ['https://pozitronpromosyon.com/img/pz-single-beyaz.jpg'],
                        'urun_url' => 'https://pozitronpromosyon.com/urun/pz-single?renk=beyaz',
                    ]],
                ],
                [
                    'id' => 103,
                    'urun_sku' => 'PZ-FLAT',
                    'urun_adi' => 'Flat Defter',
                    'urun_url' => 'https://pozitronpromosyon.com/urun/pz-flat',
                    'kategoriler' => [['id' => 13, 'ad' => 'Defter', 'slug' => 'defter']],
                    'urun_gorselleri' => ['https://pozitronpromosyon.com/img/pz-flat-parent.jpg'],
                    'urun_fiyati' => '4.20',
                    'kdv_orani' => '20',
                    'varyasyonlar' => [],
                ],
            ], JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json']),
        ]);

        $fetchResult = app(SourceFetchService::class)->fetch($source);
        $this->assertTrue($fetchResult['ok']);
        $this->assertSame(200, $fetchResult['status_code']);

        $parserResult = app(SourceParserService::class)->parse($source, (string) $fetchResult['content']);
        $this->assertTrue($parserResult['ok']);
        $this->assertSame('json', $parserResult['content_type']);
        $this->assertSame(3, $parserResult['records_read']);

        $preview = app(PreviewParserService::class)->previewSource($source, $parserResult['rows']);
        $summary = $service->buildPreviewSummary($preview);

        $this->assertSame('live_source', $preview['source_mode']);
        $this->assertNotSame('demo_fallback', $preview['source_mode']);
        $this->assertSame('POZITRON_JSON', $preview['profile_key']);
        $this->assertSame(3, $summary['records_read']);
        $this->assertSame(3, $summary['product_count']);
        $this->assertSame(4, $summary['variant_count']);
        $this->assertSame(1, $summary['multi_variant_product_count']);
        $this->assertSame(2, $summary['single_variant_product_count']);
        $this->assertSame(1, $summary['flat_sellable_fallback_count']);
        $this->assertSame(1, $summary['bundle_component_product_count']);
        $this->assertSame(3, $summary['usd_priced_parent_count']);
        $this->assertSame(4, $summary['usd_priced_variant_count']);
        $this->assertSame(7, $summary['image_present_count']);
        $this->assertSame(2, $summary['image_fallback_used_count']);
        $this->assertSame(3, $summary['category_read_count']);
        $this->assertSame('USD', $preview['products'][0]['currency']);
        $this->assertSame('list_price', $preview['products'][0]['pricing_policy_type']);
        $this->assertFalse((bool) $preview['products'][0]['net_price_warning']);
        $this->assertTrue(collect($preview['variants'])->contains(fn (array $variant) => ($variant['variant_stock_code'] ?? null) === 'PZ-BUNDLE-SYH' && (bool) ($variant['image_fallback_used'] ?? false)));

        $this->assertSame(0, SupplierProductRaw::query()->count());
        $this->assertSame(0, SupplierProductVariantRaw::query()->count());
        $this->assertSame(0, ProductDataHubSyncRun::query()->count());
    }
}
