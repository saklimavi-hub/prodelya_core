<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProductDataHub\StandardProductBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubAutoSyncProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_sync_auto_builds_and_projects_to_tenant_catalog_and_updates_stock_and_price(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $this->grantTenantAccess($source);
        $this->mapCategory($source, 'Powerbanklar', $category->id);
        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(2531, 986.00), 'RECORD');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync")
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $standardProduct = StandardProduct::query()->where('standard_product_code', 'AK-PB-4007')->firstOrFail();
        $tenantCatalog = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $standardProduct->id)
            ->firstOrFail();

        $this->assertSame(2531.0, (float) $standardProduct->total_stock_quantity);
        $this->assertSame(2531.0, (float) $tenantCatalog->total_stock_quantity);
        $this->assertSame(986.0, (float) data_get($tenantCatalog->meta, 'list_price_snapshot'));

        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(1900, 950.00), 'RECORD');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync")
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $standardProduct->refresh();
        $tenantCatalog->refresh();

        $this->assertSame(1900.0, (float) $standardProduct->total_stock_quantity);
        $this->assertSame(1900.0, (float) $tenantCatalog->total_stock_quantity);
        $this->assertSame(950.0, (float) data_get($tenantCatalog->meta, 'list_price_snapshot'));

        $catalogSearch = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/search?q=PB-4007');

        $catalogSearch->assertOk();
        $catalogSearch->assertJsonFragment([
            'tenant_catalog_product_id' => $tenantCatalog->id,
            'total_stock_quantity' => 1900.0,
            'list_price' => 950.0,
        ]);
        $catalogSearch->assertJsonMissing([
            'group_product_code' => $tenantCatalog->display_code,
        ]);

        $run = ProductDataHubSyncRun::query()->latest('id')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, (int) $run->stock_changed_count);
        $this->assertGreaterThanOrEqual(1, (int) $run->price_changed_count);
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'build.updated_products', 0));
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'projection.updated_products', 0));
    }

    public function test_missing_category_projects_with_fallback_warning_when_policy_is_enabled(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->grantTenantAccess($source);
        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(2531, 986.00), 'RECORD');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync")
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $run = ProductDataHubSyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(0, (int) data_get($run->report_payload, 'projection.blocked_missing_category', 0));

        $standardProduct = StandardProduct::query()->where('standard_product_code', 'AK-PB-4007')->firstOrFail();
        $tenantCatalog = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $standardProduct->id)
            ->firstOrFail();

        $this->assertSame('category_pending', $tenantCatalog->catalog_status);
        $this->assertSame('PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN', data_get($tenantCatalog->meta, 'fallback_category_code'));
        $this->assertContains('Genel kategori henüz bağlanmadı', data_get($tenantCatalog->meta, 'warning_snapshot', []));
    }

    public function test_product_level_category_override_survives_next_sync(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $baseCategory = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $overrideCategory = StandardCategory::query()
            ->where('is_active', true)
            ->whereNotNull('parent_id')
            ->where('id', '!=', $baseCategory->id)
            ->firstOrFail();

        $this->grantTenantAccess($source);
        $this->mapCategory($source, 'Powerbanklar', $baseCategory->id);
        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(2531, 986.00), 'RECORD');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync")
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $rawProduct = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->where('supplier_product_code', 'PB-4007')->firstOrFail();
        $payload = array_merge((array) $rawProduct->normalized_payload, [
            'category_override_standard_category_id' => $overrideCategory->id,
            'category_override_name' => $overrideCategory->full_path ?: $overrideCategory->name,
            'category_override_note' => 'Ürün bazlı test override',
            'category_override_apply_to_rule' => false,
            'category_override_applied_at' => now()->toDateTimeString(),
        ]);

        $rawProduct->forceFill([
            'standard_category_id' => $overrideCategory->id,
            'mapping_status' => 'mapped',
            'normalized_payload' => $payload,
        ])->save();

        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct->fresh('variants'));

        $this->prepareFixtureForSource($source, $this->akdenizFixtureXml(1900, 950.00), 'RECORD');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/sync")
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $rawProduct->refresh();
        $standardProduct = StandardProduct::query()->where('standard_product_code', 'AK-PB-4007')->firstOrFail();

        $this->assertSame($overrideCategory->id, $rawProduct->standard_category_id);
        $this->assertSame($overrideCategory->id, $standardProduct->standard_category_id);
        $this->assertSame($overrideCategory->id, (int) data_get($standardProduct->meta, 'category_override.standard_category_id'));
    }

    public function test_standard_products_empty_state_and_catalog_output_summary_are_visible(): void
    {
        StandardProduct::query()->delete();

        $standardResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products');

        $standardResponse->assertOk();
        $standardResponse->assertSee('Filtreye uyan standart ürün kaydı bulunamadı');

        $catalogResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/catalog-output');

        $catalogResponse->assertOk();
        $catalogResponse->assertSee('Katalog Yayını Özeti');
        $catalogResponse->assertSee('Ürünleri güncelle');
        $catalogResponse->assertSee('Projection boşluğu');
    }

    private function findSourceBySupplierCode(string $code): SupplierSource
    {
        return SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function grantTenantAccess(SupplierSource $source): void
    {
        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
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

    private function prepareFixtureForSource(SupplierSource $source, string $content, string $nodePath): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-auto-sync-fixtures');

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

    private function akdenizFixtureXml(float $stock, float $listPrice): string
    {
        $formattedStock = number_format($stock, 1, '.', '');
        $formattedPrice = number_format($listPrice, 2, ',', '.');

        return <<<XML
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
        <listefiyati>{$formattedPrice}</listefiyati>
        <listefiyatkapali>542,30</listefiyatkapali>
        <iskonto>15</iskonto>
        <netfiyat>542,30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>{$formattedStock}</stokmiktar>
        <stokresim>https://example.test/pb-4007-stok.jpg</stokresim>
        <urunresim>https://example.test/pb-4007-urun.jpg</urunresim>
        <urunresim1>https://example.test/pb-4007-1.jpg</urunresim1>
        <urunresim2>https://example.test/pb-4007-2.jpg</urunresim2>
        <kategori>Powerbanklar</kategori>
    </RECORD>
</ROOT>
XML;
    }
}
