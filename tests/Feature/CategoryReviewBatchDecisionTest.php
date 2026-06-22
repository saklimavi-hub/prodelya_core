<?php

namespace Tests\Feature;

use App\Models\CategoryReviewDecision;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryReviewBatchDecisionTest extends TestCase
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

    public function test_review_batch_001_screen_opens_and_shows_first_records(): void
    {
        [$mapping] = $this->prepareBatch();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-review-batches/001')
            ->assertOk()
            ->assertSeeText('Kategori Review Paketi 001')
            ->assertSeeText('Set Kutuları')
            ->assertSeeText((string) $mapping->product_count)
            ->assertSeeText('Sol — Tedarikçi Bilgisi')
            ->assertSeeText('Orta — Sistem Önerisi')
            ->assertSeeText('Sağ — Kullanıcı Kararı');
    }

    public function test_risk_group_filter_works(): void
    {
        $this->prepareBatch();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-review-batches/001?risk_group=Set%20Kutular%C4%B1')
            ->assertOk()
            ->assertSeeText('Set Kutuları')
            ->assertSeeText('1 kayıt gösteriliyor');
    }

    public function test_target_category_search_returns_only_active_permanent_categories(): void
    {
        $target = $this->standardCategory('PROMO-ICECEK-KUPA', 'Kupalar', 'Promosyon Ürünleri / İçecek Ürünleri / Kupalar');
        StandardCategory::query()->create([
            'code' => 'ARCHIVED-KUPA',
            'name' => 'Arşiv Kupa',
            'slug' => 'arsiv-kupa',
            'path' => 'Arşiv / Kupa',
            'product_family' => 'promotion',
            'is_active' => true,
            'visible_in_catalog' => true,
            'duplicate_status' => 'archived',
            'meta' => ['archived_by_category_reset' => true],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/super-admin/product-data-hub/categories/search?q=kupa')
            ->assertOk();

        $response->assertJsonFragment(['id' => $target->id]);
        $this->assertStringNotContainsString('ARCHIVED-KUPA', $response->getContent());
    }

    public function test_user_decision_is_saved_without_mapping_apply_or_category_refresh(): void
    {
        [$mapping, $target] = $this->prepareBatch();
        $beforeTenantCatalogCount = TenantCatalogProduct::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/category-review-batches/001/decisions', [
                'supplier_category_mapping_id' => $mapping->id,
                'supplier' => $mapping->supplier?->name,
                'supplier_category_code' => $mapping->supplier_category_code,
                'supplier_category_name' => $mapping->source_category,
                'supplier_category_path' => $mapping->supplier_category_path,
                'suggested_target_category' => $target->full_path,
                'final_target_category_id' => $target->id,
                'suggested_decision' => 'Eşle',
                'final_decision' => 'feature_attribute',
                'suggested_feature' => '',
                'final_feature' => 'kutu_tipi: Kutulu',
                'user_decision_status' => 'changed',
                'user_note' => 'Boş kutu değil, set içeriği sinyali var.',
            ])
            ->assertRedirect('/admin/super-admin/product-data-hub/category-review-batches/001');

        $this->assertDatabaseHas('category_review_decisions', [
            'batch_code' => '001',
            'supplier_category_mapping_id' => $mapping->id,
            'final_target_category_id' => $target->id,
            'final_decision' => 'feature_attribute',
            'user_decision_status' => 'changed',
        ]);
        $this->assertDatabaseHas('supplier_category_mappings', [
            'id' => $mapping->id,
            'mapping_status' => 'pending',
            'standard_category_id' => null,
        ]);
        $this->assertSame($beforeTenantCatalogCount, TenantCatalogProduct::query()->count());
    }

    public function test_archived_category_cannot_be_saved_as_review_target(): void
    {
        [$mapping] = $this->prepareBatch();
        $archived = StandardCategory::query()->create([
            'code' => 'ARCHIVED-SET-KUTU',
            'name' => 'Arşiv Set Kutu',
            'slug' => 'arsiv-set-kutu',
            'path' => 'Arşiv / Set Kutu',
            'product_family' => 'promotion',
            'is_active' => true,
            'visible_in_catalog' => true,
            'duplicate_status' => 'archived',
            'meta' => ['archived_by_category_reset' => true],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/category-review-batches/001/decisions', [
                'supplier_category_mapping_id' => $mapping->id,
                'final_target_category_id' => $archived->id,
                'final_decision' => 'map',
                'user_decision_status' => 'approved',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('category_review_decisions', [
            'batch_code' => '001',
            'supplier_category_mapping_id' => $mapping->id,
        ]);
    }

    public function test_export_with_decisions_contains_approved_changed_and_held_rows(): void
    {
        [$mapping, $target] = $this->prepareBatch(includeHeld: true);
        CategoryReviewDecision::query()->create([
            'batch_code' => '001',
            'supplier_category_mapping_id' => $mapping->id,
            'supplier' => $mapping->supplier?->name,
            'supplier_category_code' => $mapping->supplier_category_code,
            'supplier_category_name' => $mapping->source_category,
            'supplier_category_path' => $mapping->supplier_category_path,
            'final_target_category_id' => $target->id,
            'final_decision' => 'map',
            'final_feature' => '',
            'user_decision_status' => 'approved',
            'user_note' => 'Onaylandı.',
            'decided_by' => $this->adminUser->id,
            'decided_at' => now(),
        ]);

        $jsonResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-review-batches/001/export/json')
            ->assertOk();
        $jsonContent = $jsonResponse->streamedContent();
        $this->assertStringContainsString('approved', $jsonContent);
        $this->assertStringContainsString('held', $jsonContent);

        $csvResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-review-batches/001/export/csv')
            ->assertOk();
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('user_decision', $csvContent);
        $this->assertStringContainsString('approved', $csvContent);
        $this->assertStringContainsString('held', $csvContent);
    }

    private function prepareBatch(bool $includeHeld = false): array
    {
        Storage::disk('local')->makeDirectory('product-data-hub/category-review');
        $supplier = Supplier::query()->create([
            'name' => 'Akdeniz Promosyon',
            'code' => 'AKDENIZ-' . uniqid(),
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Akdeniz Promosyon',
            'status' => 'active',
        ]);
        $target = $this->standardCategory('PROMO-AMBALAJ-KUTU-SET', 'Set Kutuları', 'Promosyon Ürünleri / Ambalaj & Boş Kutular / Set Kutuları');
        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
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
        $rows = [[
            'priority' => 1,
            'supplier_category_mapping_id' => $mapping->id,
            'supplier' => 'Akdeniz Promosyon',
            'supplier_category_code' => 'AK-SET-KUTU',
            'supplier_category_name' => 'Set Kutuları',
            'supplier_category_path' => 'Akdeniz / Set Kutuları',
            'product_count' => 12,
            'sample_products' => 'Kutulu Set | Boş Set Kutusu',
            'current_status' => 'target_missing',
            'risk_group' => 'Set Kutuları',
            'suggested_class' => 'Manuel inceleme gerekir',
            'suggested_target_category' => $target->full_path,
            'suggested_feature' => '',
            'suggested_decision' => 'Eşle veya Özellik/Filtre Yap',
            'confidence_score' => 0,
            'reason' => 'Set kutusu boş ambalaj mı ürünlü set mi kontrol edilmeli.',
            'user_decision' => '',
            'user_note' => '',
        ]];

        if ($includeHeld) {
            $heldMapping = SupplierCategoryMapping::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'source_category' => 'Mousepad',
                'supplier_category_path' => 'Akdeniz / Mousepad',
                'normalized_name' => 'mousepad',
                'product_count' => 4,
                'sample_product_names' => ['Mousepad'],
                'target_category' => '',
                'mapping_status' => 'pending',
                'decision_type' => 'review',
                'is_active' => true,
            ]);
            $rows[] = [
                'priority' => 2,
                'supplier_category_mapping_id' => $heldMapping->id,
                'supplier' => 'Akdeniz Promosyon',
                'supplier_category_code' => '',
                'supplier_category_name' => 'Mousepad',
                'supplier_category_path' => 'Akdeniz / Mousepad',
                'product_count' => 4,
                'sample_products' => 'Mousepad',
                'current_status' => 'target_missing',
                'risk_group' => 'Mousepad',
                'suggested_class' => 'Manuel inceleme gerekir',
                'suggested_target_category' => '',
                'suggested_feature' => '',
                'suggested_decision' => 'Beklet',
                'confidence_score' => 0,
                'reason' => 'Mousepad ayrımı kontrol edilmeli.',
                'user_decision' => 'held',
                'user_note' => 'Sonra bakılacak.',
            ];
        }

        Storage::disk('local')->put('product-data-hub/category-review/category_review_batch_001.json', json_encode([
            'batch' => '001',
            'generated_at' => now()->toIso8601String(),
            'row_count' => count($rows),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [$mapping, $target];
    }

    private function standardCategory(string $code, string $name, string $path): StandardCategory
    {
        return StandardCategory::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)),
                'path' => $path,
                'product_family' => str_starts_with($code, 'PRINT') ? 'print' : 'promotion',
                'is_active' => true,
                'visible_in_catalog' => true,
                'meta' => [
                    'permanent_category_backbone' => true,
                    'is_system' => true,
                    'supplier_dependent' => false,
                ],
            ]
        );
    }
}
