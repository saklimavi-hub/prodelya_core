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
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductHubSupplierFlowStepperTest extends TestCase
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

    public function test_supplier_flows_screen_renders_eight_step_stepper_and_stateful_actions(): void
    {
        $missingLocation = $this->createSource('FLOW-NO-LOCATION', [
            'source_name' => 'Konumsuz Kaynak',
        ]);

        $livePreviewMissingMapping = $this->createSource('FLOW-LIVE', [
            'source_name' => 'Canli Preview Kaynagi',
            'url' => 'https://example.test/live.xml',
            'config' => ['format' => 'xml', 'product_node_path' => 'urun'],
        ]);
        $this->insertPreviewLog($livePreviewMissingMapping, 'success');

        $fallbackPreview = $this->createSource('FLOW-FALLBACK', [
            'source_name' => 'Fallback Preview Kaynagi',
            'url' => 'https://example.test/fallback.json',
            'source_type' => 'api',
            'config' => ['format' => 'json', 'items_path' => 'items'],
        ]);
        $this->insertPreviewLog($fallbackPreview, 'fallback');

        $categoryPending = $this->createSource('FLOW-CATEGORY', [
            'source_name' => 'Kategori Bekleyen Kaynak',
            'url' => 'https://example.test/category.xml',
            'config' => ['format' => 'xml', 'product_node_path' => 'urun'],
        ]);
        $this->insertPreviewLog($categoryPending, 'success');
        $this->addRequiredMappings($categoryPending);
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $categoryPending->supplier_id,
            'supplier_source_id' => $categoryPending->id,
            'source_category' => 'Kalemler',
            'supplier_category_path' => 'Promosyon / Kalemler',
            'target_category' => 'Bekliyor',
            'mapping_status' => 'pending',
            'is_active' => true,
        ]);

        $catalogPending = $this->createSource('FLOW-CATALOG', [
            'source_name' => 'Projection Bekleyen Kaynak',
            'url' => 'https://example.test/catalog.xml',
            'config' => ['format' => 'xml', 'product_node_path' => 'urun'],
        ]);
        $this->insertPreviewLog($catalogPending, 'success');
        $this->addRequiredMappings($catalogPending);
        $category = StandardCategory::query()->create([
            'code' => 'FLOW-STEPPER-CAT',
            'name' => 'Stepper Kategori',
            'slug' => 'stepper-kategori',
            'path' => 'Stepper Kategori',
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $catalogPending->supplier_id,
            'supplier_source_id' => $catalogPending->id,
            'standard_category_id' => $category->id,
            'source_category' => 'Kupalar',
            'supplier_category_path' => 'Promosyon / Kupalar',
            'mapping_status' => 'approved',
            'target_category' => 'Stepper Kategori',
            'is_active' => true,
        ]);
        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $catalogPending->supplier_id,
            'sku' => 'FLOW-STD-001',
            'name' => 'Stepper Standart Urun',
            'standard_product_code' => 'FLOW-STD-001',
            'product_name' => 'Stepper Standart Urun',
            'standard_category_id' => $category->id,
            'is_active' => true,
            'visible_in_catalog' => true,
            'currency' => 'USD',
        ]);
        StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'variant_code' => 'FLOW-VAR-001',
            'generated_variant_code' => 'FLOW-VAR-001',
            'variant_name' => 'Mavi',
            'stock_quantity' => 25,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
        $rawProduct = SupplierProductRaw::query()->create([
            'supplier_id' => $catalogPending->supplier_id,
            'supplier_source_id' => $catalogPending->id,
            'standard_product_id' => $standardProduct->id,
            'source_product_id' => 'FLOW-RAW-001',
            'source_sku' => 'FLOW-RAW-001',
            'source_name' => 'Ham Stepper Urun',
            'source_category' => 'Kupalar',
            'source_price' => 4.50,
            'source_currency' => 'USD',
            'source_stock' => 20,
            'supplier_product_code' => 'FLOW-RAW-001',
            'product_name' => 'Ham Stepper Urun',
            'supplier_category_name' => 'Kupalar',
            'stock_quantity' => 20,
            'purchase_price' => 4.50,
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);
        SupplierProductVariantRaw::query()->create([
            'supplier_id' => $catalogPending->supplier_id,
            'supplier_source_id' => $catalogPending->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'variant_code' => 'FLOW-RAW-VAR-001',
            'variant_stock_code' => 'FLOW-RAW-VAR-001',
            'variant_name' => 'Mavi',
            'variant_stock_quantity' => 25,
            'sync_status' => 'processed',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->demoTenant->id,
            'supplier_id' => $catalogPending->supplier_id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $response = $this->renderSourceIndex();
        $response->assertOk();
        $response->assertSeeText('Tedarikçi Akışları');
        $response->assertSeeText('Detaya Git');

        $missingLocationCard = $this->extractSourceCard($this->renderSupplierDetail($missingLocation)->getContent(), $missingLocation->id);
        $this->assertSame(8, substr_count($missingLocationCard, 'data-flow-step="'));
        $this->assertStringContainsString('data-flow-step="source" data-flow-status="missing"', $missingLocationCard);
        $this->assertStringContainsString('data-primary-action="Ürünleri Senkronize Et"', $missingLocationCard);
        $this->assertStringContainsString('Gelişmiş İşlemler', $missingLocationCard);
        $this->assertStringContainsString('Sadece Tara', $missingLocationCard);
        $this->assertStringContainsString('Satış Listesi Onar', $missingLocationCard);

        $livePreviewCard = $this->extractSourceCard($this->renderSupplierDetail($livePreviewMissingMapping)->getContent(), $livePreviewMissingMapping->id);
        $this->assertStringContainsString('data-flow-step="preview" data-flow-status="ready"', $livePreviewCard);
        $this->assertStringContainsString('data-flow-step="field_mapping" data-flow-status="missing"', $livePreviewCard);
        $this->assertStringContainsString('data-primary-action="Ürünleri Senkronize Et"', $livePreviewCard);

        $fallbackCard = $this->extractSourceCard($this->renderSupplierDetail($fallbackPreview)->getContent(), $fallbackPreview->id);
        $this->assertStringContainsString('data-flow-step="preview" data-flow-status="warning"', $fallbackCard);
        $this->assertStringContainsString('Demo fallback gösteriliyor; canlı kaynak gibi değerlendirilmemeli.', $fallbackCard);
        $this->assertStringNotContainsString('data-flow-step="preview" data-flow-status="ready"', $fallbackCard);

        $categoryPendingCard = $this->extractSourceCard($this->renderSupplierDetail($categoryPending)->getContent(), $categoryPending->id);
        $this->assertStringContainsString('data-flow-step="field_mapping" data-flow-status="ready"', $categoryPendingCard);
        $this->assertStringContainsString('data-flow-step="category" data-flow-status="warning"', $categoryPendingCard);
        $this->assertStringContainsString('kategori eşleşmemiş kaydı var.', $categoryPendingCard);

        $catalogPendingCard = $this->extractSourceCard($this->renderSupplierDetail($catalogPending)->getContent(), $catalogPending->id);
        $this->assertStringContainsString('data-flow-step="standard_pool" data-flow-status="ready"', $catalogPendingCard);
        $this->assertStringContainsString('data-flow-step="catalog_projection" data-flow-status="warning"', $catalogPendingCard);
        $this->assertStringContainsString('Erişim var ama projection bekliyor.', $catalogPendingCard);
        $this->assertStringContainsString('data-primary-action="Ürünleri Senkronize Et"', $catalogPendingCard);
    }

    public function test_tenant_owner_cannot_open_supplier_flows_screen(): void
    {
        $tenantOwner = User::query()->create([
            'name' => 'Stepper Tenant Owner',
            'email' => 'stepper-tenant-owner@example.test',
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
            ->get(route('admin.super.product-data-hub.sources.index'))
            ->assertForbidden();
    }

    private function renderSourceIndex()
    {
        return $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', ['filter' => 'all']));
    }

    private function renderSupplierDetail(SupplierSource $source)
    {
        return $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));
    }

    private function createSource(string $supplierCode, array $overrides = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Stepper Supplier ' . $supplierCode,
            'code' => $supplierCode,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create(array_replace_recursive([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $supplierCode . ' Source',
            'status' => 'active',
            'url' => null,
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_profile_template' => 'CUSTOM',
                'format' => 'xml',
            ],
        ], $overrides));
    }

    private function insertPreviewLog(SupplierSource $source, string $previewMode): void
    {
        DB::table('feed_sync_logs')->insert([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'sync_type' => 'manual',
            'status' => $previewMode === 'success' || $previewMode === 'fallback' ? 'completed' : 'failed',
            'total_records' => 1,
            'processed_records' => $previewMode === 'success' || $previewMode === 'fallback' ? 1 : 0,
            'error_records' => $previewMode === 'error' ? 1 : 0,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
            'error_summary' => $previewMode === 'error' ? 'Preview error' : null,
            'sync_metadata' => json_encode([
                'preview_mode' => $previewMode,
                'source_mode' => $previewMode === 'success' ? 'live_source' : ($previewMode === 'fallback' ? 'demo_fallback' : 'error'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function addRequiredMappings(SupplierSource $source): void
    {
        foreach ([
            ['source_field' => 'urun_kodu', 'target_field' => 'supplier_product_code'],
            ['source_field' => 'urun_adi', 'target_field' => 'product_name'],
            ['source_field' => 'liste_fiyat', 'target_field' => 'purchase_price'],
        ] as $mapping) {
            SupplierFieldMapping::query()->create([
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'source_field' => $mapping['source_field'],
                'target_field' => $mapping['target_field'],
                'field_type' => 'text',
                'mapping_status' => 'mapped',
                'is_required' => true,
            ]);
        }
    }

    private function extractSourceCard(string $html, int $sourceId): string
    {
        $pattern = '/<article class="pd-source-row pd-source-row-stepper" data-flow-source="' . preg_quote((string) $sourceId, '/') . '">(.+?)<\/article>/su';

        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Source card not found for source #' . $sourceId);

        return $matches[0];
    }
}
