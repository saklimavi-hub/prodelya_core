<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminProductPanelTest extends TestCase
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

    public function test_super_admin_product_panel_opens_and_shows_clean_product_name(): void
    {
        $supplier = $this->makeSupplier('AKDENIZ-SUPER', 'Akdeniz Promosyon');
        $this->makeFlatProduct(
            $supplier,
            'AK-3008-11-KIRMIZI',
            'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı'
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=AK-3008-11-KIRMIZI');

        $response->assertOk();
        $response->assertSeeText('Bekleyen Kontroller');
        $response->assertSeeText('AK-3008-11');
        $response->assertSeeText('AK-3008-11 Kırmızı 11 Fonksiyonlu Çakı');
    }

    public function test_product_panel_blank_open_uses_search_first_info_state(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel');

        $response->assertOk();
        $response->assertSeeText('Kontrol başlatmak için arama veya filtre seçin.');
        $response->assertSeeText('boş açılışta tam tarama yapmaz');
        $response->assertSeeText('Kontrol için arama veya filtre seçin.');
        $response->assertSeeText('Şüpheli Fiyat / Stok');
        $response->assertSeeText('Yeni Ürün');
        $response->assertSeeText('Tümü');
        $response->assertSeeText('Akış Özeti');
        $response->assertSeeText('Güncellik Özeti');
        $response->assertSeeText('Satış listesi eski');
        $response->assertDontSeeText('Supheli');
        $response->assertDontSeeText('Urun');
        $response->assertDontSeeText('Guncel');
        $response->assertDontSeeText('Gorunurluk');
        $response->assertDontSeeText('Gelismis');
        $response->assertDontSeeText('Tumu');
        $response->assertDontSeeText('Projection eski satır');
    }

    public function test_product_panel_default_and_empty_params_do_not_break_search_first_idle_state(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=&supplier=0&category=0&flow_mode=all&review_bucket=default&freshness_state=all');

        $response->assertOk();
        $response->assertSeeText('Kontrol başlatmak için arama veya filtre seçin.');
        $response->assertSeeText('Kontrol için arama veya filtre seçin.');
        $response->assertDontSeeText('Filtrelere uygun ürün bulunamadı.');
    }

    public function test_super_admin_product_panel_hides_group_code_from_decision_screen_but_keeps_row_type_for_technical_mode(): void
    {
        $supplier = $this->makeSupplier('ETKIN-SUPER', 'Etkin Promosyon');
        $parent = $this->makeParentProduct($supplier, 'ET-0506', 'ET-0506 Plastik Kalem');
        $this->makeVariant($parent, 'ET-0506-K', 'Kırmızı', 12, 48, 'GRP-TECH-0506');

        $defaultResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-K');

        $defaultResponse->assertOk();
        $defaultResponse->assertSeeText('ET-0506-K Plastik Kalem Kırmızı');
        $defaultResponse->assertDontSeeText('Group code: GRP-TECH-0506');

        $technicalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-K&technical_columns=1');

        $technicalResponse->assertOk();
        $technicalResponse->assertDontSeeText('Group code: GRP-TECH-0506');
        $technicalResponse->assertSeeText('Satır tipi: variant');
    }

    public function test_product_panel_detail_links_point_to_standard_products_not_common_products(): void
    {
        $supplier = $this->makeSupplier('DETAIL-SUPER', 'Detail Supplier');
        $this->makeFlatProduct($supplier, 'DT-0506-L', 'Detail Link Product');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=DT-0506-L');

        $response->assertOk();
        $response->assertSee('/admin/super-admin/product-data-hub/standard-products?q=DT-0506-L', false);
        $response->assertDontSee('/admin/super-admin/product-data-hub/common-products?q=DT-0506-L', false);
    }

    public function test_super_admin_product_panel_filters_supplier_category_status_and_limit(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $supplierA = $this->makeSupplier('YN-SUPER', 'Yeni Nesil');
        $supplierB = $this->makeSupplier('IL-SUPER', 'İlpen');

        $this->makeFlatProduct($supplierA, 'YN-209025', 'YN-209025 AKDERE TABA TARİHLİ AJANDA 15X21 CM', $category->id);

        StandardProduct::query()->create([
            'supplier_id' => $supplierB->id,
            'standard_product_code' => 'IL-WAIT-01',
            'sku' => 'IL-WAIT-01',
            'product_name' => 'Kategori Bekleyen Ürün',
            'base_product_name' => 'Kategori Bekleyen Ürün',
            'name' => 'Kategori Bekleyen Ürün',
            'currency' => 'TL',
            'min_purchase_price' => 55,
            'total_stock_quantity' => 5,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplierB->id,
                'supplier_group_code' => 'IL-WAIT-GROUP',
            ]],
            'meta' => [
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'price_snapshot' => ['list_price' => 55, 'vat_rate' => 20],
            ],
        ]);

        for ($index = 0; $index < 55; $index++) {
            $this->makeFlatProduct($supplierA, 'PAGE-SUP-' . $index, 'Sayfa Ürün ' . $index, $category->id);
        }

        $supplierResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?supplier=' . $supplierA->id . '&limit=500');

        $supplierResponse->assertOk();
        $supplierResponse->assertSeeText('YN-209025');
        $supplierResponse->assertDontSeeText('IL-WAIT-01');

        $waitingResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=IL-WAIT-01&flow_mode=category_waiting');

        $waitingResponse->assertOk();
        $waitingResponse->assertSeeText('IL-WAIT-01');

        $limitResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=PAGE-SUP-&limit=50');

        $limitResponse->assertOk();
        $limitResponse->assertSeeText('Toplam satır');
        $limitResponse->assertSeeText('55');
    }

    public function test_category_pending_product_stays_out_of_review_queue_but_remains_in_category_waiting_flow(): void
    {
        $supplier = $this->makeSupplier('CAT-WAIT-SUPER', 'Kategori Uyari Supplier');

        StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'CAT-WAIT-01',
            'sku' => 'CAT-WAIT-01',
            'product_name' => 'Kategori Eslesmemis Urun',
            'base_product_name' => 'Kategori Eslesmemis Urun',
            'name' => 'Kategori Eslesmemis Urun',
            'currency' => 'TL',
            'min_purchase_price' => 44,
            'total_stock_quantity' => 18,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_group_code' => 'CAT-WAIT-GRP',
            ]],
            'meta' => [
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'price_snapshot' => ['list_price' => 44, 'vat_rate' => 20],
            ],
        ]);

        $reviewResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=CAT-WAIT-01&flow_mode=review_queue');

        $reviewResponse->assertOk();
        $reviewResponse->assertSeeText('Filtrelere uygun ürün bulunamadı.');

        $waitingResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=CAT-WAIT-01&flow_mode=category_waiting');

        $waitingResponse->assertOk();
        $waitingResponse->assertSeeText('CAT-WAIT-01');
        $waitingResponse->assertSeeText('Kategori eşleşmemiş');
    }

    public function test_super_admin_product_panel_surfaces_review_queue_and_filters_new_items_bucket(): void
    {
        $supplier = $this->makeSupplier('REVIEW-SUPER', 'Review Supplier');
        $product = $this->makeFlatProduct($supplier, 'RV-1001', 'Review Kuyrugu Urunu');
        $source = SupplierSource::query()->where('supplier_id', $supplier->id)->firstOrFail();

        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $supplier->id,
            'run_type' => 'manual',
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'report_payload' => ['mode' => 'delta'],
        ]);

        ProductDataHubSyncChange::query()->create([
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'supplier_product_key' => 'RV-1001',
            'standard_product_id' => $product->id,
            'change_type' => 'new_product',
            'message' => 'Yeni ürün review bekliyor.',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=RV-1001&review_bucket=new_items');

        $response->assertOk();
        $response->assertSeeText('Bekleyen Kontrol Kuyruğu');
        $response->assertSeeText('Yeni Ürünler');
        $response->assertSeeText('Aktif kuyruk');
        $response->assertSeeText('RV-1001');
    }

    public function test_super_admin_product_panel_shows_category_mapping_button_and_opens_simple_drawer(): void
    {
        $supplier = $this->makeSupplier('DRAWER-SUPER', 'Akdeniz Promosyon');
        $source = SupplierSource::query()->where('supplier_id', $supplier->id)->firstOrFail();

        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'AK-1020-LACIVERT',
            'sku' => 'AK-1020-LACIVERT',
            'product_name' => '1020 Metal Tükenmez Rubber Gövde Kalem Lacivert',
            'base_product_name' => 'Metal Tükenmez Rubber Gövde Kalem',
            'name' => '1020 Metal Tükenmez Rubber Gövde Kalem Lacivert',
            'slug' => 'ak-1020-lacivert',
            'category' => 'Metal Kalemler',
            'standard_category_id' => null,
            'currency' => 'TL',
            'min_purchase_price' => 98,
            'max_purchase_price' => 98,
            'total_stock_quantity' => 42,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'image_url' => 'https://example.test/ak-1020.jpg',
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => 'AK-1020-LACIVERT',
                'supplier_group_code' => 'AK-1020',
                'supplier_category_name' => 'Metal Kalemler',
                'supplier_category_path' => 'Promosyon / Kalemler / Metal Kalemler',
            ]],
            'meta' => [
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'supplier_category_path' => 'Promosyon / Kalemler / Metal Kalemler',
                'price_snapshot' => ['list_price' => 98, 'vat_rate' => 20],
            ],
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Metal Kalemler',
            'supplier_category_path' => 'Promosyon / Kalemler / Metal Kalemler',
            'sample_product_names' => ['AK-1020 Lacivert Kalem', 'AK-1021 Siyah Kalem'],
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'confidence_score' => 84,
            'description' => 'Kalem sinyali bulundu.',
            'product_count' => 8,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=AK-1020-LACIVERT&category_mapping_product_id=' . $product->id);

        $response->assertOk();
        $response->assertSeeText('Kategori Eşle')
            ->assertSee('category_mapping_product_id=' . $product->id, false)
            ->assertSeeText('Tedarikçi kategorisini Prodelya standart kategorisine bağlayın.')
            ->assertSeeText('Metal Kalemler')
            ->assertSeeText('Hızlı kategori arama')
            ->assertSeeText('Eşle ve Kaydet')
            ->assertSeeText('Gelişmiş Eşleme Ekranında Aç')
            ->assertDontSeeText('CSV Export')
            ->assertDontSeeText('JSON Export')
            ->assertDontSeeText('Manuel Review Listesi')
            ->assertDontSeeText('Bulk Apply')
            ->assertSeeText('Karar Bekleyen Başlıklar')
            ->assertDontSeeText('fallback');
    }

    public function test_product_panel_quick_category_mapping_approves_mapping_and_logs_without_refreshing_product_or_tenant_category(): void
    {
        $supplier = $this->makeSupplier('MAP-SUPER', 'İlpen');
        $source = SupplierSource::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $targetCategory = StandardCategory::query()->permanentBackbone()->whereNotNull('parent_id')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();

        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'IL-3627',
            'sku' => 'IL-3627',
            'product_name' => 'IL-3627 Kategori Bekleyen Ürün',
            'base_product_name' => 'Kategori Bekleyen Ürün',
            'name' => 'IL-3627 Kategori Bekleyen Ürün',
            'slug' => 'il-3627',
            'category' => 'Vip Setler',
            'standard_category_id' => null,
            'currency' => 'TL',
            'min_purchase_price' => 150,
            'max_purchase_price' => 150,
            'total_stock_quantity' => 11,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => 'IL-3627',
                'supplier_group_code' => 'IL-3627',
                'supplier_category_name' => 'Vip Setler',
                'supplier_category_path' => 'Promosyon / Setler / Vip Setler',
            ]],
            'meta' => [
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'supplier_category_path' => 'Promosyon / Setler / Vip Setler',
                'price_snapshot' => ['list_price' => 150, 'vat_rate' => 20],
            ],
        ]);

        $tenantProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $product->id,
            'tenant_sku' => 'TEN-' . uniqid(),
            'name' => 'Tenant Ürün',
            'product_code' => 'TEN-IL-3627',
            'product_name' => 'Tenant Ürün',
            'slug' => 'tenant-il-3627',
            'standard_category_id' => null,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/tenant-il-3627.jpg',
            'display_price' => 150,
            'sale_price' => 150,
            'currency' => 'TL',
            'total_stock_quantity' => 11,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 11,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_category_name' => 'Vip Setler',
                'supplier_category_path' => 'Promosyon / Setler / Vip Setler',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => ['price_snapshot' => ['list_price' => 150, 'vat_rate' => 20]],
            'is_active' => true,
            'stock_quantity' => 11,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Vip Setler',
            'supplier_category_path' => 'Promosyon / Setler / Vip Setler',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'product_count' => 4,
            'sample_product_names' => ['IL-3627 Siyah VIP Set'],
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/product-panel/category-mappings/' . $mapping->id . '?search=IL-3627', [
                'standard_category_id' => $targetCategory->id,
            ])
            ->assertRedirect('/admin/super-admin/product-data-hub/product-panel?search=IL-3627');

        $mapping->refresh();
        $product->refresh();
        $tenantProduct->refresh();

        $this->assertSame('approved', $mapping->mapping_status);
        $this->assertSame('map', $mapping->decision_type);
        $this->assertSame($targetCategory->id, $mapping->standard_category_id);
        $this->assertSame('Ürün paneli hızlı eşleme ile kaydedildi.', $mapping->decision_note);
        $this->assertNull($product->standard_category_id);
        $this->assertNull($tenantProduct->standard_category_id);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => null,
            'new_standard_category_id' => $targetCategory->id,
            'action' => 'approved',
            'reason' => 'Ürün paneli hızlı eşleme ile kaydedildi.',
        ]);
        $this->assertSame(1, SupplierCategoryMappingLog::query()->where('mapping_id', $mapping->id)->count());
    }

    public function test_category_search_returns_only_active_permanent_categories(): void
    {
        $active = StandardCategory::query()->create([
            'parent_id' => null,
            'code' => 'PROMO-KALEM-TEST',
            'name' => 'Kalem Test',
            'slug' => 'kalem-test',
            'path' => 'Promosyon / Kalem Test',
            'depth' => 1,
            'sort_order' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'meta' => ['permanent_category_backbone' => true, 'supplier_dependent' => false],
        ]);

        StandardCategory::query()->create([
            'parent_id' => null,
            'code' => 'ARCHIVED-KALEM-TEST',
            'name' => 'Kalem Test Arşiv',
            'slug' => 'kalem-test-arsiv',
            'path' => 'Arşiv / Kalem Test',
            'depth' => 1,
            'sort_order' => 2,
            'is_active' => true,
            'visible_in_catalog' => true,
            'duplicate_status' => 'archived',
            'meta' => ['archived_by_category_reset' => true],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/super-admin/product-data-hub/categories/search?q=Kalem Test');

        $response->assertOk()
            ->assertJsonFragment(['id' => $active->id])
            ->assertJsonMissing(['code' => 'ARCHIVED-KALEM-TEST']);
    }

    public function test_product_data_hub_overview_uses_updated_product_panel_and_common_pool_descriptions(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub');

        $response->assertOk()
            ->assertSeeText('Senkron Sonuç Merkezi')
            ->assertSeeText('Bugün aksiyon gereken ürün var mı?')
            ->assertSeeText('Temiz Akış')
            ->assertSeeText('Teknik ve Bakım Geçişleri')
            ->assertSeeText('Ürün Paneli')
            ->assertSeeText('Standart Ürünler');
    }

    private function makeSupplier(string $code, string $name): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code,
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_name' => $name . ' XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => [
                'profile_key' => $code,
                'sync_policy' => ['sync_frequency' => 'daily'],
            ],
        ]);

        return $supplier;
    }

    private function makeFlatProduct(Supplier $supplier, string $code, string $name, ?int $categoryId = null): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => $name,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $code)),
            'standard_category_id' => $categoryId,
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 100,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 0,
            'is_active' => true,
            'visible_in_catalog' => true,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'category' => $name,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_product_code' => $code,
                'supplier_group_code' => $code,
            ]],
            'meta' => [
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
            ],
        ]);
    }

    private function makeParentProduct(Supplier $supplier, string $code, string $name): StandardProduct
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => 'Plastik Kalem',
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $code)),
            'standard_category_id' => $category->id,
            'currency' => 'TL',
            'min_purchase_price' => 48,
            'max_purchase_price' => 48,
            'total_stock_quantity' => 12,
            'supplier_count' => 1,
            'variant_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_product_code' => $code,
                'supplier_group_code' => 'GRP-TECH-0506',
            ]],
            'meta' => [
                'price_snapshot' => ['list_price' => 48, 'vat_rate' => 20],
            ],
        ]);
    }

    private function makeVariant(StandardProduct $product, string $code, string $color, int $stock, float $price, string $groupCode): StandardProductVariant
    {
        return StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => $code,
            'generated_variant_code' => $code,
            'variant_name' => 'Plastik Kalem',
            'variant_color' => $color,
            'variant_size' => null,
            'variant_attributes' => [],
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'stock_quantity' => $stock,
            'min_purchase_price' => $price,
            'max_purchase_price' => $price,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_group_code' => $groupCode,
                'variant_stock_code' => $code,
                'supplier_product_code' => $code,
            ],
            'meta' => [
                'price_snapshot' => ['list_price' => $price, 'vat_rate' => 20],
            ],
        ]);
    }
}
