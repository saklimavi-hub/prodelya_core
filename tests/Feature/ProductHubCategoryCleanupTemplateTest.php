<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubCategoryCleanupTemplateTest extends TestCase
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

    public function test_category_cleanup_screen_explains_safe_draft_structure(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-cleanup');

        $response->assertOk();
        $response->assertSeeText('Bu fazda gerçek kategori ağacı otomatik değiştirilmez');
        $response->assertSeeText('Analiz Özeti');
        $response->assertSeeText('Karar Listesi');
        $response->assertSeeText('Taslak Düzen');
        $response->assertSeeText('Dışa Aktar');
        $response->assertSeeText('Taslak: Birleştirme adayı');
        $response->assertDontSee('class="pd-btn pd-btn-primary">Birleştirme', false);
    }

    public function test_category_mapping_screen_clarifies_original_and_selected_categories(): void
    {
        $target = StandardCategory::query()->create([
            'code' => 'TEST-KATEGORI-TEMPLATE',
            'name' => 'Kalemler',
            'slug' => 'kalemler',
            'path' => 'Promosyon Ürünleri / Kalemler',
            'product_family' => 'promotion',
            'sort_order' => 10,
            'depth' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
            'meta' => ['permanent_category_backbone' => true],
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Template Test Supplier',
            'code' => 'TPL-CAT-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Template XML',
            'status' => 'active',
            'config' => ['format' => 'xml'],
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'standard_category_id' => $target->id,
            'source_category' => 'Plastik Kalemler',
            'supplier_category_path' => 'XML / Plastik Kalemler',
            'target_category' => $target->full_path,
            'mapping_status' => 'needs_review',
            'decision_type' => 'map',
            'confidence_score' => 82,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings?mode=advanced&view_mode=detail');

        $response->assertOk();
        $response->assertSeeText('Orijinal kategori korunur');
        $response->assertSeeText('Sol Blok — Orijinal Tedarikçi Kategorisi');
        $response->assertSeeText('Orta Blok — Önerilen Prodelya Kategorisi');
        $response->assertSeeText('Seçilen Prodelya Kategorisi');
    }

    public function test_standard_categories_and_feature_templates_explain_their_roles(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/standard-categories')
            ->assertOk()
            ->assertSeeText('Prodelya ortak kategori ağacını yönetin')
            ->assertSeeText('Bu ekran tedarikçi kategorilerini doğrudan değiştirmez')
            ->assertSeeText('Abone Firma bu ağacı değiştiremez');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-feature-templates')
            ->assertOk()
            ->assertSeeText('hangi özelliklerin hangi kategori ailesinde kullanılacağını gösterir')
            ->assertSeeText('renk')
            ->assertSeeText('ölçü')
            ->assertSeeText('kapasite')
            ->assertSeeText('malzeme')
            ->assertSeeText('Abone Firma kataloğu');
    }

    public function test_category_cleanup_css_uses_standard_radii(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertNotFalse($css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-flow-steps span\s*,\s*\.pd-decision-filter-row span\s*\{[^}]*border-radius:\s*999px;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.pd-cleanup-card\s*,\s*\.pd-template-card\s*\{[^}]*border-radius:\s*16px;/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-flow-steps span\s*,\s*\.pd-decision-filter-row span\s*\{[^}]*border-radius:\s*var\(--pd-radius-pill\);/s', $css);
        $this->assertMatchesRegularExpression('/\.pd-cleanup-card\s*,\s*\.pd-template-card\s*\{[^}]*border-radius:\s*var\(--pd-radius-card\);/s', $css);
    }
}
