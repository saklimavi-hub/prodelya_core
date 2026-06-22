<?php

namespace Tests\Feature;

use App\Models\CategoryAlias;
use App\Models\ProductCategorySuggestionLog;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\User;
use App\Services\ProductDataHub\NullProductImageAnalyzer;
use App\Services\ProductDataHub\ProductImageAnalyzerInterface;
use App\Services\ProductDataHub\SupplierCategoryDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductDataHubCategoryMappingCenterTest extends TestCase
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

    public function test_supplier_categories_can_be_scanned_for_all_four_suppliers(): void
    {
        $this->seedCategoryTargets();

        $service = app(SupplierCategoryDiscoveryService::class);

        $yeniNesil = $this->makeSource('YENI-NESIL', 'Yeni Nesil');
        $etkin = $this->makeSource('ETKIN', 'Etkin');
        $akdeniz = $this->makeSource('AKDENIZ', 'Akdeniz');
        $ilpen = $this->makeSource('ILPEN', 'İlpen');

        $ynResult = $service->scanSource($yeniNesil, [[
            'uid' => 'YN-1',
            'kod' => '1001',
            'kodgrup' => '1001',
            'isim' => 'Termos Şişe',
            'kategori' => 'Termos Matara',
            'resim1' => 'yn-1.jpg',
            'fiyat' => '55.00',
            'kdv' => '20',
        ]], true);

        $etkinResult = $service->scanSource($etkin, [[
            'urun_id' => 'ET-1',
            'urun_kodu' => 'USB-1',
            'urun_isim' => 'Metal USB Bellek',
            'kategori_adi' => 'USB Bellekler',
            'resim1' => 'etkin-usb.jpg',
            'urun_fiyat' => '90.00',
            'fiyat_kdv' => '20',
        ]], true);

        $akdenizResult = $service->scanSource($akdeniz, [[
            'urun_id' => 'AK-1',
            'urunkodu' => 'PB-4007',
            'urunattr_id' => 'PB-4007-1',
            'urunattrgr' => 'PB-4007',
            'urunattradi' => 'PB-4007 Siyah',
            'urunadi' => 'Powerbank',
            'kategori' => 'Powerbanklar',
            'stokresim' => 'akdeniz-powerbank.jpg',
            'listefiyati' => '986.00',
            'netfiyat' => '542.30',
            'kdvorani' => '20',
            'kur' => 'TL',
        ]], true);

        $ilpenResult = $service->scanSource($ilpen, [[
            'UrunKartiID' => 'IL-1',
            'UrunAdi' => 'Twist Tükenmez Kalem',
            'UrunGrupKodu' => '1173',
            'KategoriMain' => 'Promosyon',
            'KategoriSub' => 'Kalemler',
            'ResimUrl' => 'ilpen-kalem.jpg',
        ]], true);

        $this->assertSame(1, $ynResult['summary']['category_count']);
        $this->assertSame(1, $etkinResult['summary']['category_count']);
        $this->assertSame(1, $akdenizResult['summary']['category_count']);
        $this->assertSame(1, $ilpenResult['summary']['category_count']);

        $this->assertDatabaseHas('supplier_category_mappings', [
            'supplier_source_id' => $yeniNesil->id,
            'source_category' => 'Termos Matara',
        ]);
        $this->assertDatabaseHas('supplier_category_mappings', [
            'supplier_source_id' => $etkin->id,
            'source_category' => 'USB Bellekler',
        ]);
        $this->assertDatabaseHas('supplier_category_mappings', [
            'supplier_source_id' => $akdeniz->id,
            'source_category' => 'Powerbanklar',
        ]);
        $this->assertDatabaseHas('supplier_category_mappings', [
            'supplier_source_id' => $ilpen->id,
            'source_category' => 'Kalemler',
        ]);
    }

    public function test_alias_match_produces_high_confidence_suggestion(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil');

        CategoryAlias::query()->create([
            'standard_category_id' => $targets['usb']->id,
            'alias_name' => 'USB Flash',
            'normalized_alias' => 'usb flash',
            'supplier_id' => null,
            'source_type' => 'manual',
            'confidence_score' => 99,
            'is_active' => true,
        ]);

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'uid' => 'YN-USB',
            'kod' => '2001',
            'kodgrup' => '2001',
            'isim' => 'Metal USB Flash',
            'kategori' => 'USB Flash',
            'resim1' => 'usb-flash.jpg',
            'fiyat' => '80.00',
            'kdv' => '20',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame($targets['usb']->id, $category['standard_category_id']);
        $this->assertSame('alias', $category['decision_type']);
        $this->assertSame('auto_approved', $category['mapping_status']);
        $this->assertGreaterThanOrEqual(98.0, $category['confidence_score']);
        $this->assertContains('Alias eşleşmesi bulundu', $category['suggestion_reasons']);
    }

    public function test_twin_view_candidate_is_marked_for_umbrella_categories(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'ET-UMB',
            'urun_kodu' => 'UMB-1',
            'urun_isim' => 'Kurumsal Şemsiye',
            'kategori_adi' => 'Şemsiyeler',
            'resim1' => 'umbrella.jpg',
            'urun_fiyat' => '120.00',
            'fiyat_kdv' => '20',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame($targets['umbrella']->id, $category['standard_category_id']);
        $this->assertSame('twin_view', $category['decision_type']);
        $this->assertTrue((bool) data_get($category, 'suggestion_meta.twin_view_candidate'));
    }

    public function test_normal_mousepad_prefers_promo_desktop_category(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-MP',
            'urunkodu' => 'MP-100',
            'urunattr_id' => 'MP-100-A',
            'urunattrgr' => 'MP-100',
            'urunattradi' => 'Mousepad',
            'urunadi' => 'Nova Taban Mousepad',
            'urunaciklamasi' => 'Baskı alanı geniş eva taban mousepad ve bardakaltlığı seti',
            'kategori' => 'Mousepad',
            'discat_name' => 'Masaüstü Promosyon',
            'listefiyati' => '90.00',
            'netfiyat' => '60.00',
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokresim' => 'mousepad.jpg',
        ]], false);

        $category = $result['categories'][0];

        $this->assertNotNull($category['standard_category_id']);
        $this->assertTrue(
            str_contains($category['target_category'], 'Matbaa')
            || str_contains($category['target_category'], 'Ofis')
            || str_contains($category['target_category'], 'Masaüstü')
            || str_contains($category['target_category'], 'Mousepad')
        );
        $this->assertNotSame('conflict', $category['mapping_status']);
    }

    public function test_wireless_mousepad_prefers_technology_category(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-WMP',
            'urunkodu' => 'WMP-100',
            'urunattr_id' => 'WMP-100-A',
            'urunattrgr' => 'WMP-100',
            'urunattradi' => 'Wireless Mousepad',
            'urunadi' => 'Wireless Mousepad',
            'urunaciklamasi' => 'Kablosuz şarj destekli USB Qi telefon şarj özellikli teknoloji ürünü',
            'kategori' => 'Mousepad',
            'discat_name' => 'Teknoloji Ürünleri',
            'listefiyati' => '190.00',
            'netfiyat' => '150.00',
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokresim' => 'wireless-mousepad.jpg',
        ]], false);

        $category = $result['categories'][0];

        $this->assertStringContainsString('Teknolojik Ürünler', $category['target_category']);
        $this->assertStringContainsString('Wireless Mousepad', $category['target_category']);
        $this->assertGreaterThan(0, data_get($category, 'suggestion_meta.wireless_signal_count', 0));
    }

    public function test_conflicting_mousepad_signals_are_marked_for_review(): void
    {
        $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-CMP',
            'urunkodu' => 'CMP-100',
            'urunattr_id' => 'CMP-100-A',
            'urunattrgr' => 'CMP-100',
            'urunattradi' => 'Wireless Mousepad',
            'urunadi' => 'Wireless Mousepad EVA',
            'urunaciklamasi' => 'Kablosuz şarj destekli USB mousepad, baskı alanı geniş eva taban',
            'kategori' => 'Wireless Mousepad',
            'discat_name' => 'Teknoloji Ürünleri',
            'listefiyati' => '210.00',
            'netfiyat' => '180.00',
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokresim' => 'conflict-mousepad.jpg',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame('conflict', $category['mapping_status']);
        $this->assertTrue((bool) data_get($category, 'suggestion_meta.conflict'));
    }

    public function test_merge_candidate_is_surfaced_for_calendar_variants(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'uid' => 'YN-TKV',
            'kod' => '3001',
            'kodgrup' => '3001',
            'isim' => 'Gemici Takvim',
            'kategori' => 'Gemici Takvimler',
            'resim1' => 'gemici-takvim.jpg',
            'fiyat' => '35.00',
            'kdv' => '20',
        ]], false);

        $category = $result['categories'][0];

        $this->assertStringContainsString('Takvimler', $category['target_category']);
        $this->assertSame($targets['print_gemici_calendar']->id, $category['standard_category_id']);
        $this->assertSame('map', $category['decision_type']);
        $this->assertSame('calendar_gemici', data_get($category, 'suggestion_meta.special_rule'));
    }

    public function test_category_mapping_center_page_renders_queue_actions_and_samples(): void
    {
        $targets = $this->seedCategoryTargets();
        $supplier = Supplier::query()->create([
            'name' => 'Test Tedarikçi',
            'code' => 'TEST-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Test XML',
            'config' => ['format' => 'xml', 'product_node_path' => 'urunler'],
            'status' => 'active',
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $targets['usb']->id,
            'source_category' => 'USB Flash',
            'supplier_category_code' => 'USB-001',
            'supplier_category_path' => 'Teknoloji > USB Flash',
            'normalized_name' => 'usb flash',
            'product_count' => 3,
            'sample_product_names' => ['Metal USB Flash', 'USB Flash 16 GB'],
            'sample_image_urls' => ['https://example.test/usb-flash.jpg'],
            'suggestion_meta' => [
                'sample_keywords' => ['usb', 'flash', 'metal'],
                'reason_codes' => ['alias'],
                'alias_candidate' => true,
                'suggestion_state' => 'high_confidence',
            ],
            'target_category' => $targets['usb']->full_path,
            'description' => 'Alias eşleşmesi bulundu',
            'is_active' => true,
            'mapping_status' => 'auto_approved',
            'decision_type' => 'alias',
            'confidence_score' => 98,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=approved&view_mode=detail');

        $mappingId = SupplierCategoryMapping::query()->where('source_category', 'USB Flash')->value('id');

        $response->assertOk();
        $response->assertSee('Kategori Eşleme Kuyruğu');
        $response->assertSee('Kategori Eşleme Kuyruğu');
        $response->assertSee('Tedarikçi Kategori Tara');
        $response->assertSee('Alias Kaydet');
        $response->assertSee('Ayrı Bırak');
        $response->assertSee('Eşlemeyi İptal Et');
        $response->assertSee('Sol Blok — Tedarikçi Kategorisi');
        $response->assertSee('Orta Blok — Sistem Önerisi');
        $response->assertSee('Sağ Blok — Karar');
        $response->assertSee('class="pd-hidden-form"', false);
        $response->assertSee('form="mapping-cancel-' . $mappingId . '"', false);
        $response->assertDontSee('action="http://localhost/admin/super-admin/product-data-hub/category-mappings/' . $mappingId . '/cancel" class="mt-2"', false);
        $response->assertSee('Metal USB Flash');
        $response->assertSee('USB Bellekler');
    }

    public function test_category_mapping_center_alias_route_resolves_to_same_screen(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mapping-center')
            ->assertOk()
            ->assertSee('Kategori Eşleme');
    }

    public function test_null_image_analyzer_is_bound_and_returns_zero_score(): void
    {
        $analyzer = app(ProductImageAnalyzerInterface::class);

        $this->assertInstanceOf(NullProductImageAnalyzer::class, $analyzer);
        $this->assertSame([
            'image_score' => 0.0,
            'status' => 'manual_review',
            'message' => 'Görsel sinyali manuel kontrol edilecek.',
            'signals' => [],
        ], $analyzer->analyze('https://example.test/mousepad.jpg'));
    }

    public function test_scan_persists_product_category_suggestion_logs(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-WMP',
            'urunkodu' => 'WMP-100',
            'urunattr_id' => 'WMP-100-A',
            'urunattrgr' => 'WMP-100',
            'urunattradi' => 'Wireless Mousepad',
            'urunadi' => 'Wireless Mousepad',
            'urunaciklamasi' => 'Kablosuz şarj destekli USB Qi telefon şarj özellikli teknoloji ürünü',
            'kategori' => 'Mousepad',
            'discat_name' => 'Teknoloji Ürünleri',
            'listefiyati' => '190.00',
            'netfiyat' => '150.00',
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokresim' => 'wireless-mousepad.jpg',
        ]], true);

        $log = ProductCategorySuggestionLog::query()->firstOrFail();

        $this->assertSame($source->id, $log->supplier_source_id);
        $this->assertSame('WMP-100', $log->supplier_product_code);
        $this->assertSame('Wireless Mousepad', $log->supplier_product_name);
        $this->assertNotNull($log->supplier_category_name);
        $this->assertNotNull($log->confidence_score);
        $this->assertSame(0.0, (float) $log->image_score);
        $this->assertContains($log->decision_status, ['accepted', 'review_required', 'pending']);
        $this->assertNotNull($log->suggested_category_id);
        $this->assertStringContainsString(
            'Wireless Mousepad',
            StandardCategory::query()->findOrFail($log->suggested_category_id)->full_path
        );
    }

    public function test_category_scan_completes_when_gallery_enrichment_product_url_is_missing(): void
    {
        $this->seedCategoryTargets();
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil');
        $source->update([
            'config' => array_merge($source->config ?? [], [
                'enrich_gallery_from_product_page' => true,
            ]),
        ]);

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'uid' => 'YN-URL-EMPTY',
            'kod' => '4001',
            'kodgrup' => '4001',
            'isim' => 'URL Olmadan Taranan Kalem',
            'kategori' => 'Kalemler',
            'resim1' => 'kalem.jpg',
            'fiyat' => '20.00',
            'kdv' => '20',
        ]], false);

        $this->assertSame(1, $result['summary']['category_count']);
        $this->assertNotEmpty($result['categories']);
    }

    public function test_special_rules_map_gift_sets_to_single_gift_set_category_with_feature_suggestion(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'ET-VIP',
            'urun_kodu' => 'VIP-1',
            'urun_isim' => 'VIP Kutulu Kalem Seti',
            'kategori_adi' => 'VIP Setler',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame($targets['gift_sets']->id, $category['standard_category_id']);
        $this->assertSame('VIP', data_get($category, 'suggestion_meta.feature_suggestions.set_tipi'));
        $this->assertTrue((bool) data_get($category, 'suggestion_meta.safe_auto_approve'));
    }

    public function test_special_rules_map_cup_materials_to_single_cups_category(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-KUPA',
            'urunkodu' => 'KUPA-1',
            'urunadi' => 'Seramik Kupa',
            'kategori' => 'Seramik Kupalar',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame($targets['cups']->id, $category['standard_category_id']);
        $this->assertSame('seramik', data_get($category, 'suggestion_meta.feature_suggestions.malzeme'));
    }

    public function test_special_rules_map_calendars_to_print_calendar_tree(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil');

        $result = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'uid' => 'YN-GEMICI',
            'kod' => 'TKV-1',
            'isim' => 'Gemici Takvim',
            'kategori' => 'Gemici Takvimler',
        ]], false);

        $category = $result['categories'][0];

        $this->assertSame($targets['print_gemici_calendar']->id, $category['standard_category_id']);
        $this->assertStringContainsString('Matbaa Ürünleri / Takvimler', $category['target_category']);
    }

    public function test_special_rules_split_wireless_and_classic_mousepad(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');

        $wireless = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-WMP',
            'urunkodu' => 'WMP-1',
            'urunadi' => 'Wireless Mousepad Qi Şarj',
            'kategori' => 'Wireless Mousepad',
        ]], false)['categories'][0];

        $classic = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'urun_id' => 'AK-CMP',
            'urunkodu' => 'CMP-1',
            'urunadi' => 'Baskılı Mousepad',
            'kategori' => 'Baskılı Mousepad',
        ]], false)['categories'][0];

        $this->assertSame($targets['wireless_mousepad']->id, $wireless['standard_category_id']);
        $this->assertSame($targets['classic_mousepad']->id, $classic['standard_category_id']);
        $this->assertTrue((bool) data_get($wireless, 'suggestion_meta.safe_auto_approve'));
        $this->assertTrue((bool) data_get($classic, 'suggestion_meta.review_required'));
    }

    public function test_special_rules_keep_set_boxes_in_review(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ILPEN', 'İlpen');

        $category = app(SupplierCategoryDiscoveryService::class)->scanSource($source, [[
            'UrunKartiID' => 'IL-KUTU',
            'UrunAdi' => 'Boş Set Kutusu',
            'KategoriSub' => 'Set Kutuları',
        ]], false)['categories'][0];

        $this->assertSame($targets['set_boxes']->id, $category['standard_category_id']);
        $this->assertTrue((bool) data_get($category, 'suggestion_meta.review_required'));
        $this->assertFalse((bool) data_get($category, 'suggestion_meta.safe_auto_approve'));
    }

    public function test_special_rules_keep_opener_magnet_variants_separate(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');
        $service = app(SupplierCategoryDiscoveryService::class);

        $opener = $service->scanSource($source, [['urun_id' => 'A1', 'urun_isim' => 'Metal Açacak', 'kategori_adi' => 'Açacaklar']], false)['categories'][0];
        $magnet = $service->scanSource($source, [['urun_id' => 'M1', 'urun_isim' => 'Buzdolabı Magnet', 'kategori_adi' => 'Magnetler']], false)['categories'][0];
        $openerMagnet = $service->scanSource($source, [['urun_id' => 'AM1', 'urun_isim' => 'Açacaklı Magnet', 'kategori_adi' => 'Açacaklı Magnetler']], false)['categories'][0];

        $this->assertSame($targets['opener']->id, $opener['standard_category_id']);
        $this->assertSame($targets['magnet']->id, $magnet['standard_category_id']);
        $this->assertSame($targets['opener_magnet']->id, $openerMagnet['standard_category_id']);
    }

    public function test_auto_approve_only_accepts_safe_auto_approve_mappings_and_logs(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        $safe = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'VIP Setler',
            'standard_category_id' => $targets['gift_sets']->id,
            'target_category' => $targets['gift_sets']->full_path,
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'confidence_score' => 96,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);

        $review = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Set Kutuları',
            'standard_category_id' => $targets['set_boxes']->id,
            'target_category' => $targets['set_boxes']->full_path,
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'confidence_score' => 98,
            'suggestion_meta' => ['safe_auto_approve' => false, 'review_required' => true],
            'is_active' => true,
        ]);

        $result = app(SupplierCategoryDiscoveryService::class)->autoApproveHighConfidence();

        $this->assertSame(1, $result['approved']);
        $this->assertSame('approved', $safe->fresh()->mapping_status);
        $this->assertSame('pending', $review->fresh()->mapping_status);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $safe->id,
            'action' => 'approved',
        ]);
    }

    public function test_remap_supplier_categories_dry_run_does_not_change_mappings(): void
    {
        $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'VIP Setler',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'is_active' => true,
        ]);

        $before = SupplierCategoryMapping::query()
            ->get(['id', 'updated_at'])
            ->mapWithKeys(fn (SupplierCategoryMapping $mapping) => [$mapping->id => optional($mapping->updated_at)->toDateTimeString()])
            ->all();

        $this->artisan('product-data-hub:remap-supplier-categories', ['--dry-run' => true])
            ->assertExitCode(0);

        $after = SupplierCategoryMapping::query()
            ->get(['id', 'updated_at'])
            ->mapWithKeys(fn (SupplierCategoryMapping $mapping) => [$mapping->id => optional($mapping->updated_at)->toDateTimeString()])
            ->all();

        $this->assertSame($before, $after);
    }

    public function test_remap_apply_only_safe_requires_confirm(): void
    {
        $this->artisan('product-data-hub:remap-supplier-categories', [
            '--apply' => true,
            '--only-safe' => true,
        ])->assertExitCode(1);
    }

    public function test_remap_apply_only_safe_accepts_only_safe_rows_and_keeps_refresh_pending(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        $safe = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'VIP Setler',
            'standard_category_id' => $targets['gift_sets']->id,
            'target_category' => $targets['gift_sets']->full_path,
            'mapping_status' => 'auto_approved',
            'decision_type' => 'map',
            'confidence_score' => 96,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);

        $review = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Set Kutuları',
            'standard_category_id' => $targets['set_boxes']->id,
            'target_category' => $targets['set_boxes']->full_path,
            'mapping_status' => 'needs_review',
            'decision_type' => 'map',
            'confidence_score' => 98,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => true],
            'is_active' => true,
        ]);

        $archived = $this->makeStandardCategory('ARCHIVED-OLD', 'Eski', 'Eski');
        $archived->update([
            'duplicate_status' => 'archived',
            'meta' => ['archived_by_category_reset' => true],
        ]);
        $archiveTarget = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Eski Kategori',
            'standard_category_id' => $archived->id,
            'target_category' => $archived->full_path,
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'confidence_score' => 99,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);

        StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'sku' => 'STD-VIP-1',
            'standard_product_code' => 'STD-VIP-1',
            'name' => 'VIP Set Ürünü',
            'category' => 'VIP Setler',
            'is_active' => true,
        ]);

        app(SupplierCategoryDiscoveryService::class)->applySafeCategorySuggestions(collect([[
            'source' => $source,
            'categories' => [
                [
                    'source_category' => 'VIP Setler',
                    'supplier_category_code' => null,
                    'supplier_category_path' => 'Hediyelik > VIP Setler',
                    'supplier_category_level' => 2,
                    'normalized_name' => 'vip setler',
                    'product_count' => 1,
                    'sample_product_names' => ['VIP Set Ürünü'],
                    'sample_image_urls' => [],
                    'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
                    'standard_category_id' => $targets['gift_sets']->id,
                    'target_category' => $targets['gift_sets']->full_path,
                    'mapping_status' => 'auto_approved',
                    'decision_type' => 'map',
                    'suggestion_reason_text' => 'Test safe öneri',
                    'confidence_score' => 96,
                ],
                [
                    'source_category' => 'Set Kutuları',
                    'supplier_category_code' => null,
                    'supplier_category_path' => 'Ambalaj > Set Kutuları',
                    'supplier_category_level' => 2,
                    'normalized_name' => 'set kutulari',
                    'product_count' => 1,
                    'sample_product_names' => ['Set Kutusu'],
                    'sample_image_urls' => [],
                    'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => true],
                    'standard_category_id' => $targets['set_boxes']->id,
                    'target_category' => $targets['set_boxes']->full_path,
                    'mapping_status' => 'needs_review',
                    'decision_type' => 'map',
                    'suggestion_reason_text' => 'Review gerekli',
                    'confidence_score' => 98,
                ],
                [
                    'source_category' => 'Eski Kategori',
                    'supplier_category_code' => null,
                    'supplier_category_path' => 'Eski',
                    'supplier_category_level' => 1,
                    'normalized_name' => 'eski kategori',
                    'product_count' => 1,
                    'sample_product_names' => ['Eski Ürün'],
                    'sample_image_urls' => [],
                    'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
                    'standard_category_id' => $archived->id,
                    'target_category' => $archived->full_path,
                    'mapping_status' => 'auto_approved',
                    'decision_type' => 'map',
                    'suggestion_reason_text' => 'Arşiv hedef',
                    'confidence_score' => 99,
                ],
            ],
        ]]));

        $this->assertSame('approved', $safe->fresh()->mapping_status);
        $this->assertSame('needs_review', $review->fresh()->mapping_status);
        $this->assertSame('pending', $archiveTarget->fresh()->mapping_status);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $safe->id,
            'action' => 'approved',
        ]);
        $this->assertNull(StandardProduct::query()->where('standard_product_code', 'STD-VIP-1')->value('standard_category_id'));
    }

    public function test_refresh_category_projections_dry_run_reports_without_changes(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'VIP Setler',
            'standard_category_id' => $targets['gift_sets']->id,
            'target_category' => $targets['gift_sets']->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'confidence_score' => 96,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);

        $standardProduct = $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-VIP-2',
            'source_name' => 'VIP Set Ürünü',
            'supplier_category_name' => 'VIP Setler',
        ], 'STD-VIP-2');
        $tenant = TenantAccount::query()->firstOrFail();

        $tenantProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-VIP-2',
            'name' => 'VIP Set Tenant',
            'product_code' => 'TEN-VIP-2',
            'product_name' => 'VIP Set Tenant',
            'meta' => ['supplier_category_name' => 'VIP Setler'],
            'is_active' => true,
        ]);

        $this->artisan('product-data-hub:refresh-category-projections', [
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Mapping’e bağlanabilen standard product: 1')
            ->expectsOutputToContain('Mapping’e bağlanabilen tenant catalog product: 1')
            ->assertExitCode(0);

        $this->assertNull($standardProduct->fresh()->standard_category_id);
        $this->assertNull($tenantProduct->fresh()->standard_category_id);
    }

    public function test_refresh_dry_run_matches_standard_products_by_category_code_path_and_normalized_name(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');

        $this->approvedMapping($source, $targets['gift_sets'], 'Kodlu Kategori', 'CAT-001', null);
        $this->approvedMapping($source, $targets['cups'], 'Yol Kategorisi', null, 'Promosyon > Kupalar');
        $this->approvedMapping($source, $targets['opener'], 'VIP Setler', null, null);

        $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-CODE',
            'source_name' => 'Kodlu Ürün',
            'supplier_category_name' => 'Başka Ad',
            'normalized_payload' => ['supplier_category_id' => 'CAT-001'],
        ], 'STD-CODE');
        $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-PATH',
            'source_name' => 'Path Ürün',
            'supplier_category_name' => 'Kupalar',
            'raw_payload' => ['KategoriMain' => 'Promosyon', 'KategoriSub' => 'Kupalar'],
        ], 'STD-PATH');
        $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-NAME',
            'source_name' => 'Name Ürün',
            'supplier_category_name' => 'vip   setler',
        ], 'STD-NAME');

        $this->artisan('product-data-hub:refresh-category-projections', ['--dry-run' => true])
            ->expectsOutputToContain('Mapping’e bağlanabilen standard product: 3')
            ->expectsOutputToContain('safe_refresh standard product: 3')
            ->expectsOutputToContain('code_exact: 1')
            ->assertExitCode(0);

        $this->assertNull(StandardProduct::query()->where('standard_product_code', 'STD-CODE')->value('standard_category_id'));
    }

    public function test_refresh_dry_run_does_not_match_when_source_mismatches_and_reports_no_match(): void
    {
        $targets = $this->seedCategoryTargets();
        $mappingSource = $this->makeSource('ETKIN', 'Etkin');
        $productSource = $this->makeSource('ETKIN-OTHER', 'Etkin');

        $this->approvedMapping($mappingSource, $targets['gift_sets'], 'VIP Setler', null, null);
        $this->standardProductFromRaw($productSource, [
            'source_product_id' => 'RAW-MISMATCH',
            'source_name' => 'Source Mismatch Ürün',
            'supplier_category_name' => 'VIP Setler',
        ], 'STD-MISMATCH');

        $this->artisan('product-data-hub:refresh-category-projections', ['--dry-run' => true])
            ->expectsOutputToContain('Mapping’e bağlanabilen standard product: 0')
            ->expectsOutputToContain('no_match standard product: 1')
            ->assertExitCode(0);
    }

    public function test_refresh_dry_run_ignores_archived_target_mapping_and_tenant_follows_standard_match(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz');
        $tenant = TenantAccount::query()->firstOrFail();
        $archived = $this->makeStandardCategory('ARCHIVED-REFRESH', 'Eski Refresh', 'Eski Refresh');
        $archived->update(['duplicate_status' => 'archived', 'meta' => ['archived_by_category_reset' => true]]);

        $this->approvedMapping($source, $archived, 'Arşivli Kategori', null, null);
        $this->approvedMapping($source, $targets['wireless_mousepad'], 'Wireless Mousepad', null, null);
        $standardProduct = $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-WMP-REFRESH',
            'source_name' => 'Wireless Mousepad',
            'supplier_category_name' => 'Wireless Mousepad',
        ], 'STD-WMP-REFRESH');

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-WMP-REFRESH',
            'name' => 'Wireless Mousepad Tenant',
            'product_code' => 'TEN-WMP-REFRESH',
            'product_name' => 'Wireless Mousepad Tenant',
            'is_active' => true,
        ]);

        $this->artisan('product-data-hub:refresh-category-projections', ['--dry-run' => true])
            ->expectsOutputToContain('Mapping’e bağlanabilen standard product: 1')
            ->expectsOutputToContain('Mapping’e bağlanabilen tenant catalog product: 1')
            ->assertExitCode(0);

        $this->assertNull($standardProduct->fresh()->standard_category_id);
    }

    public function test_refresh_apply_requires_confirm_and_only_safe(): void
    {
        $this->artisan('product-data-hub:refresh-category-projections', [
            '--apply' => true,
            '--only-safe' => true,
        ])->assertExitCode(1);

        $this->artisan('product-data-hub:refresh-category-projections', [
            '--apply' => true,
            '--confirm' => 'SAFE-CATEGORY-REFRESH',
        ])->assertExitCode(1);
    }

    public function test_refresh_apply_updates_only_safe_standard_and_tenant_categories_and_preserves_warnings(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');
        $tenant = TenantAccount::query()->firstOrFail();
        $this->approvedMapping($source, $targets['gift_sets'], 'VIP Setler', null, null);
        $safeProduct = $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-SAFE-APPLY',
            'source_name' => 'VIP Set Ürünü',
            'supplier_category_name' => 'VIP Setler',
        ], 'STD-SAFE-APPLY');
        $noMatchProduct = $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-NOMATCH-APPLY',
            'source_name' => 'No Match Ürün',
            'supplier_category_name' => 'Bilinmeyen Kategori',
        ], 'STD-NOMATCH-APPLY');

        $tenantProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $safeProduct->id,
            'tenant_sku' => 'TEN-SAFE-APPLY',
            'name' => 'VIP Set Tenant',
            'product_code' => 'TEN-SAFE-APPLY',
            'product_name' => 'VIP Set Tenant',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'meta' => [
                'warning_snapshot' => ['category_missing', 'price_missing', 'net_price_warning'],
                'warnings' => ['Kategori eksik', 'supplier_warning'],
            ],
            'is_active' => true,
        ]);

        $this->artisan('product-data-hub:refresh-category-projections', [
            '--apply' => true,
            '--only-safe' => true,
            '--confirm' => 'SAFE-CATEGORY-REFRESH',
        ])
            ->expectsOutputToContain('updated_standard_product_count: 1')
            ->expectsOutputToContain('updated_tenant_catalog_count: 1')
            ->assertExitCode(0);

        $safeProduct->refresh();
        $tenantProduct->refresh();
        $this->assertSame($targets['gift_sets']->id, $safeProduct->standard_category_id);
        $this->assertSame($targets['gift_sets']->id, $tenantProduct->standard_category_id);
        $this->assertSame('safe_mapping_refresh', data_get($safeProduct->meta, 'category_refresh_source'));
        $this->assertSame('safe_mapping_refresh', data_get($tenantProduct->meta, 'category_refresh_source'));
        $this->assertFalse(in_array('category_missing', data_get($tenantProduct->meta, 'warning_snapshot', []), true));
        $this->assertContains('price_missing', data_get($tenantProduct->meta, 'warning_snapshot', []));
        $this->assertContains('net_price_warning', data_get($tenantProduct->meta, 'warning_snapshot', []));
        $this->assertContains('supplier_warning', data_get($tenantProduct->meta, 'warnings', []));
        $this->assertTrue((bool) $tenantProduct->visible_in_catalog);
        $this->assertTrue((bool) $tenantProduct->visible_in_quote);
        $this->assertNull($noMatchProduct->fresh()->standard_category_id);
        $this->assertDatabaseHas('product_data_hub_sync_runs', [
            'run_type' => 'category_refresh',
            'status' => 'success',
        ]);
    }

    public function test_refresh_apply_does_not_update_review_refresh_matches(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin');
        $this->approvedMapping($source, $targets['gift_sets'], 'Çoklu Kategori A', null, null)
            ->update(['normalized_name' => 'coklu kategori']);
        $this->approvedMapping($source, $targets['cups'], 'Çoklu Kategori B', null, null)
            ->update(['normalized_name' => 'coklu kategori']);
        $reviewProduct = $this->standardProductFromRaw($source, [
            'source_product_id' => 'RAW-REVIEW-APPLY',
            'source_name' => 'Review Ürün',
            'supplier_category_name' => 'Çoklu Kategori',
        ], 'STD-REVIEW-APPLY');

        $this->artisan('product-data-hub:refresh-category-projections', [
            '--apply' => true,
            '--only-safe' => true,
            '--confirm' => 'SAFE-CATEGORY-REFRESH',
        ])->assertExitCode(0);

        $this->assertNull($reviewProduct->fresh()->standard_category_id);
    }

    public function test_category_mapping_screen_shows_safe_review_and_no_target_filters(): void
    {
        $this->seedCategoryTargets();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced');

        $response->assertOk();
        $response->assertSee('Safe Apply Adayı');
        $response->assertSee('Hedef Bulunamayan');
        $response->assertSee('Review Required');
        $response->assertSee('Refresh Bekleyen Ürün');
        $response->assertSee('Refresh Bekleyen Tenant');
    }

    public function test_mapping_queue_supplier_dropdown_deduplicates_real_active_supplier_labels(): void
    {
        $targets = $this->seedCategoryTargets();
        $firstSource = $this->makeSource('YENI-NESIL', 'Yeni Nesil');
        $secondSource = $this->makeSource('YENI-NESIL-ALT', 'Yeni Nesil');
        $archivedSource = $this->makeSource('TMP-YENI-NESIL', 'Yeni Nesil');
        $archivedSource->update([
            'status' => 'inactive',
            'config' => ['profile_key' => 'TMP-YENI-NESIL', 'lifecycle_state' => 'archived'],
        ]);

        $this->approvedMapping($firstSource, $targets['cups'], 'Kupalar', null, 'İçecek / Kupalar')
            ->update(['mapping_status' => 'pending']);
        $this->approvedMapping($secondSource, $targets['gift_sets'], 'VIP Setler', null, 'Set / VIP')
            ->update(['mapping_status' => 'pending']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), '>Yeni Nesil</option>'));
    }

    public function test_default_mapping_queue_excludes_approved_records_and_approved_filter_shows_them(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon');

        $pending = $this->approvedMapping($source, $targets['cups'], 'Bekleyen Kupalar', null, 'İçecek / Kupalar');
        $pending->update(['mapping_status' => 'pending']);
        $approved = $this->approvedMapping($source, $targets['gift_sets'], 'Onaylı Setler', null, 'Set / VIP');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings')
            ->assertOk()
            ->assertDontSeeText('Onaylı Setler');

        $this->assertSame('pending', $pending->fresh()->mapping_status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=approved')
            ->assertOk()
            ->assertSeeText('Onaylı Setler');

        $this->assertTrue($approved->fresh()->isApproved());
    }

    public function test_mapping_queue_target_missing_and_review_filters_are_scoped(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('AKDENIZ', 'Akdeniz Promosyon');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Hedefsiz Kategori',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'suggestion_meta' => [],
            'is_active' => true,
        ]);
        $review = $this->approvedMapping($source, $targets['set_boxes'], 'Review Set Kutuları', null, 'Kutular');
        $review->update([
            'mapping_status' => 'needs_review',
            'suggestion_meta' => ['review_required' => true],
        ]);
        $approved = $this->approvedMapping($source, $targets['cups'], 'Approved Kupalar', null, 'Kupalar');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=target_missing')
            ->assertOk()
            ->assertSeeText('Hedefsiz Kategori')
            ->assertDontSeeText('Approved Kupalar');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=review_required')
            ->assertOk()
            ->assertSeeText('Review Set Kutuları')
            ->assertDontSeeText('Approved Kupalar');
    }

    public function test_category_target_search_endpoint_uses_active_permanent_backbone_only(): void
    {
        $targets = $this->seedCategoryTargets();
        $archived = $this->makeStandardCategory('ARCHIVED-KUPA-ESKI', 'Eski Kupa', 'Eski / Kupa');
        $archived->update([
            'duplicate_status' => 'archived',
            'meta' => ['archived_by_category_reset' => true],
        ]);
        $hidden = $this->makeStandardCategory('PROMO-HIDDEN-KUPA', 'Gizli Kupa', 'Promosyon Ürünleri / Gizli Kupa');
        $hidden->update(['visible_in_catalog' => false]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/super-admin/product-data-hub/categories/search?q=kupa');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $targets['cups']->id,
                'code' => $targets['cups']->code,
            ])
            ->assertJsonMissing(['id' => $archived->id])
            ->assertJsonMissing(['id' => $hidden->id]);
    }

    public function test_saved_mapping_moves_out_of_default_queue_but_remains_visible_in_approved_filter(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ILPEN', 'İlpen');
        $mapping = $this->approvedMapping($source, $targets['cups'], 'Operatör Kupaları', null, 'İçecek / Kupalar');
        $mapping->update(['mapping_status' => 'pending']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put("/admin/super-admin/product-data-hub/category-mappings/{$mapping->id}", [
                'standard_category_id' => $targets['cups']->id,
                'mapping_status' => 'approved',
                'decision_type' => 'map',
                'confidence_score' => 97,
                'note' => 'Operatör onayı',
            ])
            ->assertRedirect('/admin/super-admin/product-data-hub/category-mappings');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings')
            ->assertOk()
            ->assertDontSeeText('Operatör Kupaları');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=approved')
            ->assertOk()
            ->assertSeeText('Operatör Kupaları')
            ->assertSeeText('Eşlendi');
    }

    public function test_remaining_mapping_review_report_shows_supplier_counts_and_keeps_target_missing_unassigned(): void
    {
        $this->seedCategoryTargets();
        $source = $this->makeSource('YENI-NESIL', 'Yeni Nesil');

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Muhtelif Ürünler',
            'supplier_category_path' => 'XML / Muhtelif',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'product_count' => 12,
            'sample_product_names' => ['Karışık Promosyon Ürünü'],
            'suggestion_meta' => [],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced&review_group=target_missing');

        $response->assertOk()
            ->assertSeeText('Manuel Review Listesi')
            ->assertSeeText('Yeni Nesil')
            ->assertSeeText('Pending 1')
            ->assertSeeText('Hedef yok 1')
            ->assertSeeText('Manuel Review');

        $this->assertNull($mapping->fresh()->standard_category_id);
    }

    public function test_special_risk_groups_are_classified_for_manual_review(): void
    {
        $targets = $this->seedCategoryTargets();
        $deskSumen = $this->makeStandardCategory('PROMO-OFIS-MASAUSTU-SUMEN', 'Masa Sümenleri', 'Promosyon Ürünleri / Ofis & Masaüstü Ürünleri / Masa Sümenleri');
        $source = $this->makeSource('AKDENIZ', 'Akdeniz Promosyon');

        foreach ([
            ['Deri Masa Sümeni', 'Ofis / Masa Seti', $deskSumen->id, $deskSumen->full_path, ['review_required' => true]],
            ['Wireless Mousepad', 'Teknoloji / Kablosuz Şarj', $targets['wireless_mousepad']->id, $targets['wireless_mousepad']->full_path, ['special_rule' => 'wireless_mousepad', 'review_required' => true]],
            ['Gemici Takvimler', 'Matbaa / Takvim', $targets['print_gemici_calendar']->id, $targets['print_gemici_calendar']->full_path, ['special_rule' => 'calendar_gemici', 'review_required' => true]],
            ['VIP Setler', 'Setler / VIP', $targets['gift_sets']->id, $targets['gift_sets']->full_path, ['feature_suggestions' => ['set_tipi' => 'VIP']]],
            ['Seramik Kupalar', 'İçecek / Kupa', $targets['cups']->id, $targets['cups']->full_path, ['feature_suggestions' => ['malzeme' => 'seramik']]],
            ['Set Kutuları', 'Ambalaj / Set Kutusu', $targets['set_boxes']->id, $targets['set_boxes']->full_path, ['special_rule' => 'set_boxes', 'review_required' => true]],
            ['Açacaklı Magnetler', 'Aksesuar / Magnet', $targets['opener_magnet']->id, $targets['opener_magnet']->full_path, ['special_rule' => 'opener_magnet']],
        ] as [$name, $path, $targetId, $targetPath, $meta]) {
            SupplierCategoryMapping::query()->create([
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'source_category' => $name,
                'supplier_category_path' => $path,
                'standard_category_id' => $targetId,
                'target_category' => $targetPath,
                'mapping_status' => 'needs_review',
                'decision_type' => 'map',
                'product_count' => 3,
                'suggestion_meta' => $meta,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced&review_group=all&limit=100');

        $response->assertOk()
            ->assertSeeText('Masa Sümeni')
            ->assertSeeText('Mousepad')
            ->assertSeeText('Takvim')
            ->assertSeeText('Hediyelik Setler')
            ->assertSeeText('Kupa / Malzeme')
            ->assertSeeText('Set Kutuları')
            ->assertSeeText('Açacak / Magnet')
            ->assertSeeText('Promosyon Ürünleri / Hediyelik Setler')
            ->assertSeeText('Promosyon Ürünleri / İçecek Ürünleri / Kupalar')
            ->assertSeeText('Matbaa Ürünleri / Takvimler / Gemici Takvim')
            ->assertSeeText('Set kutuları boş ambalaj mı ürünlü set mi kontrol edilmeli; review_required kalır.');
    }

    public function test_review_list_exports_csv_and_json_without_applying_mappings(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('ETKIN', 'Etkin Promosyon');
        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Seramik Kupalar',
            'supplier_category_path' => 'İçecek / Seramik',
            'standard_category_id' => $targets['cups']->id,
            'target_category' => $targets['cups']->full_path,
            'mapping_status' => 'needs_review',
            'decision_type' => 'map',
            'product_count' => 5,
            'sample_product_names' => ['Seramik Kupa 300 ml'],
            'suggestion_meta' => ['feature_suggestions' => ['malzeme' => 'seramik'], 'review_required' => true],
            'is_active' => true,
        ]);

        $csvResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings/review-export/csv');

        $csvResponse->assertOk();
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('supplier,supplier_category_name,supplier_category_path,product_count,sample_products,current_status,suggested_class,suggested_target_category,suggested_decision,risk_group,risk_level,reason', $csvContent);
        $this->assertStringContainsString('Seramik Kupalar', $csvContent);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/super-admin/product-data-hub/category-mappings/review-export/json')
            ->assertOk()
            ->assertJsonFragment([
                'supplier_category_name' => 'Seramik Kupalar',
                'risk_group' => 'Kupa / Malzeme',
            ]);

        $this->assertSame('needs_review', $mapping->fresh()->mapping_status);
        $this->assertSame($targets['cups']->id, $mapping->fresh()->standard_category_id);
    }

    public function test_mapping_queue_quick_view_renders_bulk_operation_tools(): void
    {
        $this->seedCategoryTargets();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced');

        $response->assertOk()
            ->assertSeeText('Hızlı Eşleme')
            ->assertSeeText('Gelişmiş İnceleme')
            ->assertSeeText('Sayfadaki Güvenli Önerileri Seç')
            ->assertSeeText('Bu Filtredeki Güvenli Önerileri Seç')
            ->assertSeeText('Seçili Kayıtları Önizle')
            ->assertSee('action="http://localhost/admin/super-admin/product-data-hub/category-mappings/bulk-apply"', false)
            ->assertDontSee('action="http://localhost/admin/super-admin/product-data-hub/category-mappings/auto-approve"', false);
    }

    public function test_quick_accept_approves_mapping_and_does_not_refresh_products(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('FAST-ACCEPT', 'Yeni Nesil');
        $mapping = $this->approvedMapping($source, $targets['cups'], 'Tek Tık Kupalar', null, 'İçecek / Kupalar');
        $mapping->update(['mapping_status' => 'pending']);
        $standardProductCount = StandardProduct::query()->count();
        $tenantProductCount = TenantCatalogProduct::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/category-mappings/{$mapping->id}/accept")
            ->assertRedirect('/admin/super-admin/product-data-hub/category-mappings?view_mode=quick');

        $mapping->refresh();
        $this->assertSame('approved', $mapping->mapping_status);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $mapping->id,
            'action' => 'approved',
        ]);
        $this->assertSame($standardProductCount, StandardProduct::query()->count());
        $this->assertSame($tenantProductCount, TenantCatalogProduct::query()->count());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings')
            ->assertOk()
            ->assertDontSeeText('Tek Tık Kupalar');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?queue=approved')
            ->assertOk()
            ->assertSeeText('Tek Tık Kupalar')
            ->assertSeeText('Eşlendi');
    }

    public function test_quick_accept_is_disabled_when_target_category_is_missing(): void
    {
        $source = $this->makeSource('NO-TARGET', 'Akdeniz Promosyon');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Hedefsiz Hızlı Kategori',
            'target_category' => '',
            'mapping_status' => 'pending',
            'decision_type' => 'map',
            'confidence_score' => 97,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings')
            ->assertOk()
            ->assertSeeText('Hedefsiz Hızlı Kategori')
            ->assertSee('title="Hedef kategori bulunamadı. Önce kategori seçin."', false);
    }

    public function test_bulk_apply_only_safe_skips_review_required_and_risky_records(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('BULK-SAFE', 'Etkin Promosyon');
        $safe = $this->approvedMapping($source, $targets['pens'], 'Safe Kalemler', null, 'Kalemler');
        $safe->update(['mapping_status' => 'pending']);
        $review = $this->approvedMapping($source, $targets['cups'], 'Review Kupalar', null, 'Kupalar');
        $review->update([
            'mapping_status' => 'needs_review',
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => true],
        ]);
        $risky = $this->approvedMapping($source, $targets['classic_mousepad'], 'Klasik Mousepad Riskli', null, 'Mousepad');
        $risky->update([
            'mapping_status' => 'pending',
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false, 'risk_group_key' => 'mousepad'],
        ]);
        $standardProductCount = StandardProduct::query()->count();
        $tenantProductCount = TenantCatalogProduct::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/category-mappings/bulk-apply', [
                'mapping_ids' => [$safe->id, $review->id, $risky->id],
                'mode' => 'only_safe',
                'confirm' => 'Seçili kategori eşlemelerini kaydetmek istiyorum.',
            ])
            ->assertRedirect('/admin/super-admin/product-data-hub/category-mappings?view_mode=quick');

        $this->assertSame('approved', $safe->fresh()->mapping_status);
        $this->assertSame('needs_review', $review->fresh()->mapping_status);
        $this->assertSame('pending', $risky->fresh()->mapping_status);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $safe->id,
            'action' => 'approved',
        ]);
        $this->assertSame($standardProductCount, StandardProduct::query()->count());
        $this->assertSame($tenantProductCount, TenantCatalogProduct::query()->count());
    }

    public function test_bulk_apply_requires_strong_confirm(): void
    {
        $targets = $this->seedCategoryTargets();
        $source = $this->makeSource('BULK-CONFIRM', 'İlpen');
        $mapping = $this->approvedMapping($source, $targets['pens'], 'Confirm Kalemler', null, 'Kalemler');
        $mapping->update(['mapping_status' => 'pending']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/category-mappings/bulk-apply', [
                'mapping_ids' => [$mapping->id],
                'mode' => 'only_safe',
                'confirm' => 'yanlış metin',
            ])
            ->assertRedirect('/admin/super-admin/product-data-hub/category-mappings?view_mode=quick');

        $this->assertSame('pending', $mapping->fresh()->mapping_status);
        $this->assertDatabaseMissing('supplier_category_mapping_logs', [
            'mapping_id' => $mapping->id,
            'action' => 'approved',
        ]);
    }

    private function seedCategoryTargets(): array
    {
        $usb = $this->makeStandardCategory('PDH-USB-BELLEK', 'USB Bellekler', 'Teknolojik Ürünler / USB Bellekler');
        $wirelessMousepad = $this->makeStandardCategory('PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD', 'Wireless Mousepadler', 'Promosyon Ürünleri / Teknolojik Ürünler / Wireless Mousepadler');
        $promoMousepad = $this->makeStandardCategory('PDH-PROMO-MOUSEPAD', 'Mousepad-Bardakaltlığı', 'Matbaa & Kağıt Promosyon / Masaüstü Ürünleri / Mousepad-Bardakaltlığı');
        $classicMousepad = $this->makeStandardCategory('PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD', 'Klasik Mousepadler', 'Promosyon Ürünleri / Kağıt & Üretim Promosyonları / Klasik Mousepadler');
        $umbrella = $this->makeStandardCategory('PDH-SEMSIYE', 'Şemsiyeler', 'Outdoor / Şemsiyeler');
        $calendar = $this->makeStandardCategory('PDH-TAKVIM', 'Takvimler', 'Matbaa & Kağıt Promosyon / Takvimler');
        $printGemiciCalendar = $this->makeStandardCategory('PRINT-TAKVIM-GEMICI', 'Gemici Takvim', 'Matbaa Ürünleri / Takvimler / Gemici Takvim', 'print');
        $powerbank = $this->makeStandardCategory('PDH-POWERBANK', 'Powerbank', 'Teknolojik Ürünler / Powerbank');
        $thermos = $this->makeStandardCategory('PDH-TERMOS', 'Termos Matara', 'Ev & Yaşam / Termos Matara');
        $pens = $this->makeStandardCategory('PDH-KALEM', 'Kalemler', 'Yazım Gereçleri / Kalemler');
        $giftSets = $this->makeStandardCategory('PROMO-HEDIYELIK-SET', 'Hediyelik Setler', 'Promosyon Ürünleri / Hediyelik Setler');
        $cups = $this->makeStandardCategory('PROMO-ICECEK-KUPA', 'Kupalar', 'Promosyon Ürünleri / İçecek Ürünleri / Kupalar');
        $setBoxes = $this->makeStandardCategory('PROMO-AMBALAJ-KUTU-SET', 'Set Kutuları', 'Promosyon Ürünleri / Ambalaj & Boş Kutular / Set Kutuları');
        $opener = $this->makeStandardCategory('PROMO-AKSESUAR-ACACAK', 'Açacaklar', 'Promosyon Ürünleri / Anahtarlık, Rozet & Küçük Aksesuarlar / Açacaklar');
        $magnet = $this->makeStandardCategory('PROMO-AKSESUAR-MAGNET', 'Magnetler', 'Promosyon Ürünleri / Anahtarlık, Rozet & Küçük Aksesuarlar / Magnetler');
        $openerMagnet = $this->makeStandardCategory('PROMO-AKSESUAR-ACACAKLI-MAGNET', 'Açacaklı Magnetler', 'Promosyon Ürünleri / Anahtarlık, Rozet & Küçük Aksesuarlar / Açacaklı Magnetler');

        return [
            'usb' => $usb,
            'wireless_mousepad' => $wirelessMousepad,
            'promo_mousepad' => $promoMousepad,
            'classic_mousepad' => $classicMousepad,
            'umbrella' => $umbrella,
            'calendar' => $calendar,
            'print_gemici_calendar' => $printGemiciCalendar,
            'powerbank' => $powerbank,
            'thermos' => $thermos,
            'pens' => $pens,
            'gift_sets' => $giftSets,
            'cups' => $cups,
            'set_boxes' => $setBoxes,
            'opener' => $opener,
            'magnet' => $magnet,
            'opener_magnet' => $openerMagnet,
        ];
    }

    private function makeSource(string $supplierCode, string $supplierName): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $supplierName,
            'code' => $supplierCode . '-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $supplierName . ' XML',
            'config' => [
                'format' => 'xml',
                'product_node_path' => 'urunler',
            ],
            'status' => 'active',
        ]);
    }

    private function approvedMapping(
        SupplierSource $source,
        StandardCategory $target,
        string $sourceCategory,
        ?string $categoryCode,
        ?string $categoryPath
    ): SupplierCategoryMapping {
        return SupplierCategoryMapping::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_category' => $sourceCategory,
            'supplier_category_code' => $categoryCode,
            'supplier_category_path' => $categoryPath,
            'normalized_name' => Str::lower(Str::ascii($sourceCategory)),
            'standard_category_id' => $target->id,
            'target_category' => $target->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'confidence_score' => 96,
            'suggestion_meta' => ['safe_auto_approve' => true, 'review_required' => false],
            'is_active' => true,
        ]);
    }

    private function standardProductFromRaw(SupplierSource $source, array $rawPayload, string $code): StandardProduct
    {
        $raw = SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => $rawPayload['source_product_id'] ?? $code,
            'source_name' => $rawPayload['source_name'] ?? $code,
            'supplier_product_id' => $rawPayload['source_product_id'] ?? $code,
            'supplier_product_code' => $code,
            'product_name' => $rawPayload['source_name'] ?? $code,
            'supplier_category_name' => $rawPayload['supplier_category_name'] ?? null,
            'source_category' => $rawPayload['source_category'] ?? ($rawPayload['supplier_category_name'] ?? null),
            'raw_payload' => $rawPayload['raw_payload'] ?? [],
            'normalized_payload' => $rawPayload['normalized_payload'] ?? [],
            'sync_status' => 'processed',
        ], []));

        $product = StandardProduct::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_product_raw_id' => $raw->id,
            'sku' => $code,
            'standard_product_code' => $code,
            'name' => $rawPayload['source_name'] ?? $code,
            'product_name' => $rawPayload['source_name'] ?? $code,
            'category' => null,
            'standard_category_id' => null,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $raw->update(['standard_product_id' => $product->id]);

        return $product;
    }

    private function makeStandardCategory(string $code, string $name, string $path, string $family = 'promotion'): StandardCategory
    {
        return StandardCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => StandardCategory::generateSlug($name),
            'product_family' => $family,
            'sort_order' => 1,
            'depth' => max(0, substr_count($path, '/') ),
            'path' => $path,
            'is_active' => true,
            'visible_in_catalog' => true,
            'meta' => [
                'permanent_category_backbone' => true,
                'supplier_dependent' => false,
                'tenant_visible' => true,
            ],
        ]);
    }
}
