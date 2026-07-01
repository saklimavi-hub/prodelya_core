<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CatalogSearchController;
use App\Models\Package;
use App\Models\ProductDataHubSyncRun;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\SuperAdmin\ProductDataHubLiveReadinessService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductDataHubLiveSourceReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_active_source_inventory_is_summarized_without_secret_leakage(): void
    {
        $source = $this->createLocalXmlSource('inventory.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>ET-0506-L</id>
        <name>Etkin 0506 Lacivert</name>
        <category>Kalemler</category>
        <price>9.20</price>
        <stock>42</stock>
        <images>
            <main>https://example.test/images/etkin-0506-l.jpg</main>
        </images>
    </urun>
</urunler>
XML, [
            'url' => 'https://example.test/feed.xml?token=super-secret-token',
            'last_error' => 'api_key=very-secret-key path=C:\\laragon\\www\\prodelya_core\\storage\\app\\testing\\inventory.xml',
        ]);
        $this->createRequiredMappings($source);
        ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'scheduled',
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(18),
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'report_payload' => ['token' => 'hidden'],
            'error_message' => 'secret=should-not-leak',
        ]);

        $context = app(ProductDataHubLiveReadinessService::class)->buildReadinessContext();

        $this->assertNotEmpty($context['sources']);
        $row = collect($context['sources'])->firstWhere('source_id', $source->id);

        $this->assertNotNull($row);
        $this->assertSame('XML', $row['source_type']);
        $this->assertContains($row['preview']['status_label'], ['Hazır', 'Kontrol Gerekir']);
        $this->assertContains($row['mapping_readiness']['status_label'], ['Hazır', 'Kontrol Gerekir']);

        $json = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('super-secret-token', (string) $json);
        $this->assertStringNotContainsString('very-secret-key', (string) $json);
        $this->assertStringNotContainsString('C:\\laragon\\www\\prodelya_core', (string) $json);
    }

    public function test_mapping_readiness_reports_missing_required_fields_but_category_gap_does_not_block_price_stock(): void
    {
        $source = $this->createLocalXmlSource('mapping.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>AKD-100</id>
        <name>Akdeniz Deneme Ürünü</name>
        <price>14.50</price>
        <stock>7</stock>
    </urun>
</urunler>
XML);

        SupplierFieldMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_field' => 'id',
            'target_field' => 'supplier_product_code',
            'field_type' => 'direct',
            'mapping_status' => 'mapped',
            'is_required' => true,
        ]);
        SupplierFieldMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_field' => 'name',
            'target_field' => 'product_name',
            'field_type' => 'direct',
            'mapping_status' => 'mapped',
            'is_required' => true,
        ]);
        SupplierFieldMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_field' => 'price',
            'target_field' => 'list_price',
            'field_type' => 'direct',
            'mapping_status' => 'mapped',
            'is_required' => true,
        ]);
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_category_code' => 'KLM',
            'source_category' => 'Kalemler',
            'target_category' => 'Kalemler',
            'mapping_status' => 'pending',
        ]);

        $context = app(ProductDataHubLiveReadinessService::class)->buildReadinessContext();
        $row = collect($context['sources'])->firstWhere('source_id', $source->id);

        $this->assertContains($row['mapping_readiness']['status_label'], ['Hazır', 'Kontrol Gerekir', 'Kritik Eksik']);
        $this->assertContains($row['mapping_readiness']['fields']['price']['status_label'], ['Hazır', 'Kontrol Gerekir']);
        $this->assertContains($row['mapping_readiness']['fields']['stock']['status_label'], ['Hazır', 'Kontrol Gerekir']);
        $this->assertSame('Kontrol Gerekir', $row['category_mapping']['status_label']);
    }

    public function test_local_preview_fallback_does_not_mutate_raw_tables(): void
    {
        $source = $this->createLocalXmlSource('read-only.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>ILP-1</id>
        <name>İlpen Read Only Ürün</name>
        <price>22.00</price>
        <stock>5</stock>
    </urun>
</urunler>
XML);

        $this->createRequiredMappings($source);
        $rawBefore = \App\Models\SupplierProductRaw::query()->count();

        $context = app(ProductDataHubLiveReadinessService::class)->buildReadinessContext();

        $this->assertSame($rawBefore, \App\Models\SupplierProductRaw::query()->count());
        $row = collect($context['sources'])->firstWhere('source_id', $source->id);
        $this->assertContains($row['preview']['status_label'], ['Hazır', 'Kontrol Gerekir']);
    }

    public function test_sellable_truth_matches_catalog_and_quote_search_price(): void
    {
        [$tenant, $source] = $this->createCatalogTruthFixture();

        $catalogRows = app(TenantCatalogListRowQueryService::class)
            ->paginate($tenant, ['search' => 'ET-0506-L'], Request::create('/admin/catalog', 'GET'), 'products');
        $catalogRow = collect($catalogRows->items())->first(fn ($row) => ($row->product_code ?? null) === 'ET-0506-L');

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => 'ET-0506-L']);
        $request->attributes->set('current_tenant', $tenant);
        $quoteResults = app(CatalogSearchController::class)->search($request)->getData(true);
        $quoteRow = collect($quoteResults)->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');

        $this->assertNotNull($catalogRow);
        $this->assertNotNull($quoteRow);
        $this->assertSame(9.2, round((float) ($catalogRow->display_price ?? 0), 2));
        $this->assertSame(9.2, round((float) ($quoteRow['display_price'] ?? 0), 2));

        $context = app(ProductDataHubLiveReadinessService::class)->buildReadinessContext();
        $this->assertContains($context['truth_smoke']['status'], ['healthy', 'warning', 'unknown']);
        $this->assertTrue(collect($context['truth_smoke']['rows'])->contains(
            fn (array $row): bool => ($row['product_code'] ?? null) === 'ET-0506-L' && ($row['price_match'] ?? false) === true
        ));
    }

    public function test_supplier_access_followup_warning_is_added_to_dashboard_action_queue(): void
    {
        [, $source] = $this->createCatalogTruthFixture(withCatalogProjection: false);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertTrue(collect($context['action_queue']['today'])->contains(
            fn (array $item): bool => $item['source'] === 'product_data_hub'
                && str_contains($item['title'], 'Tedarikçi erişimi sonrası kontrol')
        ));
    }

    public function test_product_hub_live_readiness_checklist_is_documented(): void
    {
        $content = file_get_contents(base_path('docs/production-go-live-checklist.md'));

        $this->assertStringContainsString('Her aktif tedarikçi için canlı önizleme read-only olarak doğrulandı mı?', $content);
        $this->assertStringContainsString('ProductHubSellableTruthService ile katalog ve teklif fiyatı aynı truth zincirinden okunuyor mu?', $content);
        $this->assertStringContainsString('Product Data Hub zamanlayıcı heartbeat sinyali dashboard’da görünüyor mu?', $content);
    }

    private function createLocalXmlSource(string $fileName, string $xml, array $overrides = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $overrides['supplier_name'] ?? 'Etkin 0506',
            'code' => $overrides['supplier_code'] ?? ('ETKIN-' . uniqid()),
            'status' => 'active',
        ]);

        $fixturePath = storage_path('app/testing/' . $fileName);
        if (!is_dir(dirname($fixturePath))) {
            mkdir(dirname($fixturePath), 0777, true);
        }

        file_put_contents($fixturePath, $xml);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => $overrides['source_type'] ?? 'xml',
            'source_name' => $overrides['source_name'] ?? 'Etkin XML',
            'url' => $overrides['url'] ?? 'https://example.test/feed.xml',
            'status' => $overrides['status'] ?? 'active',
            'last_error' => $overrides['last_error'] ?? null,
            'config' => array_merge([
                'profile_key' => 'CUSTOM',
                'format' => 'xml',
                'product_node_path' => 'urun',
                'source_file_path' => $fixturePath,
                'sync_policy' => ['sync_frequency' => 'daily'],
            ], $overrides['config'] ?? []),
        ]);
    }

    private function createRequiredMappings(SupplierSource $source): void
    {
        foreach ([
            'id' => 'supplier_product_code',
            'name' => 'product_name',
            'price' => 'list_price',
            'stock' => 'stock_quantity',
            'uid' => 'supplier_product_id',
            'category' => 'supplier_category_name',
            'image' => 'image_url',
        ] as $sourceField => $targetField) {
            SupplierFieldMapping::query()->create([
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'source_field' => $sourceField,
                'target_field' => $targetField,
                'field_type' => 'direct',
                'mapping_status' => 'mapped',
                'is_required' => in_array($targetField, ['supplier_product_code', 'product_name', 'list_price', 'supplier_product_id'], true),
            ]);
        }
    }

    /**
     * @return array{0: TenantAccount, 1: SupplierSource}
     */
    private function createCatalogTruthFixture(bool $withCatalogProjection = true): array
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();
        $tenant = TenantAccount::query()->create([
            'name' => 'Truth Tenant',
            'legal_name' => 'Truth Tenant A.Ş.',
            'slug' => 'truth-tenant-' . uniqid(),
            'status' => 'active',
            'package_key' => $package->key,
            'panel_subdomain' => 'truth-' . uniqid(),
        ]);

        $source = $this->createLocalXmlSource('truth.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <id>ET-0506-L</id>
        <name>Etkin 0506 Lacivert</name>
        <category>Kalemler</category>
        <price>9.20</price>
        <stock>12</stock>
    </urun>
</urunler>
XML, ['supplier_name' => 'Etkin 0506']);
        $this->createRequiredMappings($source);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $source->supplier_id,
            'is_active' => true,
            'granted_at' => now(),
            'can_view_products' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
        ]);

        $standardProduct = \App\Models\StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_product_raw_id' => null,
            'sku' => 'ET-0506',
            'standard_product_code' => 'ET-0506',
            'name' => 'Etkin 0506',
            'product_name' => 'Etkin 0506',
            'base_product_name' => 'Etkin 0506',
            'visible_in_catalog' => true,
            'is_active' => true,
            'min_purchase_price' => 9.2,
            'total_stock_quantity' => 12,
            'source_summary' => [['supplier_id' => $source->supplier_id, 'supplier_source_id' => $source->id]],
        ]);

        \App\Models\SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_product_id' => $standardProduct->id,
            'source_product_id' => 'ET-0506',
            'supplier_product_code' => 'ET-0506',
            'product_name' => 'Etkin 0506',
            'source_name' => 'Etkin 0506',
            'purchase_price' => 9.2,
            'stock_quantity' => 12,
            'normalized_payload' => ['list_price' => 9.2, 'stock_quantity' => 12],
            'sync_status' => 'processed',
        ]);

        $standardVariant = \App\Models\StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => 'ET-0506-L',
            'variant_name' => 'Lacivert',
            'stock_quantity' => 12,
            'min_purchase_price' => 9.2,
            'visible_in_catalog' => true,
            'is_active' => true,
        ]);

        if ($withCatalogProjection) {
            $catalogProduct = TenantCatalogProduct::query()->create([
                'tenant_account_id' => $tenant->id,
                'standard_product_id' => $standardProduct->id,
                'tenant_sku' => 'ET-0506',
                'name' => 'Etkin 0506',
                'product_code' => 'ET-0506',
                'product_name' => 'Etkin 0506',
                'display_price' => 9.2,
                'currency' => 'TL',
                'total_stock_quantity' => 12,
                'supplier_stock_quantity' => 12,
                'visible_in_catalog' => true,
                'visible_in_quote' => true,
                'catalog_status' => 'ready',
                'is_active' => true,
                'local_stock_priority' => false,
                'source_summary' => [[
                    'supplier_id' => $source->supplier_id,
                    'supplier_source_id' => $source->id,
                    'supplier_name' => $source->supplier->name,
                ]],
                'meta' => ['projection_status' => 'ready'],
            ]);

            TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $catalogProduct->id,
                'standard_product_variant_id' => $standardVariant->id,
                'variant_code' => 'ET-0506-L',
                'variant_name' => 'Lacivert',
                'display_price' => 9.2,
                'currency' => 'TL',
                'stock_quantity' => 12,
                'supplier_stock_quantity' => 12,
                'visible_in_catalog' => true,
                'is_active' => true,
                'source_summary' => [[
                    'supplier_id' => $source->supplier_id,
                    'supplier_source_id' => $source->id,
                    'supplier_name' => $source->supplier->name,
                ]],
                'meta' => [
                    'quote_search_visible' => true,
                    'parent_product_name' => 'Etkin 0506',
                ],
            ]);
        }

        return [$tenant, $source];
    }
}
