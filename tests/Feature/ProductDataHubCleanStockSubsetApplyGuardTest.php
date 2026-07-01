<?php

namespace Tests\Feature;

use App\Models\Role;
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
use App\Models\UserRole;
use App\Services\ProductDataHub\DeltaChangeDetectorService;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ProductDataHubCleanStockSubsetApplyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;
    private TenantAccount $demoTenant;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->actingAs($this->adminUser);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_only_clean_stock_dry_run_and_apply_mode_only_process_clean_stock_changes(): void
    {
        $source = $this->makeEtkinSource('ETKIN-CLEAN-STOCK');

        $clean = $this->createMappedVariantProduct($source, [
            'supplier_product_id' => 1100,
            'supplier_product_code' => 'CLEAN-1',
            'supplier_group_code' => 'CLEAN',
            'product_name' => 'Clean Stock Product',
            'variant_code' => 'CLEAN-1',
            'variant_stock_code' => 'CLEAN-1',
            'generated_variant_code' => 'ET-CLEAN-1',
            'variant_name' => 'Clean Stock Product Lacivert',
            'variant_color' => 'Lacivert',
            'stock_quantity' => 75,
            'variant_stock_quantity' => 75,
            'price' => 10.0,
        ], true);

        $priceOnly = $this->createMappedVariantProduct($source, [
            'supplier_product_id' => 1200,
            'supplier_product_code' => 'PRICE-1',
            'supplier_group_code' => 'PRICE',
            'product_name' => 'Price Only Product',
            'variant_code' => 'PRICE-1',
            'variant_stock_code' => 'PRICE-1',
            'generated_variant_code' => 'ET-PRICE-1',
            'variant_name' => 'Price Only Product',
            'variant_color' => 'Siyah',
            'stock_quantity' => 80,
            'variant_stock_quantity' => 80,
            'price' => 15.0,
        ]);

        $priceAndStock = $this->createMappedVariantProduct($source, [
            'supplier_product_id' => 1300,
            'supplier_product_code' => 'BOTH-1',
            'supplier_group_code' => 'BOTH',
            'product_name' => 'Price And Stock Product',
            'variant_code' => 'BOTH-1',
            'variant_stock_code' => 'BOTH-1',
            'generated_variant_code' => 'ET-BOTH-1',
            'variant_name' => 'Price And Stock Product',
            'variant_color' => 'Kırmızı',
            'stock_quantity' => 90,
            'variant_stock_quantity' => 90,
            'price' => 20.0,
        ]);

        $requiredField = $this->createRawProductWithoutMapping($source, [
            'supplier_product_id' => 1400,
            'supplier_product_code' => 'REQ-1',
            'supplier_group_code' => 'REQ',
            'product_name' => 'Required Field Product',
            'stock_quantity' => 60,
            'purchase_price' => 12.0,
            'currency' => 'TL',
            'normalized_payload' => [
                'list_price' => 12.0,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => 60,
                'total_variant_stock_quantity' => 60,
            ],
            'import_hash' => 'req-parent',
        ]);

        $imageCollision = $this->createMappedVariantProduct($source, [
            'supplier_product_id' => 1500,
            'supplier_product_code' => 'IMG-1',
            'supplier_group_code' => 'IMG',
            'product_name' => 'Image Collision Product',
            'variant_code' => 'IMG-1',
            'variant_stock_code' => 'IMG-1',
            'generated_variant_code' => 'ET-IMG-1',
            'variant_name' => 'Image Collision Product',
            'variant_color' => 'Beyaz',
            'stock_quantity' => 40,
            'variant_stock_quantity' => 40,
            'price' => 17.0,
            'image_url' => 'https://example.test/old-image.jpg',
        ]);

        $this->prepareJsonFixtureForSource($source, [
            $this->fixtureRow(1100, 'CLEAN-1', 'CLEAN', 'Clean Stock Product', 'Lacivert', 50, '10.000', 'https://example.test/clean.jpg'),
            $this->fixtureRow(1200, 'PRICE-1', 'PRICE', 'Price Only Product', 'Siyah', 80, '19.000', 'https://example.test/price.jpg'),
            $this->fixtureRow(1300, 'BOTH-1', 'BOTH', 'Price And Stock Product', 'Kırmızı', 45, '21.500', 'https://example.test/both.jpg'),
            $this->fixtureRow(1400, 'REQ-1', 'REQ', 'Required Field Product', 'Gri', 10, '', 'https://example.test/req.jpg'),
            $this->fixtureRow(1500, 'IMG-1', 'IMG', 'Image Collision Product', 'Beyaz', 10, '17.000', 'https://example.test/new-image.jpg'),
        ]);

        $this->mockDeltaDetectorForCleanStockFixture();

        $service = app(SupplierSourceSyncService::class);

        $dryRun = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
            'only_clean_stock' => true,
        ]);

        $dryRunSummary = data_get($dryRun['run']->report_payload, 'delta_summary', []);

        $this->assertSame(1, (int) data_get($dryRunSummary, 'clean_stock_candidates'));
        $this->assertSame(1, (int) data_get($dryRunSummary, 'skipped_required_field_missing'));
        $this->assertSame(1, (int) data_get($dryRunSummary, 'skipped_category_content_image_changed'));
        $this->assertSame(1, (int) data_get($dryRunSummary, 'would_apply_clean_stock'));
        $this->assertSame(1, (int) data_get($dryRunSummary, 'would_project_dirty_products'));
        $this->assertSame(1, (int) data_get($dryRunSummary, 'affected_standard_products_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($dryRunSummary, 'affected_tenant_catalog_variants_count'));

        $apply = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
            'only_clean_stock' => true,
        ]);

        $applySummary = data_get($apply['run']->report_payload, 'delta_apply_summary', []);

        $clean['rawVariant']->refresh();
        $clean['standardVariant']->refresh();
        $clean['tenantCatalogVariant']->refresh();
        $priceOnly['rawVariant']->refresh();
        $priceAndStock['rawVariant']->refresh();
        $imageCollision['rawVariant']->refresh();
        $requiredField->refresh();

        $this->assertSame(50.0, (float) $clean['rawVariant']->variant_stock_quantity);
        $this->assertSame(50.0, (float) $clean['standardVariant']->stock_quantity);
        $this->assertSame(7.0, (float) $clean['tenantCatalogVariant']->local_stock_quantity);
        $this->assertSame(75.0, (float) $clean['tenantCatalogVariant']->supplier_stock_quantity);

        $this->assertSame(80.0, (float) $priceOnly['rawVariant']->variant_stock_quantity);
        $this->assertSame(90.0, (float) $priceAndStock['rawVariant']->variant_stock_quantity);
        $this->assertSame(40.0, (float) $imageCollision['rawVariant']->variant_stock_quantity);
        $this->assertSame(60.0, (float) $requiredField->stock_quantity);

        $this->assertSame(1, (int) data_get($applySummary, 'stock_changed_applied'));
        $this->assertSame(0, (int) data_get($applySummary, 'price_changed_applied'));
        $this->assertSame(0, (int) data_get($applySummary, 'price_and_stock_changed_applied'));
        $this->assertSame(1, (int) data_get($applySummary, 'clean_stock_candidates'));
        $this->assertSame(1, (int) data_get($applySummary, 'would_apply_clean_stock'));
        $this->assertSame(1, (int) data_get($applySummary, 'affected_standard_products_count'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($applySummary, 'affected_tenant_catalog_variants_count'));
    }

    public function test_only_clean_stock_summary_counts_variant_structure_new_missing_and_required_collisions(): void
    {
        $source = $this->makeEtkinSource('ETKIN-SUMMARY-STOCK');
        $service = app(SupplierSourceSyncService::class);

        $context = [
            'products' => collect(),
            'variants' => collect(),
            'existing_products' => collect(),
            'existing_variants' => collect(),
            'counts' => [
                'stock_changed' => 4,
                'variant_structure_changed' => 1,
                'blocked_required_field_missing' => 1,
                'new_variant' => 1,
                'category_changed' => 1,
            ],
            'delta' => [
                'apply_candidate' => true,
                'identity_summary' => ['reliable' => true],
                'flags' => [
                    'feed_degraded' => false,
                    'suspicious_feed_drop' => false,
                    'suspicious_price_jump' => false,
                ],
                'changes' => [
                    ['type' => 'stock_changed', 'identity_key' => 'variant:clean', 'scope' => 'variant'],
                    ['type' => 'stock_changed', 'identity_key' => 'product:structure', 'scope' => 'product'],
                    ['type' => 'variant_structure_changed', 'identity_key' => 'product:structure', 'scope' => 'product'],
                    ['type' => 'stock_changed', 'identity_key' => 'product:req', 'scope' => 'product'],
                    ['type' => 'blocked_required_field_missing', 'identity_key' => 'product:req', 'scope' => 'product'],
                    ['type' => 'stock_changed', 'identity_key' => 'variant:new', 'scope' => 'variant'],
                    ['type' => 'new_variant', 'identity_key' => 'variant:new', 'scope' => 'variant'],
                    ['type' => 'stock_changed', 'identity_key' => 'variant:image', 'scope' => 'variant'],
                    ['type' => 'image_changed', 'identity_key' => 'variant:image', 'scope' => 'variant'],
                ],
            ],
        ];

        $summary = $this->invokeOnlyCleanStockSummary($service, $source, $context);

        $this->assertSame(5, (int) $summary['clean_stock_candidates'] + (int) $summary['skipped_variant_structure_changed'] + (int) $summary['skipped_required_field_missing'] + (int) $summary['skipped_new_or_missing_variant'] + (int) $summary['skipped_category_content_image_changed']);
        $this->assertSame(1, (int) $summary['skipped_variant_structure_changed']);
        $this->assertSame(1, (int) $summary['skipped_required_field_missing']);
        $this->assertSame(1, (int) $summary['skipped_new_or_missing_variant']);
        $this->assertSame(1, (int) $summary['skipped_category_content_image_changed']);
        $this->assertSame(1, (int) $summary['clean_stock_candidates']);
    }

    public function test_only_clean_stock_summary_marks_identity_and_feed_risks_as_skipped(): void
    {
        $source = $this->makeEtkinSource('ETKIN-RISK-STOCK');
        $service = app(SupplierSourceSyncService::class);

        $identityRiskContext = [
            'products' => collect(),
            'variants' => collect(),
            'existing_products' => collect(),
            'existing_variants' => collect(),
            'counts' => ['stock_changed' => 2, 'blocked_identity_missing' => 1],
            'delta' => [
                'apply_candidate' => false,
                'identity_summary' => ['reliable' => false],
                'flags' => [
                    'feed_degraded' => false,
                    'suspicious_feed_drop' => false,
                    'suspicious_price_jump' => false,
                ],
                'changes' => [
                    ['type' => 'stock_changed', 'identity_key' => 'variant:a', 'scope' => 'variant'],
                    ['type' => 'stock_changed', 'identity_key' => 'variant:b', 'scope' => 'variant'],
                ],
            ],
        ];

        $feedRiskContext = [
            'products' => collect(),
            'variants' => collect(),
            'existing_products' => collect(),
            'existing_variants' => collect(),
            'counts' => ['stock_changed' => 3],
            'delta' => [
                'apply_candidate' => true,
                'identity_summary' => ['reliable' => true],
                'flags' => [
                    'feed_degraded' => true,
                    'suspicious_feed_drop' => false,
                    'suspicious_price_jump' => false,
                ],
                'changes' => [
                    ['type' => 'stock_changed', 'identity_key' => 'variant:a', 'scope' => 'variant'],
                    ['type' => 'stock_changed', 'identity_key' => 'variant:b', 'scope' => 'variant'],
                    ['type' => 'stock_changed', 'identity_key' => 'variant:c', 'scope' => 'variant'],
                ],
            ],
        ];

        $identitySummary = $this->invokeOnlyCleanStockSummary($service, $source, $identityRiskContext);
        $feedSummary = $this->invokeOnlyCleanStockSummary($service, $source, $feedRiskContext);

        $this->assertSame(2, (int) $identitySummary['skipped_identity_risk']);
        $this->assertSame(0, (int) $identitySummary['clean_stock_candidates']);
        $this->assertSame(3, (int) $feedSummary['skipped_suspicious_or_feed_risk']);
        $this->assertSame(0, (int) $feedSummary['clean_stock_candidates']);
    }

    private function invokeOnlyCleanStockSummary(SupplierSourceSyncService $service, SupplierSource $source, array $context): array
    {
        $method = new ReflectionMethod(SupplierSourceSyncService::class, 'buildOnlyCleanStockSummary');
        $method->setAccessible(true);

        return $method->invoke($service, $source, $context);
    }

    private function makeEtkinSource(string $code): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Etkin Clean Stock Supplier ' . $code,
            'code' => $code,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Etkin Clean Stock Source ' . $code,
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'sync_policy' => ['sync_frequency' => 'daily'],
                'enrich_gallery_from_product_page' => false,
            ],
        ]);
    }

    private function prepareJsonFixtureForSource(SupplierSource $source, array $rows): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-clean-stock-fixtures');

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

    private function fixtureRow(int $urunId, string $urunKodu, string $grup, string $name, string $renk, int $stok, string $fiyat, string $resim): array
    {
        return [
            'urun_id' => $urunId,
            'kategori_id' => 83,
            'kategori_adi' => 'Plastik Kalemler',
            'urun_kodu' => $urunKodu,
            'urun_kodgrup' => $grup,
            'urun_isim' => $name,
            'urun_baslik' => $name,
            'urun_aciklama' => $name . ' aciklama',
            'urun_renk' => $renk,
            'urun_ebat' => '',
            'toplam_stok' => $stok,
            'urun_fiyat' => $fiyat,
            'urun_fiyat_virgul' => str_replace('.', ',', $fiyat),
            'fiyat_kdv' => 20,
            'kirmiziurun' => 0,
            'urun_trase' => '',
            'katalog_sayfa_no' => 1,
            'resim1' => $resim,
            'md5' => 'hash-' . $urunKodu,
        ];
    }

    private function createMappedVariantProduct(SupplierSource $source, array $attributes, bool $withTenantCatalog = false): array
    {
        $rawProduct = $this->createRawProductWithoutMapping($source, [
            'supplier_product_id' => $attributes['supplier_product_id'],
            'supplier_product_code' => $attributes['supplier_product_code'],
            'supplier_group_code' => $attributes['supplier_group_code'],
            'product_name' => $attributes['product_name'],
            'supplier_category_name' => 'Plastik Kalemler',
            'stock_quantity' => $attributes['stock_quantity'],
            'purchase_price' => $attributes['price'],
            'currency' => 'TL',
            'image_url' => $attributes['image_url'] ?? 'https://example.test/' . strtolower($attributes['supplier_product_code']) . '.jpg',
            'source_price' => $attributes['price'],
            'source_stock' => $attributes['stock_quantity'],
            'normalized_payload' => [
                'list_price' => $attributes['price'],
                'purchase_price' => $attributes['price'],
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => $attributes['stock_quantity'],
                'total_variant_stock_quantity' => $attributes['stock_quantity'],
            ],
            'import_hash' => 'raw-parent-' . strtolower($attributes['supplier_product_code']),
        ]);

        $rawVariant = SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'parent_supplier_product_id' => $attributes['supplier_product_id'],
            'supplier_group_code' => $attributes['supplier_group_code'],
            'variant_code' => $attributes['variant_code'],
            'variant_stock_code' => $attributes['variant_stock_code'],
            'generated_variant_code' => $attributes['generated_variant_code'],
            'variant_name' => $attributes['variant_name'],
            'variant_color' => $attributes['variant_color'],
            'variant_stock_quantity' => $attributes['variant_stock_quantity'],
            'normalized_payload' => [
                'list_price' => $attributes['price'],
                'purchase_price' => $attributes['price'],
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'variant_stock_quantity' => $attributes['variant_stock_quantity'],
            ],
            'import_hash' => 'raw-variant-' . strtolower($attributes['variant_code']),
            'sync_status' => 'processed',
        ]);

        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct);

        $standardProduct = StandardProduct::query()->findOrFail($rawProduct->fresh()->standard_product_id);
        $standardVariant = StandardProductVariant::query()
            ->where('standard_product_id', $standardProduct->id)
            ->where('generated_variant_code', $attributes['generated_variant_code'])
            ->firstOrFail();

        $rawVariant->forceFill([
            'standard_product_variant_id' => $standardVariant->id,
        ])->save();

        $tenantCatalogProduct = null;
        $tenantCatalogVariant = null;

        if ($withTenantCatalog) {
            UserRole::query()->firstOrCreate([
                'user_id' => $this->adminUser->id,
                'tenant_account_id' => $this->demoTenant->id,
                'role_id' => $this->tenantOwnerRole->id,
            ]);

            TenantSupplierAccess::query()->updateOrCreate([
                'tenant_account_id' => $this->demoTenant->id,
                'supplier_id' => $source->supplier_id,
            ], [
                'is_active' => true,
                'can_view_products' => true,
                'visible_in_catalog' => true,
                'can_use_in_quotes' => true,
                'can_request_purchase' => true,
                'price_multiplier' => 1,
                'safe_stock_quantity' => 0,
                'export_allowed' => false,
            ]);

            $tenantCatalogProduct = TenantCatalogProduct::query()->create([
                'tenant_account_id' => $this->demoTenant->id,
                'standard_product_id' => $standardProduct->id,
                'tenant_sku' => $attributes['supplier_product_code'],
                'name' => $attributes['product_name'],
                'product_code' => $attributes['supplier_product_code'],
                'product_name' => $attributes['product_name'],
                'slug' => strtolower($attributes['supplier_product_code']),
                'product_family' => 'promotion',
                'image_url' => 'https://example.test/catalog-' . strtolower($attributes['supplier_product_code']) . '.jpg',
                'display_price' => $attributes['price'],
                'sale_price' => $attributes['price'],
                'currency' => 'TL',
                'stock_quantity' => $attributes['stock_quantity'],
                'total_stock_quantity' => $attributes['stock_quantity'],
                'local_stock_quantity' => 7,
                'supplier_stock_quantity' => $attributes['stock_quantity'],
                'safe_stock_quantity' => 0,
                'price_multiplier' => 1,
                'source_summary' => [[
                    'supplier_id' => $source->supplier_id,
                    'supplier_product_code' => $attributes['supplier_product_code'],
                    'supplier_group_code' => $attributes['supplier_group_code'],
                    'supplier_source_id' => $source->id,
                ]],
                'visible_in_catalog' => true,
                'visible_in_quote' => true,
                'is_featured' => false,
                'local_stock_priority' => true,
                'catalog_source' => 'supplier_projection',
                'catalog_status' => 'ready',
                'last_synced_at' => now(),
                'meta' => ['price_snapshot' => ['list_price' => $attributes['price']]],
                'is_active' => true,
                'allow_backorder' => false,
                'min_order_quantity' => 1,
                'tenant_attributes' => [],
            ]);

            $tenantCatalogVariant = TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $this->demoTenant->id,
                'tenant_catalog_product_id' => $tenantCatalogProduct->id,
                'standard_product_variant_id' => $standardVariant->id,
                'variant_code' => $attributes['variant_code'],
                'variant_name' => $attributes['variant_name'],
                'variant_color' => $attributes['variant_color'],
                'image_url' => 'https://example.test/catalog-variant-' . strtolower($attributes['variant_code']) . '.jpg',
                'display_price' => $attributes['price'],
                'currency' => 'TL',
                'stock_quantity' => $attributes['variant_stock_quantity'],
                'local_stock_quantity' => 7,
                'supplier_stock_quantity' => $attributes['variant_stock_quantity'],
                'safe_stock_quantity' => 0,
                'visible_in_catalog' => true,
                'is_active' => true,
                'source_summary' => [
                    'supplier_id' => $source->supplier_id,
                    'supplier_product_code' => $attributes['variant_code'],
                    'supplier_group_code' => $attributes['supplier_group_code'],
                    'supplier_source_id' => $source->id,
                ],
                'meta' => ['is_variant' => true],
            ]);
        }

        return compact('rawProduct', 'rawVariant', 'standardProduct', 'standardVariant', 'tenantCatalogProduct', 'tenantCatalogVariant');
    }

    private function createRawProductWithoutMapping(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => (string) ($attributes['supplier_product_id'] ?? $attributes['supplier_product_code']),
            'source_name' => $attributes['product_name'],
            'source_category' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'source_price' => $attributes['source_price'] ?? $attributes['purchase_price'] ?? 0,
            'source_currency' => $attributes['currency'] ?? 'TL',
            'source_stock' => $attributes['source_stock'] ?? $attributes['stock_quantity'] ?? 0,
            'supplier_product_id' => $attributes['supplier_product_id'] ?? null,
            'supplier_product_code' => $attributes['supplier_product_code'] ?? null,
            'supplier_group_code' => $attributes['supplier_group_code'] ?? null,
            'product_name' => $attributes['product_name'] ?? 'Fixture Product',
            'supplier_category_name' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'stock_quantity' => $attributes['stock_quantity'] ?? 0,
            'purchase_price' => $attributes['purchase_price'] ?? 0,
            'currency' => $attributes['currency'] ?? 'TL',
            'image_url' => $attributes['image_url'] ?? null,
            'normalized_payload' => $attributes['normalized_payload'] ?? [],
            'import_hash' => $attributes['import_hash'] ?? uniqid('raw-', true),
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function mockDeltaDetectorForCleanStockFixture(): void
    {
        $mock = Mockery::mock(DeltaChangeDetectorService::class);
        $mock->shouldReceive('detectForSource')->andReturn([
            'changes' => [
                ['type' => 'stock_changed', 'scope' => 'variant', 'identity_key' => 'group-stock:CLEAN:CLEAN-1', 'old_value' => ['stock_hash' => 'old-clean'], 'new_value' => ['stock_hash' => 'new-clean'], 'message' => 'Stok bilgisi değişti.'],
                ['type' => 'price_changed', 'scope' => 'variant', 'identity_key' => 'group-stock:PRICE:PRICE-1', 'old_value' => ['price_hash' => 'old-price'], 'new_value' => ['price_hash' => 'new-price'], 'message' => 'Fiyat bilgisi değişti.'],
                ['type' => 'price_and_stock_changed', 'scope' => 'variant', 'identity_key' => 'group-stock:BOTH:BOTH-1', 'old_value' => ['price_hash' => 'old-both', 'stock_hash' => 'old-both'], 'new_value' => ['price_hash' => 'new-both', 'stock_hash' => 'new-both'], 'message' => 'Fiyat ve stok birlikte değişti.'],
                ['type' => 'stock_changed', 'scope' => 'product', 'identity_key' => 'product:1400', 'old_value' => ['stock_hash' => 'old-req'], 'new_value' => ['stock_hash' => 'new-req'], 'message' => 'Stok bilgisi değişti.'],
                ['type' => 'blocked_required_field_missing', 'scope' => 'product', 'identity_key' => 'product:1400', 'old_value' => null, 'new_value' => null, 'message' => 'Zorunlu alan eksik veya bozuldu.'],
                ['type' => 'stock_changed', 'scope' => 'variant', 'identity_key' => 'group-stock:IMG:IMG-1', 'old_value' => ['stock_hash' => 'old-img'], 'new_value' => ['stock_hash' => 'new-img'], 'message' => 'Stok bilgisi değişti.'],
                ['type' => 'image_changed', 'scope' => 'variant', 'identity_key' => 'group-stock:IMG:IMG-1', 'old_value' => null, 'new_value' => null, 'message' => 'Görsel bilgisi değişti.'],
            ],
            'counts' => [
                'price_changed' => 1,
                'stock_changed' => 3,
                'price_and_stock_changed' => 1,
                'blocked_required_field_missing' => 1,
                'image_changed' => 1,
            ],
            'identity_summary' => [
                'reliable' => true,
                'status' => 'reliable',
                'label' => 'Güvenilir',
                'product_total' => 5,
                'product_reliable' => 5,
                'variant_total' => 5,
                'variant_reliable' => 5,
                'mapping_ok' => true,
                'warnings' => [],
            ],
            'flags' => [
                'feed_degraded' => false,
                'suspicious_feed_drop' => false,
                'suspicious_price_jump' => false,
            ],
            'apply_candidate' => true,
        ]);

        $this->app->instance(DeltaChangeDetectorService::class, $mock);
    }
}
