<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermanentCategoryBackboneLockTest extends TestCase
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

    public function test_standard_categories_hide_archived_by_default_and_show_with_archive_filter(): void
    {
        $permanent = $this->permanentCategory('PROMO-LOCKED', 'Kalıcı Test Kategori');
        $archived = $this->archivedCategory('ARCHIVED-LOCKED-1', 'Eski Arşiv Kategori');

        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/standard-categories')
            ->assertOk()
            ->assertSeeText($permanent->name)
            ->assertDontSeeText($archived->code)
            ->assertSeeText('Aktif Kategoriler')
            ->assertDontSeeText('Bu kategori eski kategori ağacından arşivlenmiştir');

        $this->getOnCentralHost('/admin/super-admin/standard-categories?archive_status=archived')
            ->assertOk()
            ->assertSeeText($archived->code)
            ->assertSeeText('Bu kategori eski kategori ağacından arşivlenmiştir')
            ->assertDontSeeText($permanent->name);
    }

    public function test_mapping_queue_target_dropdown_excludes_archived_categories(): void
    {
        $permanent = $this->permanentCategory('PROMO-MAPPING-TARGET', 'Yeni Hedef Kategori');
        $archived = $this->archivedCategory('ARCHIVED-MAPPING-TARGET', 'Eski Hedef Kategori');
        $this->supplierMapping($archived);

        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-mappings?view_mode=detail')
            ->assertOk()
            ->assertSeeText('Standart kategori ağacı yenilendi')
            ->assertSeeText($permanent->full_path)
            ->assertDontSeeText($archived->full_path)
            ->assertDontSee('value="' . $archived->id . '"', false);
    }

    public function test_mapping_update_rejects_archived_target_category(): void
    {
        $archived = $this->archivedCategory('ARCHIVED-POST-TARGET', 'Eski Post Hedefi');
        $mapping = $this->supplierMapping($archived);

        $this->actingAs($this->adminUser);

        $this->putOnCentralHost("/admin/super-admin/product-data-hub/category-mappings/{$mapping->id}", [
            'standard_category_id' => $archived->id,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
        ])->assertStatus(422);
    }

    public function test_tenant_category_selection_shows_permanent_categories_and_hides_archived(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $permanent = $this->permanentCategory('PROMO-TENANT-SELECT', 'Tenant Yeni Kategori');
        $archived = $this->archivedCategory('ARCHIVED-TENANT-SELECT', 'Tenant Eski Kategori');

        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/catalog/local-products')
            ->assertOk()
            ->assertSeeText($permanent->full_path)
            ->assertDontSeeText($archived->full_path);

        $this->postOnCentralHost('/admin/catalog/local-products', [
            'product_name' => 'Arşiv Kategori Deneme',
            'product_code' => 'ARCH-CAT-LOCAL-1',
            'standard_category_id' => $archived->id,
            'visible_in_catalog' => '1',
            'visible_in_quote' => '1',
            'is_active' => '1',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'product_code' => 'ARCH-CAT-LOCAL-1',
        ]);
    }

    public function test_permanent_categories_do_not_show_delete_action_and_backend_blocks_delete(): void
    {
        $permanent = $this->permanentCategory('PROMO-NO-DELETE', 'Silinemez Kalıcı Kategori');

        $this->actingAs($this->adminUser);

        $response = $this->getOnCentralHost('/admin/super-admin/standard-categories');
        $response->assertOk();
        $response->assertSeeText('Kalıcı omurga');
        $response->assertDontSee('action="' . route('admin.super.standard-categories.destroy', $permanent) . '"', false);

        $this->deleteOnCentralHost("/admin/super-admin/standard-categories/{$permanent->id}")
            ->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', [
            'id' => $permanent->id,
            'code' => 'PROMO-NO-DELETE',
        ]);
    }

    public function test_product_data_hub_overview_shows_category_reset_metrics(): void
    {
        $this->permanentCategory('PROMO-OVERVIEW', 'Overview Kalıcı');
        $this->archivedCategory('ARCHIVED-OVERVIEW', 'Overview Arşiv');

        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub')
            ->assertOk()
            ->assertSeeText('Kategori Reset Durumu')
            ->assertSeeText('Kalıcı kategori')
            ->assertSeeText('Arşiv kategori')
            ->assertSeeText('Yeniden eşleme');
    }

    private function permanentCategory(string $code, string $name): StandardCategory
    {
        return StandardCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => StandardCategory::generateSlug($name),
            'product_family' => str_starts_with($code, 'PRINT') ? 'print' : 'promotion',
            'sort_order' => 1,
            'depth' => 0,
            'path' => $name,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => false,
            'duplicate_status' => 'canonical',
            'meta' => [
                'is_system' => true,
                'supplier_dependent' => false,
                'tenant_visible' => true,
                'permanent_category_backbone' => true,
            ],
        ]);
    }

    private function archivedCategory(string $code, string $name): StandardCategory
    {
        return StandardCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => StandardCategory::generateSlug($name),
            'product_family' => 'promotion',
            'sort_order' => 1,
            'depth' => 0,
            'path' => $name,
            'is_active' => false,
            'visible_in_catalog' => false,
            'requires_mapping' => false,
            'duplicate_status' => 'archived',
            'meta' => [
                'archived_by_category_reset' => true,
                'old_code' => str_replace('ARCHIVED-', '', $code),
            ],
        ]);
    }

    private function supplierMapping(StandardCategory $category): SupplierCategoryMapping
    {
        $supplier = Supplier::query()->first()
            ?: Supplier::query()->create([
                'name' => 'Kategori Test Tedarikçi',
                'code' => 'KTT',
                'status' => 'active',
            ]);

        $source = SupplierSource::query()->first()
            ?: SupplierSource::query()->create([
                'supplier_id' => $supplier->id,
                'source_type' => 'xml',
                'source_name' => 'Kategori Test XML',
                'url' => 'https://example.test/feed.xml',
                'status' => 'active',
            ]);

        return SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_category' => 'Tedarikçi Test Kategorisi',
            'target_category' => $category->full_path,
            'standard_category_id' => $category->id,
            'mapping_status' => 'pending',
            'decision_type' => 'review',
            'is_active' => true,
        ]);
    }

    private function getOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get($uri);
    }

    private function postOnCentralHost(string $uri, array $data)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->post($uri, $data);
    }

    private function putOnCentralHost(string $uri, array $data)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->put($uri, $data);
    }

    private function deleteOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->delete($uri);
    }
}
