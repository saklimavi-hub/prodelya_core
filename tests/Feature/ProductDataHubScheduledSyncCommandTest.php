<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubScheduledSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_command_only_runs_visible_sources(): void
    {
        $realSource = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareFixtureForSource($realSource, $this->etkinFixtureXml(), 'urun', 'daily');

        $tempSupplier = Supplier::query()->create([
            'name' => 'Tmp Source',
            'code' => 'TMP-CMD-' . uniqid(),
            'status' => 'active',
        ]);

        $tempSource = SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Tmp XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => [
                'profile_key' => 'TMP-CMD-DEMO',
                'sync_policy' => ['sync_frequency' => 'daily'],
            ],
        ]);

        $this->artisan('product-data-hub:sync-sources', ['--frequency' => 'daily', '--dry-run' => true])
            ->expectsOutputToContain('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.')
            ->assertSuccessful();

        $this->assertDatabaseHas('product_data_hub_sync_runs', ['supplier_source_id' => $realSource->id]);
        $this->assertDatabaseMissing('product_data_hub_sync_runs', ['supplier_source_id' => $tempSource->id]);
    }

    public function test_command_filters_by_frequency(): void
    {
        $dailySource = $this->findSourceBySupplierCode('ETKIN');
        $weeklySource = $this->findSourceBySupplierCode('AKDENIZ');

        $this->prepareFixtureForSource($dailySource, $this->etkinFixtureXml(), 'urun', 'daily');
        $this->prepareFixtureForSource($weeklySource, $this->akdenizFixtureXml(), 'RECORD', 'weekly');

        $this->artisan('product-data-hub:sync-sources', ['--frequency' => 'daily', '--dry-run' => true])
            ->expectsOutputToContain('frekans uygun değil')
            ->assertSuccessful();

        $this->assertDatabaseHas('product_data_hub_sync_runs', ['supplier_source_id' => $dailySource->id]);
        $this->assertDatabaseMissing('product_data_hub_sync_runs', ['supplier_source_id' => $weeklySource->id]);
    }

    public function test_dry_run_generates_report_without_writing_products(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareFixtureForSource($source, $this->etkinFixtureXml(), 'urun', 'daily');

        $this->artisan('product-data-hub:sync-sources', ['--source' => $source->id, '--dry-run' => true])
            ->expectsOutputToContain('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.')
            ->assertSuccessful();

        $run = ProductDataHubSyncRun::query()->where('supplier_source_id', $source->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($run->report_payload, 'dry_run'));
        $this->assertDatabaseCount('supplier_products_raw', 0);
        $this->assertDatabaseCount('standard_products', 0);
        $this->assertDatabaseCount('tenant_catalog_products', 0);
    }

    public function test_command_can_auto_build_and_project(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();

        $this->prepareFixtureForSource($source, $this->etkinFixtureXml(), 'urun', 'daily');
        $this->grantTenantAccess($tenant, $source);
        $this->mapCategory($source, 'Kalemler', $category->id);

        $this->artisan('product-data-hub:sync-sources', ['--source' => $source->id])
            ->assertSuccessful();

        $standardProduct = StandardProduct::query()->firstOrFail();
        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
        ]);
    }

    public function test_command_respects_no_build_and_no_project_options(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();

        $this->prepareFixtureForSource($source, $this->etkinFixtureXml(), 'urun', 'daily');
        $this->grantTenantAccess($tenant, $source);
        $this->mapCategory($source, 'Kalemler', $category->id);

        $this->artisan('product-data-hub:sync-sources', ['--source' => $source->id, '--no-build' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('standard_products', 0);
        $this->assertDatabaseCount('tenant_catalog_products', 0);

        $this->artisan('product-data-hub:sync-sources', ['--source' => $source->id, '--no-project' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('standard_products', 1);
        $this->assertDatabaseCount('tenant_catalog_products', 0);
    }

    public function test_one_source_can_fail_while_others_continue(): void
    {
        $successSource = $this->findSourceBySupplierCode('ETKIN');
        $failedSource = $this->findSourceBySupplierCode('AKDENIZ');

        $this->prepareFixtureForSource($successSource, $this->etkinFixtureXml(), 'urun', 'daily');
        $failedSource->forceFill([
            'config' => array_merge((array) ($failedSource->config ?? []), [
                'source_file_path' => storage_path('framework/testing/missing-feed.xml'),
                'sync_policy' => array_merge((array) data_get($failedSource->config, 'sync_policy', []), [
                    'sync_frequency' => 'daily',
                ]),
            ]),
            'url' => null,
            'status' => 'active',
        ])->save();

        $this->artisan('product-data-hub:sync-sources', ['--frequency' => 'daily'])
            ->expectsOutputToContain('başarısız')
            ->assertSuccessful();

        $this->assertDatabaseHas('product_data_hub_sync_runs', [
            'supplier_source_id' => $successSource->id,
        ]);
        $this->assertDatabaseHas('product_data_hub_sync_runs', [
            'supplier_source_id' => $failedSource->id,
            'status' => 'failed',
        ]);
    }

    public function test_missing_location_source_is_skipped_with_turkish_message(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $source->forceFill([
            'url' => null,
            'config' => array_merge((array) ($source->config ?? []), [
                'source_file_path' => null,
                'sync_policy' => array_merge((array) data_get($source->config, 'sync_policy', []), [
                    'sync_frequency' => 'daily',
                ]),
            ]),
            'status' => 'active',
        ])->save();

        $this->artisan('product-data-hub:sync-sources', ['--frequency' => 'daily'])
            ->expectsOutputToContain('Kaynak URL bilgisi eksik olduğu için senkronizasyon atlandı.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('product_data_hub_sync_runs', [
            'supplier_source_id' => $source->id,
        ]);
    }

    public function test_source_list_shows_last_sync_and_next_sync_information(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareFixtureForSource($source, $this->etkinFixtureXml(), 'urun', 'daily');

        $this->artisan('product-data-hub:sync-sources', ['--source' => $source->id])
            ->assertSuccessful();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources');

        $response->assertOk();
        $response->assertSeeText('Tedarikçi Akışları');
        $response->assertSeeText('Detaya Git');
        $response->assertSeeText('Son Sync');
        $response->assertSeeText('Abone Firma Ürün Listesi');
        $response->assertSeeText('Ürünleri Senkronize Et');
    }

    public function test_dry_run_ui_message_and_sync_report_badge_are_visible(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareFixtureForSource($source, $this->etkinFixtureXml(), 'urun', 'daily');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->followingRedirects()
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync", [
                'dry_run' => '1',
            ]);

        $response->assertOk();
        $response->assertSeeText('Bu işlem test çalıştırmasıdır, ürün/stok/fiyat verisi değiştirilmedi.');

        $reportResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/sync-reports');

        $reportResponse->assertOk();
        $reportResponse->assertSeeText('Dry-run');
        $reportResponse->assertSeeText('Güncelleme ayarı için otomatik saatlik/günlük/haftalık çalışmaların sunucuda Laravel scheduler ile aktif olması gerekir.');
    }

    public function test_source_list_shows_build_waiting_warning_when_staging_exists_without_standard_product(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');

        SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'ET-BEKLE-001',
            'supplier_product_code' => 'ET-BEKLE-001',
            'product_name' => 'Build Bekleyen Ürün',
            'source_name' => 'Build Bekleyen Ürün',
            'import_hash' => 'build-bekleyen-001',
            'sync_status' => 'staged',
            'normalized_payload' => [
                'list_price' => 19.90,
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources?filter=all');

        $response->assertOk();
        $response->assertSeeText('Detaya Git');

        $detailResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));

        $detailResponse->assertOk();
        $detailResponse->assertSeeText('1 hazırlık kaydı');
        $detailResponse->assertSeeText('Ürün Havuzu');
    }

    public function test_source_list_shows_projection_waiting_warning_when_standard_product_exists_without_projection(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');

        $rawProduct = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'AK-BEKLE-001',
            'supplier_product_code' => 'AK-BEKLE-001',
            'product_name' => 'Projection Bekleyen Ürün',
            'source_name' => 'Projection Bekleyen Ürün',
            'import_hash' => 'projection-bekleyen-001',
            'sync_status' => 'processed',
            'normalized_payload' => [
                'list_price' => 89.50,
            ],
        ]);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_product_raw_id' => $rawProduct->id,
            'standard_product_code' => 'AK-BEKLE-001',
            'sku' => 'AK-BEKLE-001',
            'product_name' => 'Projection Bekleyen Ürün',
            'name' => 'Projection Bekleyen Ürün',
            'slug' => 'projection-bekleyen-urun',
            'currency' => 'TL',
            'min_purchase_price' => 89.50,
            'max_purchase_price' => 89.50,
            'total_stock_quantity' => 15,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'raw_product_id' => $rawProduct->id,
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 89.50,
                ],
            ],
        ]);

        $rawProduct->update([
            'standard_product_id' => $standardProduct->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources?filter=all');

        $response->assertOk();
        $response->assertSeeText('Detaya Git');

        $detailResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));

        $detailResponse->assertOk();
        $detailResponse->assertSeeText('Bu kaynakta ürünler hazır, ancak Abone Firma kataloğuna henüz yansıtılmamış kayıtlar var.');
    }

    private function findSourceBySupplierCode(string $code): SupplierSource
    {
        return SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function prepareFixtureForSource(SupplierSource $source, string $content, string $nodePath, string $frequency): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-command-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = $nodePath;
        $config['sync_auto_build'] = true;
        $config['sync_auto_project_to_tenant_catalog'] = true;
        $config['sync_policy'] = array_merge((array) ($config['sync_policy'] ?? []), [
            'sync_frequency' => $frequency,
            'sync_auto_build' => true,
            'sync_auto_project_to_tenant_catalog' => true,
            'sync_block_on_missing_category' => true,
            'sync_block_on_missing_price' => false,
            'sync_block_on_conflict_category' => true,
            'sync_allow_warning_products_to_catalog' => true,
        ]);

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function grantTenantAccess(TenantAccount $tenant, SupplierSource $source): void
    {
        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $source->supplier_id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'visible_in_catalog' => true,
                'can_use_in_quotes' => true,
                'can_request_purchase' => true,
                'price_multiplier' => 1,
                'safe_stock_quantity' => 0,
                'export_allowed' => false,
            ]
        );
    }

    private function mapCategory(SupplierSource $source, string $supplierCategory, int $standardCategoryId): void
    {
        $category = StandardCategory::query()->findOrFail($standardCategoryId);

        SupplierCategoryMapping::query()->updateOrCreate(
            [
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'source_category' => $supplierCategory,
            ],
            [
                'standard_category_id' => $standardCategoryId,
                'target_category' => $category->full_path ?: $category->name,
                'mapping_status' => 'approved',
                'decision_type' => 'map',
                'confidence_score' => 100,
                'is_active' => true,
            ]
        );
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
}
