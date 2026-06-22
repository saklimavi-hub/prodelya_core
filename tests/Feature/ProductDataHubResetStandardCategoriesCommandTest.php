<?php

namespace Tests\Feature;

use App\Models\CategoryAttributeRule;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductDataHubResetStandardCategoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_reset_command_dry_run_does_not_change_database(): void
    {
        Storage::fake('local');

        $legacyCategory = $this->legacyCategory();
        $beforeCount = StandardCategory::query()->count();

        $this->artisan('product-data-hub:reset-standard-categories --dry-run')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame($beforeCount, StandardCategory::query()->count());
        $this->assertDatabaseHas('standard_categories', [
            'id' => $legacyCategory->id,
            'code' => 'LEGACY-KUPA',
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
    }

    public function test_apply_requires_confirm_and_leaves_database_unchanged_without_it(): void
    {
        Storage::fake('local');

        $legacyCategory = $this->legacyCategory();

        $this->artisan('product-data-hub:reset-standard-categories --apply')
            ->expectsOutputToContain('Apply için --confirm=KALICI-KATEGORI-RESET zorunludur')
            ->assertExitCode(1);

        $this->assertDatabaseHas('standard_categories', [
            'id' => $legacyCategory->id,
            'code' => 'LEGACY-KUPA',
            'is_active' => true,
        ]);
    }

    public function test_apply_creates_permanent_category_backbone_and_resets_mappings_safely(): void
    {
        Storage::fake('local');

        $legacyCategory = $this->legacyCategory();
        [$mapping, $standardProduct, $tenantCatalogProduct] = $this->mappedProductFixture($legacyCategory);

        $standardProductCount = StandardProduct::query()->count();
        $tenantCatalogProductCount = TenantCatalogProduct::query()->count();

        $this->artisan('product-data-hub:reset-standard-categories --apply --confirm=KALICI-KATEGORI-RESET')
            ->expectsOutputToContain('APPLY: Kalıcı kategori omurgası oluşturuldu.')
            ->assertExitCode(0);

        $this->assertSame($standardProductCount, StandardProduct::query()->count());
        $this->assertSame($tenantCatalogProductCount, TenantCatalogProduct::query()->count());

        $pendingCategory = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->firstOrFail();

        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PROMO',
            'name' => 'Promosyon Ürünleri',
            'parent_id' => null,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PRINT',
            'name' => 'Matbaa Ürünleri',
            'parent_id' => null,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PROMO-ICECEK-KUPA',
            'name' => 'Kupalar',
        ]);
        $this->assertDatabaseMissing('standard_categories', [
            'code' => 'PROMO-ICECEK-KUPA-SERAMIK',
        ]);
        $this->assertDatabaseMissing('standard_categories', [
            'code' => 'PROMO-ICECEK-KUPA-PORSELEN',
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD',
            'name' => 'Wireless Mousepadler',
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD',
            'name' => 'Klasik Mousepadler',
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PROMO-AMBALAJ-KUTU-SET',
            'name' => 'Set Kutuları',
        ]);
        $this->assertDatabaseHas('standard_categories', [
            'code' => 'PRINT-TAKVIM-MASA-SUMENI',
            'name' => 'Masa Sümeni',
        ]);
        $this->assertDatabaseMissing('standard_categories', [
            'code' => 'PROMO-TAKVIM',
        ]);

        $giftSet = StandardCategory::query()->where('code', 'PROMO-HEDIYELIK-SET')->firstOrFail();
        $this->assertSame(0, StandardCategory::query()->where('parent_id', $giftSet->id)->count());

        $mapping->refresh();
        $this->assertNull($mapping->standard_category_id);
        $this->assertSame('pending', $mapping->mapping_status);
        $this->assertSame('review', $mapping->decision_type);
        $this->assertSame('Standart kategori ağacı sıfırlandı; yeni ağaca yeniden eşleme bekleniyor.', $mapping->decision_note);

        $this->assertDatabaseHas('supplier_category_mapping_logs', [
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => $legacyCategory->id,
            'new_standard_category_id' => null,
            'action' => 'category_tree_reset',
        ]);

        $standardProduct->refresh();
        $tenantCatalogProduct->refresh();
        $this->assertSame($pendingCategory->id, $standardProduct->standard_category_id);
        $this->assertSame($pendingCategory->id, $tenantCatalogProduct->standard_category_id);

        $this->assertGreaterThanOrEqual(11, CategoryAttributeRule::query()
            ->whereJsonContains('meta->keep_out_of_quote_form', true)
            ->distinct('standard_category_id')
            ->count('standard_category_id'));

        $activeVisibleBackboneCount = StandardCategory::query()
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->where('code', 'not like', 'ARCHIVED-%')
            ->count();

        $this->assertGreaterThan(100, $activeVisibleBackboneCount);
    }

    public function test_backup_files_are_created_before_apply(): void
    {
        Storage::fake('local');

        $this->legacyCategory();

        $this->artisan('product-data-hub:reset-standard-categories --apply --confirm=KALICI-KATEGORI-RESET')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('product-data-hub/category-backups');

        $this->assertNotEmpty($files);
        $this->assertTrue(collect($files)->contains(fn (string $file) => str_ends_with($file, 'standard_categories_before_reset.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $file) => str_ends_with($file, 'supplier_category_mappings_before_reset.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $file) => str_ends_with($file, 'category_cleanup_decisions_before_reset.json')));
    }

    private function legacyCategory(): StandardCategory
    {
        return StandardCategory::query()->create([
            'code' => 'LEGACY-KUPA',
            'name' => 'Eski Kupa',
            'slug' => 'eski-kupa',
            'product_family' => 'promotion',
            'sort_order' => 1,
            'depth' => 0,
            'path' => 'Eski Kupa',
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);
    }

    private function mappedProductFixture(StandardCategory $category): array
    {
        $supplier = Supplier::query()->first()
            ?: Supplier::query()->create([
                'name' => 'Test Tedarikçi',
                'code' => 'TEST',
                'status' => 'active',
            ]);

        $source = SupplierSource::query()->first()
            ?: SupplierSource::query()->create([
                'supplier_id' => $supplier->id,
                'source_type' => 'xml',
                'source_name' => 'Test XML',
                'url' => 'https://example.test/feed.xml',
                'status' => 'active',
            ]);

        $tenant = TenantAccount::query()->firstOrFail();

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Eski Tedarikçi Kupa',
            'target_category' => 'Eski Kupa',
            'standard_category_id' => $category->id,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'decision_note' => 'Eski karar',
            'is_active' => true,
        ]);

        $standardProduct = StandardProduct::query()->create([
            'sku' => 'RESET-STANDARD-1',
            'name' => 'Reset Standard Ürün',
            'standard_product_code' => 'RESET-STANDARD-1',
            'product_name' => 'Reset Standard Ürün',
            'standard_category_id' => $category->id,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $tenantCatalogProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'RESET-TENANT-1',
            'name' => 'Reset Tenant Ürün',
            'product_code' => 'RESET-TENANT-1',
            'product_name' => 'Reset Tenant Ürün',
            'standard_category_id' => $category->id,
            'currency' => 'TL',
            'stock_quantity' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        return [$mapping, $standardProduct, $tenantCatalogProduct];
    }
}
