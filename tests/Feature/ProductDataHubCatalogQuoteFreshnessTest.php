<?php

namespace Tests\Feature;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use App\Http\Controllers\Admin\CatalogSearchController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductDataHubCatalogQuoteFreshnessTest extends TestCase
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

    public function test_etkin_variant_price_and_stock_flow_updates_raw_standard_tenant_catalog_and_quote_search(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Saklimavi Test',
            'legal_name' => 'Saklimavi Test Ltd.',
            'slug' => 'saklimavi-test',
            'panel_subdomain' => 'saklimavi-test',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);
        $source = $this->makeEtkinSource();
        $this->prepareJsonFixtureForSource($source, [
            [
                'urun_id' => 4516,
                'kategori_id' => 83,
                'kategori_adi' => 'Plastik Kalemler',
                'urun_kodu' => '0506-L',
                'urun_kodgrup' => '0506',
                'urun_isim' => 'Plastik Kalem',
                'urun_baslik' => '0506-L Plastik Kalem',
                'urun_aciklama' => 'Test kalem',
                'urun_renk' => 'Lacivert',
                'urun_ebat' => '',
                'toplam_stok' => 49800,
                'urun_fiyat' => '9.200',
                'urun_fiyat_virgul' => '9,200',
                'fiyat_kdv' => 20,
                'kirmiziurun' => 0,
                'urun_trase' => 'https://example.test/0506.pdf',
                'katalog_sayfa_no' => 177,
                'resim1' => 'https://example.test/0506_lacivert.jpg',
                'md5' => 'hash-0506-l',
            ],
        ]);

        $rawProduct = $this->createEtkinRawProduct($source, [
            'supplier_product_id' => 4516,
            'supplier_product_code' => '0506-L',
            'supplier_group_code' => '0506',
            'product_name' => 'Plastik Kalem',
            'supplier_category_name' => 'Plastik Kalemler',
            'image_url' => 'https://example.test/0506-parent-old.jpg',
            'stock_quantity' => 107500,
            'purchase_price' => 6.5,
            'currency' => 'TL',
            'source_price' => 6.5,
            'source_stock' => 107500,
            'normalized_payload' => [
                'list_price' => 6.5,
                'purchase_price' => 6.5,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => 107500,
                'total_variant_stock_quantity' => 107500,
            ],
            'import_hash' => 'et-0506-parent',
        ]);

        $rawVariant = $this->createEtkinRawVariant($source, $rawProduct, [
            'parent_supplier_product_id' => 4516,
            'supplier_group_code' => '0506',
            'variant_code' => '0506-L',
            'variant_stock_code' => '0506-L',
            'generated_variant_code' => 'ET-0506-L',
            'variant_name' => '0506-L Plastik Kalem',
            'variant_color' => 'Lacivert',
            'variant_stock_quantity' => 107500,
            'normalized_payload' => [
                'list_price' => 6.5,
                'purchase_price' => 6.5,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'variant_stock_quantity' => 107500,
            ],
            'import_hash' => 'et-0506-l',
        ]);

        $standardProduct = $this->buildStandardProductFromRaw($rawProduct);
        $standardVariant = StandardProductVariant::query()
            ->where('standard_product_id', $standardProduct->id)
            ->where('generated_variant_code', 'ET-0506-L')
            ->firstOrFail();

        $rawVariant->forceFill([
            'standard_product_variant_id' => $standardVariant->id,
        ])->save();

        $this->grantAccess($tenant, $source->supplier_id);

        app(\App\Services\ProductDataHub\TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'supplier_ids' => [$source->supplier_id],
            'standard_product_ids' => [$standardProduct->id],
        ]);

        $catalogProductBefore = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $standardProduct->id)
            ->firstOrFail();
        $catalogVariantBefore = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_variant_id', $standardVariant->id)
            ->firstOrFail();

        $service = app(SupplierSourceSyncService::class);

        $dryRun = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ]);

        $this->assertFalse($dryRun['run']->exists);
        $this->assertTrue((bool) data_get($dryRun['run']->report_payload, 'delta_summary.apply_candidate'));
        $this->assertSame('Güvenilir', data_get($dryRun['run']->report_payload, 'delta_summary.identity.label'));
        $this->assertGreaterThan(0, (int) data_get($dryRun['run']->report_payload, 'delta_summary.counts.price_and_stock_changed', 0));
        $this->assertTrue(collect(data_get($dryRun['run']->report_payload, 'delta_summary.sample_changes', []))
            ->contains(fn ($change) => ($change['identity_key'] ?? null) === 'group-stock:0506:0506-L' && ($change['type'] ?? null) === 'price_and_stock_changed'));
        $this->assertDatabaseMissing('product_data_hub_sync_runs', ['run_type' => 'manual']);

        $apply = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'project_dirty' => true,
        ]);

        $run = $apply['run']->fresh();
        $rawVariant->refresh();
        $standardVariant->refresh();
        $catalogProductBefore->refresh();
        $catalogVariantBefore->refresh();

        $this->assertSame(9.2, (float) data_get($rawVariant->normalized_payload, 'list_price'));
        $this->assertSame(49800.0, (float) $rawVariant->variant_stock_quantity);
        $this->assertSame(9.2, (float) $standardVariant->min_purchase_price);
        $this->assertSame(49800.0, (float) $standardVariant->stock_quantity);
        $this->assertSame(9.2, (float) $catalogProductBefore->display_price);
        $this->assertSame(9.2, (float) $catalogVariantBefore->display_price);
        $this->assertSame(49800.0, (float) $catalogVariantBefore->stock_quantity);
        $this->assertSame(49800.0, (float) $catalogVariantBefore->supplier_stock_quantity);
        $this->assertSame(0.0, (float) $catalogVariantBefore->local_stock_quantity);
        $this->assertSame('dirty', data_get($run->report_payload, 'delta_apply_summary.projection_mode'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'delta_apply_summary.tenant_catalog_variants_updated'));

        $catalogRows = app(\App\Services\TenantCatalog\TenantCatalogListRowQueryService::class)
            ->paginate($tenant, ['search' => '0506-L'], Request::create('/admin/catalog', 'GET'), 'products');
        $row = collect($catalogRows->items())
            ->first(fn ($item) => ($item->product_code ?? null) === 'ET-0506-L');

        $this->assertNotNull($row);
        $this->assertSame(9.2, round((float) ($row->display_price ?? 0), 2));
        $this->assertSame(49800.0, (float) ($row->effective_stock_quantity ?? 0));

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => '0506-L']);
        $request->attributes->set('current_tenant', $tenant);
        /** @var JsonResponse $response */
        $response = app(CatalogSearchController::class)->search($request);
        $results = $response->getData(true);

        $this->assertNotEmpty($results);
        $this->assertTrue(collect($results)->contains(fn (array $item) =>
            ($item['product_code'] ?? null) === 'ET-0506-L'
            && (float) ($item['display_price'] ?? 0) === 9.2
            && (float) ($item['visible_stock_quantity'] ?? 0) === 49800.0
        ));
    }

    private function makeEtkinSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Etkin Freshness Supplier',
            'code' => 'ETKIN-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Etkin Freshness Source',
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'sync_policy' => ['sync_frequency' => 'daily'],
                'enrich_gallery_from_product_page' => true,
            ],
        ]);
    }

    private function prepareJsonFixtureForSource(SupplierSource $source, array $rows): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-freshness-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.json';
        file_put_contents($filePath, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $config = $source->config ?? [];
        $config['source_file_path'] = $filePath;
        $config['format'] = 'json';
        $source->forceFill([
            'url' => null,
            'config' => $config,
        ])->save();
    }

    private function createEtkinRawProduct(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => (string) ($attributes['supplier_product_id'] ?? $attributes['supplier_product_code'] ?? 'ETKIN-RAW'),
            'source_name' => $attributes['product_name'] ?? 'Etkin Product',
            'source_category' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'source_price' => $attributes['source_price'] ?? $attributes['purchase_price'] ?? null,
            'source_currency' => $attributes['currency'] ?? 'TL',
            'source_stock' => $attributes['source_stock'] ?? $attributes['stock_quantity'] ?? null,
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function createEtkinRawVariant(SupplierSource $source, SupplierProductRaw $rawProduct, array $attributes): SupplierProductVariantRaw
    {
        return SupplierProductVariantRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'supplier_product_id' => $rawProduct->supplier_product_id,
            'sync_status' => 'processed',
            'variant_attributes' => [],
        ], $attributes));
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $raw): StandardProduct
    {
        $service = app(StandardProductBuilderService::class);
        $service->buildFromRawProduct($raw);

        return StandardProduct::query()->findOrFail($raw->fresh()->standard_product_id);
    }

    private function grantAccess(TenantAccount $tenant, int $supplierId): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplierId,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
        ]);
    }
}
