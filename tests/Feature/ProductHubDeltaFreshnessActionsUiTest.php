<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProductHubDeltaFreshnessActionsUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
    }

    public function test_supplier_card_shows_delta_freshness_actions(): void
    {
        $source = $this->createReadySource();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));

        $response->assertOk();
        $card = $this->extractSourceCard($response->getContent(), $source->id);
        $this->assertStringContainsString('Katalog Tazeliği', $card);
        $this->assertStringContainsString('Normal fiyat/stok değişimleri sessiz akışta ilerlemeli', $card);
        $this->assertStringContainsString('Fiyat/Stok Güncelle', $card);
        $this->assertStringContainsString('Sadece Tara', $card);
        $this->assertStringContainsString('Projection Onar', $card);
        $this->assertStringContainsString('Katalogda var, teklifte kapalı', $card);
    }

    public function test_ready_source_uses_fiyat_stok_guncelle_as_primary_cta(): void
    {
        $source = $this->createOperationallyReadySource();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));

        $response->assertOk();
        $card = $this->extractSourceCard($response->getContent(), $source->id);
        $this->assertStringContainsString('data-primary-action="Fiyat/Stok Güncelle"', $card);
    }

    public function test_delta_dry_run_route_calls_service_without_persisting_run(): void
    {
        $source = $this->createReadySource();
        $run = new ProductDataHubSyncRun([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'manual',
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => [
                'mode' => 'delta',
                'dry_run' => true,
                'delta_summary' => [
                    'counts' => [
                        'price_changed' => 2,
                        'stock_changed' => 1,
                        'price_and_stock_changed' => 3,
                        'new_product' => 1,
                        'missing_product' => 0,
                        'missing_variant' => 0,
                    ],
                    'flags' => [],
                ],
            ],
        ]);

        $mock = Mockery::mock(SupplierSourceSyncService::class);
        $mock->shouldReceive('syncSource')
            ->once()
            ->withArgs(function ($passedSource, $options) use ($source) {
                return $passedSource->is($source)
                    && ($options['mode'] ?? null) === 'delta'
                    && ($options['dry_run'] ?? false) === true
                    && ($options['no_project'] ?? false) === true;
            })
            ->andReturn(['run' => $run, 'stats' => []]);
        $this->app->instance(SupplierSourceSyncService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.delta-dry-run', $source));

        $response->assertRedirect(route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]));
        $response->assertSessionHas('success');
        $this->assertDatabaseCount('product_data_hub_sync_runs', 0);
    }

    public function test_apply_routes_require_super_admin_and_post(): void
    {
        $source = $this->createReadySource();
        $tenantOwner = User::query()->create([
            'name' => 'Freshness Tenant Owner',
            'email' => 'freshness-tenant-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantOwner->id,
            'tenant_account_id' => $this->demoTenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock', $source))
            ->assertForbidden();

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock-project-dirty', $source))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.apply-price-stock', $source))
            ->assertStatus(405);
    }

    public function test_apply_price_stock_route_now_projects_dirty_rows_in_same_run(): void
    {
        $source = $this->createReadySource();
        $run = new ProductDataHubSyncRun([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'manual',
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => [
                'mode' => 'delta',
                'delta_apply_summary' => [
                    'price_changed_applied' => 1,
                    'stock_changed_applied' => 1,
                    'tenant_catalog_products_updated' => 1,
                    'tenant_catalog_variants_updated' => 1,
                    'projection_mode' => 'dirty',
                ],
            ],
        ]);

        $mock = Mockery::mock(SupplierSourceSyncService::class);
        $mock->shouldReceive('syncSource')
            ->once()
            ->withArgs(function ($passedSource, $options) use ($source) {
                return $passedSource->is($source)
                    && ($options['mode'] ?? null) === 'delta'
                    && ($options['apply_price_stock'] ?? false) === true
                    && ($options['project_dirty'] ?? false) === true
                    && !($options['no_project'] ?? false);
            })
            ->andReturn(['run' => $run, 'stats' => []]);
        $this->app->instance(SupplierSourceSyncService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock', $source));

        $response->assertRedirect(route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]));
        $response->assertSessionHas('success');
    }

    public function test_inactive_source_returns_error_without_running_delta_action(): void
    {
        $source = $this->createReadySource(['status' => 'inactive']);

        $mock = Mockery::mock(SupplierSourceSyncService::class);
        $mock->shouldNotReceive('syncSource');
        $this->app->instance(SupplierSourceSyncService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock', $source));

        $response->assertRedirect(route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]));
        $response->assertSessionHas('error', 'Bu aksiyon yalnız aktif kaynaklarda çalıştırılabilir.');
    }

    public function test_apply_and_project_dirty_route_reports_summary_to_user(): void
    {
        $source = $this->createReadySource();
        $run = new ProductDataHubSyncRun([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'manual',
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => [
                'mode' => 'delta',
                'delta_apply_summary' => [
                    'counts' => [
                        'price_changed' => 2,
                        'stock_changed' => 1,
                        'price_and_stock_changed' => 3,
                        'new_product' => 1,
                        'missing_product' => 0,
                        'missing_variant' => 1,
                    ],
                    'price_changed_applied' => 2,
                    'stock_changed_applied' => 1,
                    'price_and_stock_changed_applied' => 3,
                    'tenant_catalog_products_updated' => 2,
                    'tenant_catalog_variants_updated' => 4,
                    'projection_mode' => 'dirty',
                ],
            ],
        ]);

        $mock = Mockery::mock(SupplierSourceSyncService::class);
        $mock->shouldReceive('syncSource')
            ->once()
            ->withArgs(function ($passedSource, $options) use ($source) {
                return $passedSource->is($source)
                    && ($options['mode'] ?? null) === 'delta'
                    && ($options['apply_price_stock'] ?? false) === true
                    && ($options['project_dirty'] ?? false) === true;
            })
            ->andReturn(['run' => $run, 'stats' => []]);
        $this->app->instance(SupplierSourceSyncService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock-project-dirty', $source));

        $response->assertRedirect(route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]));
        $response->assertSessionHas('success');
        $response->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, 'Fiyat/stok güncelleme + Abone Kataloğa Yansıtma tamamlandı')
                && str_contains($message, 'otomatik işlenen 6')
                && str_contains($message, 'tenant katalog varyantı güncellenen 4')
                && str_contains($message, 'kataloga yansıtılan 6');
        });
    }

    private function createReadySource(array $overrides = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Freshness UI Supplier',
            'code' => 'FRESH-UI',
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create(array_replace_recursive([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Freshness UI Source',
            'status' => 'active',
            'url' => null,
            'config' => [
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'format' => 'json',
                'source_file_path' => 'C:\\laragon\\www\\feeds\\curl.json',
            ],
        ], $overrides));

        SupplierFieldMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_field' => 'urun_kodu',
            'target_field' => 'supplier_product_code',
            'field_type' => 'text',
            'mapping_status' => 'mapped',
        ]);

        return $source;
    }

    private function createOperationallyReadySource(): SupplierSource
    {
        $source = $this->createReadySource([
            'source_name' => 'Operational Freshness Source',
            'url' => 'https://example.test/operational-feed.json',
        ]);

        DB::table('feed_sync_logs')->insert([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'sync_type' => 'manual',
            'status' => 'completed',
            'total_records' => 1,
            'processed_records' => 1,
            'error_records' => 0,
            'sync_metadata' => json_encode(['preview_mode' => 'success'], JSON_UNESCAPED_UNICODE),
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        foreach ([
            'urun_kodu' => ['target_field' => 'supplier_product_code', 'field_type' => 'text'],
            'urun_isim' => ['target_field' => 'product_name', 'field_type' => 'text'],
            'urun_fiyat' => ['target_field' => 'list_price', 'field_type' => 'decimal'],
            'toplam_stok' => ['target_field' => 'stock_quantity', 'field_type' => 'number'],
        ] as $sourceField => $mapping) {
            SupplierFieldMapping::query()->updateOrCreate(
                [
                    'supplier_id' => $source->supplier_id,
                    'supplier_source_id' => $source->id,
                    'source_field' => $sourceField,
                ],
                [
                    'target_field' => $mapping['target_field'],
                    'field_type' => $mapping['field_type'],
                    'mapping_status' => 'mapped',
                ]
            );
        }

        $category = StandardCategory::query()->create([
            'code' => 'FRESH-UI-CAT',
            'name' => 'Freshness UI Kategori',
            'slug' => 'freshness-ui-kategori',
            'path' => 'Freshness UI Kategori',
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'source_category' => 'Kalemler',
            'supplier_category_path' => 'Promosyon / Kalemler',
            'mapping_status' => 'approved',
            'target_category' => 'Freshness UI Kategori',
            'is_active' => true,
        ]);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'sku' => 'FRESH-UI-STD-001',
            'name' => 'Freshness UI Standart Urun',
            'standard_product_code' => 'FRESH-UI-STD-001',
            'product_name' => 'Freshness UI Standart Urun',
            'standard_category_id' => $category->id,
            'is_active' => true,
            'visible_in_catalog' => true,
            'currency' => 'TRY',
        ]);

        StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'variant_code' => 'FRESH-UI-VAR-001',
            'generated_variant_code' => 'FRESH-UI-VAR-001',
            'variant_name' => 'Siyah',
            'stock_quantity' => 10,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $rawProduct = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_product_id' => $standardProduct->id,
            'source_product_id' => 'FRESH-UI-RAW-001',
            'source_sku' => 'FRESH-UI-RAW-001',
            'source_name' => 'Freshness UI Ham Urun',
            'source_category' => 'Kalemler',
            'source_price' => 12.5,
            'source_currency' => 'TRY',
            'source_stock' => 10,
            'supplier_product_code' => 'FRESH-UI-RAW-001',
            'product_name' => 'Freshness UI Ham Urun',
            'supplier_category_name' => 'Kalemler',
            'stock_quantity' => 10,
            'purchase_price' => 12.5,
            'currency' => 'TRY',
            'sync_status' => 'processed',
        ]);

        SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'variant_code' => 'FRESH-UI-VAR-001',
            'variant_stock_code' => 'FRESH-UI-VAR-001',
            'variant_name' => 'Siyah',
            'variant_stock_quantity' => 10,
            'sync_status' => 'processed',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->demoTenant->id,
            'supplier_id' => $source->supplier_id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $tenantCatalogProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->demoTenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'FRESH-UI-TENANT-001',
            'name' => 'Freshness UI Standart Urun',
            'product_code' => 'FRESH-UI-STD-001',
            'product_name' => 'Freshness UI Standart Urun',
            'currency' => 'TRY',
            'stock_quantity' => 10,
            'display_price' => 12.5,
            'is_active' => true,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_catalog_product_id' => $tenantCatalogProduct->id,
            'tenant_account_id' => $this->demoTenant->id,
            'supplier_id' => $source->supplier_id,
            'standard_product_variant_id' => StandardProductVariant::query()
                ->where('standard_product_id', $standardProduct->id)
                ->value('id'),
            'supplier_product_variant_raw_id' => SupplierProductVariantRaw::query()
                ->where('supplier_product_raw_id', $rawProduct->id)
                ->value('id'),
            'variant_code' => 'FRESH-UI-VAR-001',
            'variant_name' => 'Siyah',
            'currency' => 'TRY',
            'display_price' => 12.5,
            'stock_quantity' => 10,
            'supplier_stock_quantity' => 10,
            'local_stock_quantity' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
        ]);

        ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'manual',
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'report_payload' => [
                'mode' => 'full',
            ],
        ]);

        return $source;
    }

    private function extractSourceCard(string $html, int $sourceId): string
    {
        $marker = 'data-flow-source="' . $sourceId . '"';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'Kaynak kartı bulunamadı.');

        $articleStart = strrpos(substr($html, 0, $start), '<article');
        $articleEnd = strpos($html, '</article>', $start);

        $this->assertNotFalse($articleStart, 'Kaynak kartı başlangıcı bulunamadı.');
        $this->assertNotFalse($articleEnd, 'Kaynak kartı sonu bulunamadı.');

        return substr($html, $articleStart, $articleEnd - $articleStart + strlen('</article>'));
    }
}
