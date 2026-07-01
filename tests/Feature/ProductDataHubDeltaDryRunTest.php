<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\User;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubDeltaDryRunTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->actingAs($this->adminUser);
    }

    public function test_delta_dry_run_command_reports_without_db_writes_or_build_projection(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(), 'RECORD', 'daily');

        SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'PB-4007',
            'supplier_product_code' => 'PB-4007',
            'product_name' => 'Wireless Mousepad',
            'source_name' => 'Wireless Mousepad',
            'supplier_category_name' => 'Powerbanklar',
            'source_category' => 'Powerbanklar',
            'image_url' => 'https://example.test/pb-old.jpg',
            'stock_quantity' => 100,
            'source_stock' => 100,
            'source_price' => 200,
            'source_currency' => 'TL',
            'normalized_payload' => ['list_price' => 200, 'currency' => 'TL'],
            'import_hash' => 'existing-pb-4007',
            'sync_status' => 'processed',
        ]);
        SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'OLD-EXTRA',
            'supplier_product_code' => 'OLD-EXTRA',
            'product_name' => 'Eski Ürün',
            'source_name' => 'Eski Ürün',
            'supplier_category_name' => 'Kalemler',
            'source_category' => 'Kalemler',
            'image_url' => 'https://example.test/old.jpg',
            'stock_quantity' => 10,
            'source_stock' => 10,
            'source_price' => 20,
            'source_currency' => 'TL',
            'normalized_payload' => ['list_price' => 20, 'currency' => 'TL'],
            'import_hash' => 'existing-old-extra',
            'sync_status' => 'processed',
        ]);

        $this->artisan('product-data-hub:sync-sources', [
            '--source' => $source->id,
            '--mode' => 'delta',
            '--dry-run' => true,
            '--no-project' => true,
        ])
            ->expectsOutputToContain('delta dry-run')
            ->assertSuccessful();

        $this->assertDatabaseCount('product_data_hub_sync_runs', 0);
        $this->assertDatabaseCount('standard_products', 0);
        $this->assertDatabaseCount('tenant_catalog_products', 0);
        $this->assertSame(2, SupplierProductRaw::query()->count());
        $this->assertSame(200.0, (float) data_get(
            SupplierProductRaw::query()->where('supplier_product_code', 'PB-4007')->firstOrFail()->normalized_payload,
            'list_price'
        ));
    }

    public function test_delta_dry_run_service_marks_identity_risky_source_as_not_apply_candidate(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareFixtureForSource($source, $this->identityRiskFixtureXml(), 'urun', 'daily');

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ]);

        $run = $result['run'];

        $this->assertFalse($run->exists);
        $this->assertFalse((bool) data_get($run->report_payload, 'delta_summary.apply_candidate'));
        $this->assertSame('Riskli', data_get($run->report_payload, 'delta_summary.identity.label'));
        $this->assertGreaterThan(0, (int) data_get($run->report_payload, 'delta_summary.counts.blocked_identity_missing', 0));
        $this->assertDatabaseCount('product_data_hub_sync_runs', 0);
    }

    private function findSourceBySupplierCode(string $code): SupplierSource
    {
        return SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function prepareFixtureForSource(SupplierSource $source, string $content, string $nodePath, string $frequency): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-delta-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = $nodePath;
        $config['sync_policy'] = array_merge((array) ($config['sync_policy'] ?? []), [
            'sync_frequency' => $frequency,
        ]);

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
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
        <urun_id>9001</urun_id>
        <urunkodu>PB-4007</urunkodu>
        <urunattr_id>A1</urunattr_id>
        <urunattrgr>PB-4007</urunattrgr>
        <urunattradi>Siyah</urunattradi>
        <urunadi>Wireless Mousepad</urunadi>
        <pure_prodname>Wireless Mousepad</pure_prodname>
        <listefiyati>986,00</listefiyati>
        <listefiyatkapali>542,30</listefiyatkapali>
        <iskonto>15</iskonto>
        <netfiyat>542,30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>2531.0</stokmiktar>
        <stokresim>https://example.test/pb-4007-stok.jpg</stokresim>
        <urunresim>https://example.test/pb-4007-urun.jpg</urunresim>
        <kategori>Powerbanklar</kategori>
    </RECORD>
</ROOT>
XML;
    }

    private function identityRiskFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_id></urun_id>
        <urun_kodu></urun_kodu>
        <urun_grupkodu></urun_grupkodu>
        <urun_adi>Kodsuz Ürün</urun_adi>
        <urun_resim>https://example.test/kodsuz.jpg</urun_resim>
        <urun_kategori>Kalemler</urun_kategori>
        <urun_stok>25</urun_stok>
        <urun_fiyat>9.90</urun_fiyat>
    </urun>
</urunler>
XML;
    }
}
