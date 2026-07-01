<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
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
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductHubTemplateCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
    }

    public function test_status_center_shows_compact_daily_summary_and_uses_abone_firma_language(): void
    {
        $response = $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.index'));

        $response->assertOk();
        $response->assertSeeText('Durum Merkezi');
        $response->assertSeeText('Aktif Hazır Tedarikçi Kaynağı');
        $response->assertSeeText('Kontrol Bekleyen Ürün');
        $response->assertSeeText('Abone Katalog Yayını Bekleyen');
        $response->assertSeeText('Tedarikçi Akışlarını Aç');
        $response->assertSeeText('Abone Katalog Yayınını Aç');
        $response->assertSeeText('Abone Firma Erişimi');
        $response->assertDontSeeText('İki Katmanlı Yönetim Modeli');
        $response->assertDontSeeText('Tenant Tedarikçi Erişim Matrisi');
        $response->assertDontSeeText('Tenant Yetki Alanları');
    }

    public function test_supplier_flow_cards_use_single_primary_cta_and_clear_catalog_message(): void
    {
        $source = $this->makeProjectionPendingSource();

        $response = $this->renderSourceIndex();
        $response->assertOk();
        $response->assertSeeText('Detaya Git');

        $card = $this->extractSourceCard($this->renderSupplierDetail($source)->getContent(), $source->id);
        $this->assertSame(1, substr_count($card, 'data-primary-action="'));
        $this->assertStringContainsString('data-primary-action="Fiyat/Stok Güncelle"', $card);
        $this->assertStringContainsString('Bu kaynakta ürünler hazır, ancak Abone Firma kataloğuna henüz yansıtılmamış kayıtlar var.', $card);
        $this->assertStringContainsString('Gelişmiş İşlemler', $card);
        $this->assertStringContainsString('Abone Katalog Durumu', $card);
        $this->assertStringContainsString('Projection Onar', $card);
    }

    public function test_review_waiting_source_prefers_inceleme_bekleyenleri_cta(): void
    {
        $source = $this->createSource('FLOW-REVIEW', [
            'source_name' => 'Inceleme Bekleyen Kaynak',
            'url' => 'https://example.test/review.xml',
            'config' => ['format' => 'xml', 'product_node_path' => 'urun'],
        ]);
        $this->insertPreviewLog($source, 'success');
        $this->addRequiredMappings($source);

        ProductDataHubSyncChange::query()->create([
            'sync_run_id' => ProductDataHubSyncRun::query()->create([
                'supplier_source_id' => $source->id,
                'supplier_id' => $source->supplier_id,
                'run_type' => 'manual',
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
                'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            ])->id,
            'supplier_source_id' => $source->id,
            'supplier_product_key' => 'FLOW-REVIEW-001',
            'change_type' => 'new_product',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
            'message' => 'Yeni ürün inceleme bekliyor.',
            'review_payload' => ['supplier_product_code' => 'FLOW-REVIEW-001'],
        ]);

        $response = $this->renderSourceIndex();
        $response->assertOk();

        $card = $this->extractSourceCard($this->renderSupplierDetail($source)->getContent(), $source->id);
        $this->assertSame(1, substr_count($card, 'data-primary-action="'));
        $this->assertStringContainsString('data-primary-action="Fiyat/Stok Güncelle"', $card);
        $this->assertStringContainsString('Değişimleri İncele', $card);
        $this->assertStringContainsString('data-review-type="new_product" data-review-count="1"', $card);
    }

    public function test_product_hub_specific_css_uses_standard_radii(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertNotFalse($css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-source-summary-card\s*\{[^}]*border-radius:\s*14px;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-flow-step\s*\{[^}]*border-radius:\s*16px;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-flow-step-index\s*\{[^}]*border-radius:\s*999px;/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-source-summary-card\s*\{[^}]*border-radius:\s*var\(--pd-radius-panel\);/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-flow-step\s*\{[^}]*border-radius:\s*var\(--pd-radius-panel\);/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-flow-step-index\s*\{[^}]*border-radius:\s*var\(--pd-radius-pill\);/s', $css);
    }

    private function renderSourceIndex()
    {
        return $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', ['filter' => 'all']));
    }

    private function renderSupplierDetail(SupplierSource $source)
    {
        return $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id, 'filter' => 'all']));
    }

    private function makeProjectionPendingSource(): SupplierSource
    {
        $source = $this->createSource('FLOW-CLEANUP', [
            'source_name' => 'Yayin Bekleyen Kaynak',
            'url' => 'https://example.test/catalog.xml',
            'config' => ['format' => 'xml', 'product_node_path' => 'urun'],
        ]);

        $this->insertPreviewLog($source, 'success');
        $this->addRequiredMappings($source);

        $category = StandardCategory::query()->create([
            'code' => 'FLOW-CLEANUP-CAT',
            'name' => 'Cleanup Kategori',
            'slug' => 'cleanup-kategori',
            'path' => 'Cleanup Kategori',
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'source_category' => 'Kupalar',
            'supplier_category_path' => 'Promosyon / Kupalar',
            'mapping_status' => 'approved',
            'target_category' => 'Cleanup Kategori',
            'is_active' => true,
        ]);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'sku' => 'FLOW-CLEAN-STD-001',
            'name' => 'Cleanup Standart Urun',
            'standard_product_code' => 'FLOW-CLEAN-STD-001',
            'product_name' => 'Cleanup Standart Urun',
            'standard_category_id' => $category->id,
            'is_active' => true,
            'visible_in_catalog' => true,
            'currency' => 'USD',
        ]);

        StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'variant_code' => 'FLOW-CLEAN-VAR-001',
            'generated_variant_code' => 'FLOW-CLEAN-VAR-001',
            'variant_name' => 'Mavi',
            'stock_quantity' => 25,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $rawProduct = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'standard_product_id' => $standardProduct->id,
            'source_product_id' => 'FLOW-CLEAN-RAW-001',
            'source_sku' => 'FLOW-CLEAN-RAW-001',
            'source_name' => 'Cleanup Ham Urun',
            'source_category' => 'Kupalar',
            'source_price' => 4.50,
            'source_currency' => 'USD',
            'source_stock' => 20,
            'supplier_product_code' => 'FLOW-CLEAN-RAW-001',
            'product_name' => 'Cleanup Ham Urun',
            'supplier_category_name' => 'Kupalar',
            'stock_quantity' => 20,
            'purchase_price' => 4.50,
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'variant_code' => 'FLOW-CLEAN-RAW-VAR-001',
            'variant_stock_code' => 'FLOW-CLEAN-RAW-VAR-001',
            'variant_name' => 'Mavi',
            'variant_stock_quantity' => 25,
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

        return $source;
    }

    private function createSource(string $supplierCode, array $overrides = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Cleanup Supplier ' . $supplierCode,
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
            'sync_metadata' => json_encode(['preview_mode' => $previewMode], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function addRequiredMappings(SupplierSource $source): void
    {
        foreach ([
            ['source_field' => 'urun_kodu', 'target_field' => 'supplier_product_code'],
            ['source_field' => 'urun_isim', 'target_field' => 'product_name'],
            ['source_field' => 'kategori_adi', 'target_field' => 'supplier_category_name'],
            ['source_field' => 'urun_fiyat', 'target_field' => 'purchase_price'],
            ['source_field' => 'toplam_stok', 'target_field' => 'stock_quantity'],
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
        $startMarker = 'data-flow-source="' . $sourceId . '"';
        $start = strpos($html, $startMarker);

        $this->assertNotFalse($start, 'Source card start not found for source ' . $sourceId);

        $nextStart = strpos($html, 'data-flow-source="', $start + strlen($startMarker));
        if ($nextStart === false) {
            return substr($html, $start);
        }

        return substr($html, $start, $nextStart - $start);
    }
}
