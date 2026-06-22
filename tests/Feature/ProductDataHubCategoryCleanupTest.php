<?php

namespace Tests\Feature;

use App\Models\CategoryTreeDraft;
use App\Models\CategoryTreeDraftItem;
use App\Models\CategoryCleanupDecision;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubCategoryCleanupTest extends TestCase
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

    public function test_category_cleanup_screen_opens_and_shows_review_candidates(): void
    {
        $this->actingAs($this->adminUser);
        $this->makeReviewCategories();

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')
            ->assertOk()
            ->assertSee('Kategori Temizlik')
            ->assertSee('Temizlik Grupları')
            ->assertSee('Termos, Matara &amp; Kupa', false)
            ->assertSee('Hediyelik Setler')
            ->assertSee('Takvimler')
            ->assertSee('Mousepad')
            ->assertSee('Yeni Kategori Taslağı')
            ->assertDontSee('Sol Blok — Tedarikçi Kategorisi')
            ->assertDontSee('TODO');
    }

    public function test_category_cleanup_creates_draft_without_changing_active_categories(): void
    {
        $this->actingAs($this->adminUser);

        $categoryCount = StandardCategory::query()->count();

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')
            ->assertOk()
            ->assertSee('Yeni Kategori Taslağı')
            ->assertSee('Promosyon Ürünleri')
            ->assertSee('Matbaa Ürünleri');

        $this->assertSame($categoryCount, StandardCategory::query()->count());
        $this->assertDatabaseHas('category_tree_drafts', [
            'name' => 'Prodelya Temiz Standart Kategori Taslağı',
            'status' => 'draft',
        ]);
        $this->assertGreaterThan(0, CategoryTreeDraftItem::query()->count());
    }

    public function test_draft_drag_reorder_updates_only_draft_tree(): void
    {
        $this->actingAs($this->adminUser);

        $categoryCount = StandardCategory::query()->count();
        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')->assertOk();

        $draft = CategoryTreeDraft::query()->where('name', 'Prodelya Temiz Standart Kategori Taslağı')->firstOrFail();
        $root = CategoryTreeDraftItem::query()->where('draft_id', $draft->id)->whereNull('parent_id')->firstOrFail();
        $child = CategoryTreeDraftItem::query()->where('draft_id', $draft->id)->whereNotNull('parent_id')->firstOrFail();

        $this->postOnCentralHost("/admin/super-admin/product-data-hub/category-cleanup/draft-items/{$child->id}/reorder", [
            'parent_id' => $root->id,
            'sort_order' => 99,
        ])->assertRedirect('/admin/super-admin/product-data-hub/category-cleanup');

        $child->refresh();

        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(99, $child->sort_order);
        $this->assertSame($categoryCount, StandardCategory::query()->count());
    }

    public function test_draft_category_cannot_be_moved_under_its_descendant(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')->assertOk();

        $draft = CategoryTreeDraft::query()->where('name', 'Prodelya Temiz Standart Kategori Taslağı')->firstOrFail();
        $root = CategoryTreeDraftItem::query()->where('draft_id', $draft->id)->whereNull('parent_id')->firstOrFail();
        $child = CategoryTreeDraftItem::query()->where('draft_id', $draft->id)->where('parent_id', $root->id)->firstOrFail();

        $this->from('/admin/super-admin/product-data-hub/category-cleanup')
            ->postOnCentralHost("/admin/super-admin/product-data-hub/category-cleanup/draft-items/{$root->id}/reorder", [
                'parent_id' => $child->id,
                'sort_order' => 10,
            ])
            ->assertSessionHasErrors('parent_id');

        $root->refresh();
        $this->assertNull($root->parent_id);
    }

    public function test_mapping_queue_is_separate_and_feature_templates_screen_is_separate(): void
    {
        $this->actingAs($this->adminUser);

        $category = $this->makeCategory('TEST-TERMOS-TARGET', 'İçecek Ürünleri', 'Promosyon / İçecek Ürünleri');
        [$supplier, $source] = $this->makeSupplierSource();

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'supplier_category_code' => 'TERMOS',
            'source_category' => 'Termos Metal Ürünler',
            'supplier_category_path' => 'XML / Termos',
            'sample_product_names' => ['Metal Termos'],
            'target_category' => $category->full_path,
            'mapping_status' => 'needs_review',
            'decision_type' => 'merge_candidate',
            'confidence_score' => 55,
            'is_active' => true,
        ]);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-mappings?view_mode=detail')
            ->assertOk()
            ->assertSee('Kategori Eşleme Kuyruğu')
            ->assertSee('Sol Blok — Tedarikçi Kategorisi')
            ->assertSee('Orta Blok — Sistem Önerisi')
            ->assertSee('Sağ Blok — Karar')
            ->assertSee('Eşle')
            ->assertSee('Alias Yap')
            ->assertSee('İkiz Yap')
            ->assertSee('Ayrı Bırak')
            ->assertSee('Reddet')
            ->assertDontSee('Birleştir')
            ->assertDontSee('Filtre Yap');

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-feature-templates')
            ->assertOk()
            ->assertSee('Özellik Şablonları')
            ->assertSee('Web filtresi')
            ->assertSee('XML/API export')
            ->assertSee('Öneri motoru');
    }

    public function test_cleanup_workbench_shows_initial_order_and_path_preview(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')
            ->assertOk()
            ->assertSee('Yeni Kategori Taslağı')
            ->assertSee('Promosyon Ürünleri')
            ->assertSee('Matbaa Ürünleri')
            ->assertSee('Taslağı Aç');
    }

    public function test_category_mapping_can_be_cancelled_and_logged(): void
    {
        $this->actingAs($this->adminUser);

        $category = $this->makeCategory('TEST-KALEM', 'Kalemler', 'Promosyon / Kalemler');
        [$supplier, $source] = $this->makeSupplierSource();

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'supplier_category_code' => 'SRC-KALEM',
            'source_category' => 'Tedarikçi Kalemleri',
            'supplier_category_path' => 'Ana / Tedarikçi Kalemleri',
            'target_category' => $category->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'confidence_score' => 92,
            'is_active' => true,
        ]);

        $this->postOnCentralHost("/admin/super-admin/product-data-hub/category-mappings/{$mapping->id}/cancel", [
            'reason' => 'Yanlış kategori eşlemesi.',
        ])->assertRedirect('/admin/super-admin/product-data-hub/category-mappings?queue=pending');

        $mapping->refresh();

        $this->assertNull($mapping->standard_category_id);
        $this->assertSame('cancelled', $mapping->mapping_status);
        $this->assertSame('review', $mapping->decision_type);
        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => $category->id,
            'new_standard_category_id' => null,
            'action' => 'cancelled',
            'reason' => 'Yanlış kategori eşlemesi.',
        ]);
    }

    public function test_mapping_page_keeps_supplier_category_visible_after_cancel_action_exists(): void
    {
        $this->actingAs($this->adminUser);

        $category = $this->makeCategory('TEST-SET', 'Hediyelik Setler', 'Promosyon / Hediyelik Setler');
        [$supplier, $source] = $this->makeSupplierSource();

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'supplier_category_code' => 'SET',
            'source_category' => 'Tedarikçi Set Kategorisi',
            'supplier_category_path' => 'XML / Setler',
            'sample_product_names' => ['Kutulu VIP Set'],
            'target_category' => $category->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'confidence_score' => 85,
            'is_active' => true,
        ]);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-mappings?queue=approved&view_mode=detail')
            ->assertOk()
            ->assertSee('Tedarikçi Set Kategorisi')
            ->assertSee('XML / Setler')
            ->assertSee('Sol Blok — Tedarikçi Kategorisi')
            ->assertSee('Orta Blok — Sistem Önerisi')
            ->assertSee('Sağ Blok — Karar')
            ->assertSee('İptal Edilmiş')
            ->assertSee('Ayrı Bırakılmış')
            ->assertSee('Eşlemeyi İptal Et')
            ->assertDontSee('Birleştir')
            ->assertDontSee('Filtre Yap');
    }

    public function test_product_data_hub_menu_shows_category_cleanup_link(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')
            ->assertOk()
            ->assertSee('Kategori Temizlik');

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-feature-templates')
            ->assertOk()
            ->assertSee('Standart Kategori Ağacı')
            ->assertSee('Kategori Eşleme')
            ->assertSee('Kategori Temizlik')
            ->assertSee('Özellik Şablonları');
    }

    public function test_standard_categories_screen_uses_compact_header_and_feature_templates_language(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/standard-categories')
            ->assertOk()
            ->assertSee('Standart Kategori Ağacı')
            ->assertSee('Özellik Şablonları')
            ->assertDontSee('Sol Blok — Tedarikçi Kategorisi')
            ->assertDontSee('pd-hero-title', false)
            ->assertDontSee('TODO');
    }

    public function test_category_decision_list_is_created_without_changing_active_tree(): void
    {
        $this->actingAs($this->adminUser);
        $this->makeReviewCategories();

        $before = StandardCategory::query()
            ->get(['id', 'parent_id', 'name', 'code', 'sort_order', 'is_active', 'visible_in_catalog'])
            ->keyBy('id')
            ->map(fn (StandardCategory $category) => $category->only(['parent_id', 'name', 'code', 'sort_order', 'is_active', 'visible_in_catalog']))
            ->all();

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')
            ->assertOk()
            ->assertSee('Temizlik Kararları')
            ->assertSee('CSV Export')
            ->assertSee('JSON Export');

        $draft = CategoryTreeDraft::query()->where('name', 'Prodelya Temiz Standart Kategori Taslağı')->firstOrFail();

        $this->assertSame(StandardCategory::query()->count(), CategoryCleanupDecision::query()->where('draft_id', $draft->id)->count());

        $after = StandardCategory::query()
            ->get(['id', 'parent_id', 'name', 'code', 'sort_order', 'is_active', 'visible_in_catalog'])
            ->keyBy('id')
            ->map(fn (StandardCategory $category) => $category->only(['parent_id', 'name', 'code', 'sort_order', 'is_active', 'visible_in_catalog']))
            ->all();

        $this->assertSame($before, $after);
    }

    public function test_special_category_groups_get_expected_decisions(): void
    {
        $this->actingAs($this->adminUser);

        $promo = $this->makeCategory('PROMO-ROOT-DECISION', 'Promosyon Ürünleri', 'Promosyon Ürünleri');
        $this->makeCategory('QA-PROMO-TERMOS', 'Termos', 'Promosyon Ürünleri / Termos', $promo->id, 1);
        $this->makeCategory('QA-PROMO-HEDIYELIK-SET', 'Hediyelik Setler', 'Promosyon Ürünleri / Hediyelik Setler', $promo->id, 1);
        $this->makeCategory('QA-PROMO-TAKVIM', 'Takvimler', 'Promosyon Ürünleri / Takvimler', $promo->id, 1);
        $this->makeCategory('QA-PROMO-MOUSEPAD-WIRELESS', 'Wireless Mousepad', 'Promosyon Ürünleri / Wireless Mousepad', $promo->id, 1);
        $this->makeCategory('QA-PROMO-ACACAK-MAGNET', 'Açacaklı Magnet', 'Promosyon Ürünleri / Açacaklı Magnet', $promo->id, 1);
        $this->makeCategory('QA-PROMO-SET-KUTU', 'Set Kutuları', 'Promosyon Ürünleri / Set Kutuları', $promo->id, 1);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')->assertOk();

        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-TERMOS',
            'proposed_action' => 'alias',
            'proposed_parent' => 'İçecek Ürünleri',
        ]);
        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-HEDIYELIK-SET',
            'proposed_action' => 'merge',
            'proposed_parent' => 'Hediyelik Setler',
        ]);
        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-TAKVIM',
            'proposed_action' => 'twin_view',
        ]);
        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-MOUSEPAD-WIRELESS',
            'proposed_action' => 'filter_attribute',
            'feature_template_key' => 'teknoloji',
        ]);
        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-ACACAK-MAGNET',
            'proposed_action' => 'separate_keep',
        ]);
        $this->assertDatabaseMissing('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-ACACAK-MAGNET',
            'proposed_action' => 'merge',
        ]);
        $this->assertDatabaseHas('category_cleanup_decisions', [
            'current_code' => 'QA-PROMO-SET-KUTU',
            'proposed_action' => 'needs_review',
            'risk_level' => 'high',
        ]);
    }

    public function test_decision_list_exports_csv_and_json(): void
    {
        $this->actingAs($this->adminUser);
        $this->makeReviewCategories();

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup/export/csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('current_id,current_code,current_name,current_path,product_count,mapping_count,proposed_action,proposed_code,proposed_name,proposed_path,risk_level,reason,decision_status', false);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup/export/json')
            ->assertOk()
            ->assertJsonPath('active_tree_changed', false)
            ->assertJsonStructure(['draft' => ['id', 'name', 'status'], 'decisions']);
    }

    public function test_feature_templates_are_suggested_for_draft_categories(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-feature-templates')
            ->assertOk()
            ->assertSee('kapasite mAh')
            ->assertSee('sıcak/soğuk tutma')
            ->assertSee('kağıt gramajı')
            ->assertSee('Özellikler kategori çoğaltmayı azaltır');

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')->assertOk();
        $this->assertGreaterThan(0, CategoryCleanupDecision::query()->whereNotNull('feature_template_key')->count());
    }

    public function test_products_and_supplier_mapping_category_links_do_not_change(): void
    {
        $this->actingAs($this->adminUser);

        $category = $this->makeCategory('TEST-LINK-KALEM', 'Kalemler', 'Promosyon Ürünleri / Kalemler');
        [$supplier, $source] = $this->makeSupplierSource();

        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'sku' => 'TEST-KLM-1',
            'standard_product_code' => 'TEST-KLM-1',
            'name' => 'Test Kalem',
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'is_active' => true,
        ]);

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $category->id,
            'supplier_category_code' => 'SRC-LINK-KALEM',
            'source_category' => 'Tedarikçi Kalem',
            'supplier_category_path' => 'XML / Kalem',
            'target_category' => $category->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'confidence_score' => 91,
            'is_active' => true,
        ]);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup')->assertOk();
        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-cleanup/export/json')->assertOk();

        $this->assertSame($category->id, $product->refresh()->standard_category_id);
        $this->assertSame($category->id, $mapping->refresh()->standard_category_id);
    }

    private function makeReviewCategories(): void
    {
        $promo = $this->makeCategory('TEST-PROMO', 'Promosyon', 'Promosyon');
        $print = $this->makeCategory('TEST-PRINT', 'Matbaa', 'Matbaa');

        $this->makeCategory('TEST-TERMOS', 'Termos', 'Promosyon / Termos', $promo->id, 1);
        $this->makeCategory('TEST-TERMOS-KUPA', 'Termos', 'Matbaa / Termos', $print->id, 1);
        $this->makeCategory('TEST-HEDIYELIK-SET', 'Hediyelik Setler', 'Promosyon / Hediyelik Setler', $promo->id, 1);
        $this->makeCategory('TEST-TAKVIM', 'Takvimler', 'Promosyon / Takvimler', $promo->id, 1);
        $this->makeCategory('TEST-PRINT-TAKVIM', 'Takvimler', 'Matbaa / Takvimler', $print->id, 1);
        $this->makeCategory('TEST-MOUSEPAD', 'Mousepad', 'Promosyon / Mousepad', $promo->id, 1);
    }

    private function makeCategory(string $code, string $name, string $path, ?int $parentId = null, int $depth = 0): StandardCategory
    {
        return StandardCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => StandardCategory::generateSlug($name),
            'parent_id' => $parentId,
            'product_family' => 'promotion',
            'sort_order' => 10,
            'depth' => $depth,
            'path' => $path,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
        ]);
    }

    private function makeSupplierSource(): array
    {
        $supplier = Supplier::query()->create([
            'name' => 'Kategori Test Tedarikçisi',
            'code' => 'KATEGORI-TEST-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Kategori Test XML',
            'config' => ['format' => 'xml'],
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function getOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get($uri);
    }

    private function postOnCentralHost(string $uri, array $data = [])
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->post($uri, $data);
    }
}
