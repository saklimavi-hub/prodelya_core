<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProductDataHub\FallbackCategoryService;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductDataHubUnmappedCategoryProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_unmapped_product_projects_to_tenant_catalog_with_fallback_category(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('AKDENIZ', 'Akdeniz Promosyon');
        $this->grantAccess($tenant, $source);
        $product = $this->standardProduct($source, 'AK-UNMAPPED-1', 'Akdeniz Kategorisiz Kalem', 'XML / Bilinmeyen');

        $result = app(TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'standard_product_ids' => [$product->id],
        ]);

        $fallback = StandardCategory::query()->where('code', FallbackCategoryService::PENDING_CODE)->firstOrFail();
        $catalogProduct = TenantCatalogProduct::query()->where('standard_product_id', $product->id)->firstOrFail();

        $this->assertSame(1, $result['products']);
        $this->assertSame($fallback->id, $catalogProduct->standard_category_id);
        $this->assertSame('category_pending', $catalogProduct->catalog_status);
        $this->assertTrue((bool) data_get($catalogProduct->meta, 'category_missing_warning'));
        $this->assertContains('Genel kategori henüz bağlanmadı', data_get($catalogProduct->meta, 'warning_snapshot', []));
        $this->assertTrue($catalogProduct->visible_in_catalog);
        $this->assertTrue($catalogProduct->visible_in_quote);
        $this->assertDatabaseMissing('supplier_sources', ['source_name' => 'TMP']);
    }

    public function test_unmapped_product_is_visible_in_catalog_and_search(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('YENI-NESIL', 'Yeni Nesil');
        $this->grantAccess($tenant, $source);
        $product = $this->standardProduct($source, 'YN-UNMAPPED-1', 'Yeni Nesil Kategori Bekleyen Ürün', 'Yeni Nesil / Belirsiz');

        app(TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'standard_product_ids' => [$product->id],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?q=YN-UNMAPPED-1')
            ->assertOk()
            ->assertSeeText('YN-UNMAPPED-1')
            ->assertSeeText('Kategori eşleşmemiş')
            ->assertSeeText('Kategori uyarısı');

        $searchResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=YN-UNMAPPED-1')
            ->assertOk()
            ->assertJsonFragment(['product_code' => 'YN-UNMAPPED-1']);

        $this->assertContains('Kategori eşleşmemiş', $searchResponse->json('0.warning_badges'));
        $this->assertContains('Kategori uyarısı', $searchResponse->json('0.warning_badges'));
    }

    public function test_source_access_closed_keeps_unmapped_product_hidden(): void
    {
        $tenant = $this->tenant();
        $visibleSource = $this->source('ETKIN', 'Etkin Promosyon');
        $closedSource = $this->source('ILPEN', 'İlpen');
        $this->grantAccess($tenant, $visibleSource);
        $this->grantAccess($tenant, $closedSource, false);

        $visibleProduct = $this->standardProduct($visibleSource, 'ET-VISIBLE', 'Etkin Görünür Ürün', 'Etkin / Kalem');
        $closedProduct = $this->standardProduct($closedSource, 'IL-HIDDEN', 'İlpen Gizli Ürün', 'İlpen / Set');

        app(TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'standard_product_ids' => [$visibleProduct->id, $closedProduct->id],
        ]);

        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $visibleProduct->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $closedProduct->id,
        ]);
    }

    public function test_category_conflict_warns_but_does_not_block_projection_price_and_quote_visibility(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('ETKIN', 'Etkin Promosyon');
        $this->grantAccess($tenant, $source);
        $product = $this->standardProduct($source, 'ET-CONFLICT-1', 'Etkin Conflict Ürün', 'Etkin / Kalem');

        \App\Models\SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_product_id' => $product->id,
            'source_product_id' => 'ET-CONFLICT-1',
            'supplier_product_code' => 'ET-CONFLICT-1',
            'product_name' => 'Etkin Conflict Ürün',
            'source_name' => 'Etkin Conflict Ürün',
            'supplier_category_name' => 'Kalem',
            'source_category' => 'Kalem',
            'stock_quantity' => 32,
            'source_stock' => 32,
            'purchase_price' => 144.5,
            'source_price' => 144.5,
            'currency' => 'TL',
            'source_currency' => 'TL',
            'mapping_status' => 'conflict',
            'normalized_payload' => [
                'list_price' => 144.5,
                'purchase_price' => 144.5,
                'currency' => 'TL',
            ],
            'import_hash' => 'conflict-raw',
            'sync_status' => 'processed',
        ]);

        app(TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'standard_product_ids' => [$product->id],
        ]);

        $catalogProduct = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('category_pending', $catalogProduct->catalog_status);
        $this->assertTrue($catalogProduct->visible_in_catalog);
        $this->assertTrue($catalogProduct->visible_in_quote);
        $this->assertContains('Kategori eşleşmemiş', data_get($catalogProduct->meta, 'warning_snapshot', []));
        $this->assertContains('Kategori uyarısı', data_get($catalogProduct->meta, 'warning_snapshot', []));
    }

    public function test_project_unmapped_products_command_is_dry_run_safe_and_requires_confirm(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('AKDENIZ', 'Akdeniz Promosyon');
        $this->grantAccess($tenant, $source);
        $product = $this->standardProduct($source, 'AK-DRY-1', 'Akdeniz Dry Run Ürün', 'Akdeniz / Hedef Yok');

        $this->artisan('product-data-hub:project-unmapped-products --dry-run')
            ->expectsOutputToContain('Dry-run tamamlandı')
            ->expectsOutputToContain('Tenant access açık projection adayı: 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('tenant_catalog_products', [
            'standard_product_id' => $product->id,
        ]);

        $this->artisan('product-data-hub:project-unmapped-products --apply')
            ->expectsOutputToContain('--confirm=PROJECT-UNMAPPED-PRODUCTS zorunludur')
            ->assertExitCode(1);
    }

    public function test_project_unmapped_products_apply_projects_only_confirmed_safe_records(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('AKDENIZ', 'Akdeniz Promosyon');
        $this->grantAccess($tenant, $source);
        $product = $this->standardProduct($source, 'AK-APPLY-1', 'Akdeniz Apply Ürün', 'Akdeniz / Hedef Yok');

        $this->artisan('product-data-hub:project-unmapped-products --apply --confirm=PROJECT-UNMAPPED-PRODUCTS')
            ->expectsOutputToContain('Apply tamamlandı')
            ->assertExitCode(0);

        $fallback = StandardCategory::query()->where('code', FallbackCategoryService::PENDING_CODE)->firstOrFail();

        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $product->id,
            'standard_category_id' => $fallback->id,
            'catalog_status' => 'category_pending',
        ]);
    }

    public function test_fallback_projection_does_not_approve_supplier_category_mapping(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('ETKIN', 'Etkin Promosyon');
        $this->grantAccess($tenant, $source);
        $this->standardProduct($source, 'ET-FALLBACK-MAP-1', 'Etkin Fallback Mapping Ürün', 'Etkin / Hedef Yok');
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Hedef Yok',
            'supplier_category_path' => 'Etkin / Hedef Yok',
            'normalized_name' => 'hedef yok',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'review',
            'product_count' => 1,
            'is_active' => true,
        ]);
        $approvedBefore = SupplierCategoryMapping::query()->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped'])->count();

        $this->artisan('product-data-hub:project-unmapped-products --apply --confirm=PROJECT-UNMAPPED-PRODUCTS')
            ->assertExitCode(0);

        $this->assertSame($approvedBefore, SupplierCategoryMapping::query()->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped'])->count());
        $this->assertDatabaseHas('supplier_category_mappings', [
            'supplier_source_id' => $source->id,
            'source_category' => 'Hedef Yok',
            'mapping_status' => 'pending',
            'standard_category_id' => null,
        ]);
    }

    public function test_project_unmapped_products_command_reports_idempotent_counts(): void
    {
        $tenant = $this->tenant();
        $source = $this->source('ILPEN', 'İlpen');
        $this->grantAccess($tenant, $source);
        $this->standardProduct($source, 'IL-IDEMPOTENT-1', 'İlpen Idempotent Ürün', 'İlpen / Hedef Yok');

        $this->artisan('product-data-hub:project-unmapped-products --apply --confirm=PROJECT-UNMAPPED-PRODUCTS')
            ->assertExitCode(0);

        $countAfterFirstRun = TenantCatalogProduct::query()->count();

        $this->artisan('product-data-hub:project-unmapped-products --dry-run')
            ->expectsOutputToContain('already_fallback_count: 1')
            ->expectsOutputToContain('would_create_count: 0')
            ->expectsOutputToContain('would_update_count: 0')
            ->assertExitCode(0);

        $this->assertSame($countAfterFirstRun, TenantCatalogProduct::query()->count());
    }

    public function test_category_review_batch_export_creates_csv_and_json_without_data_changes(): void
    {
        Storage::fake('local');

        $source = $this->source('AKDENIZ', 'Akdeniz Promosyon');
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_category_code' => 'AK-SET-KUTU',
            'source_category' => 'Set Kutuları',
            'supplier_category_path' => 'Akdeniz / Set Kutuları',
            'normalized_name' => 'set kutulari',
            'product_count' => 12,
            'sample_product_names' => ['Kutulu Set', 'Boş Set Kutusu'],
            'suggestion_meta' => ['review_required' => true],
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'review',
            'is_active' => true,
        ]);

        $before = SupplierCategoryMapping::query()->count();

        $this->artisan('product-data-hub:export-category-review-batch --batch=001 --limit=50')
            ->expectsOutputToContain('Kategori review batch üretildi')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists('product-data-hub/category-review/category_review_batch_001.csv');
        Storage::disk('local')->assertExists('product-data-hub/category-review/category_review_batch_001.json');
        $this->assertSame($before, SupplierCategoryMapping::query()->count());
        $this->assertStringContainsString('Set Kutuları', Storage::disk('local')->get('product-data-hub/category-review/category_review_batch_001.csv'));
        $this->assertStringContainsString('"risk_group": "Set Kutuları"', Storage::disk('local')->get('product-data-hub/category-review/category_review_batch_001.json'));
    }

    private function tenant(): TenantAccount
    {
        return TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?: TenantAccount::query()->create([
                'name' => 'Demo Tenant',
                'slug' => 'demo',
                'panel_subdomain' => 'demo',
                'status' => 'active',
                'package_key' => 'suite',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'number_format_locale' => 'tr_TR',
            ]);
    }

    private function source(string $code, string $name): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code . '-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $name,
            'config' => [
                'format' => 'xml',
                'sync_policy' => [
                    'missing_category_policy' => 'warn_and_project',
                    'sync_block_on_missing_category' => true,
                ],
            ],
            'status' => 'active',
        ]);
    }

    private function grantAccess(TenantAccount $tenant, SupplierSource $source, bool $open = true): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $source->supplier_id,
            'is_active' => $open,
            'can_view_products' => $open,
            'can_request_purchase' => $open,
            'can_use_in_quotes' => $open,
            'visible_in_catalog' => $open,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => $open,
        ]);
    }

    private function standardProduct(SupplierSource $source, string $code, string $name, string $supplierCategoryPath): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'sku' => $code,
            'standard_product_code' => $code,
            'name' => $name,
            'product_name' => $name,
            'standard_category_id' => null,
            'category' => null,
            'product_family' => 'promotion',
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 100,
            'total_stock_quantity' => 25,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => $code,
                'supplier_category_name' => basename(str_replace('\\', '/', $supplierCategoryPath)),
                'supplier_category_path' => $supplierCategoryPath,
            ]],
            'meta' => [
                'supplier_category_name' => basename(str_replace('\\', '/', $supplierCategoryPath)),
                'supplier_category_path' => $supplierCategoryPath,
                'category_missing_warning' => true,
            ],
        ]);
    }
}
