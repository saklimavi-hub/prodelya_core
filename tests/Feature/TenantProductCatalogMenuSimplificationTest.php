<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\AdminMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProductCatalogMenuSimplificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'enterprise',
            'panel_subdomain' => 'catalog-menu-simplified',
            'slug' => 'catalog-menu-simplified',
        ])->save();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'advanced_catalog',
                'feature_key' => 'product_variants',
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'advanced_catalog',
                'feature_key' => 'local_stock',
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'product_data_hub',
                'feature_key' => 'tenant_catalog_projection',
            ],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Menu Simplification Supplier',
            'code' => 'MENU-SIMPLIFY',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);
    }

    public function test_tenant_menu_hides_product_data_hub_and_shows_only_simplified_catalog_entries(): void
    {
        $menu = app(AdminMenuService::class)->tenantMenu($this->tenant->fresh(), $this->adminUser);
        $labels = $this->flattenLabels($menu);

        $this->assertContains('Ürün ve Katalog', $labels);
        $this->assertContains('Katalog Ürünleri', $labels);
        $this->assertContains('Kendi Ürünlerim', $labels);
        $this->assertNotContains('Product Data Hub', $labels);
        $this->assertNotContains('Ürün Paneli', $labels);
        $this->assertNotContains('Tedarikçi Ürünleri', $labels);
        $this->assertNotContains('Katalog Görünürlüğü', $labels);
        $this->assertNotContains('Uyarılı Ürünler', $labels);
    }

    public function test_tenant_product_data_hub_routes_are_forbidden_while_catalog_routes_keep_working(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.index'))
            ->assertForbidden()
            ->assertSee('yalnız Super Admin tarafından yönetilir.');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.catalog.index'))
            ->assertOk()
            ->assertSee('Katalog Ürünleri');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.catalog.local-products'))
            ->assertOk()
            ->assertSee('Kendi Ürünlerim');
    }

    public function test_super_admin_menu_keeps_product_data_hub_visible(): void
    {
        $menu = app(AdminMenuService::class)->superAdminMenu($this->adminUser);
        $labels = $this->flattenLabels($menu);

        $this->assertContains('Product Data Hub', $labels);
        $this->assertContains('Standart Kategori Ağacı', $labels);
    }

    private function flattenLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            if (!empty($item['label'])) {
                $labels[] = $item['label'];
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $labels = array_merge($labels, $this->flattenLabels($item['children']));
            }
        }

        return $labels;
    }
}
