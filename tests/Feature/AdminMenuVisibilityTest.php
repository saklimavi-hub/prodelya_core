<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMenuVisibilityTest extends TestCase
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
            'package_key' => 'starter',
            'panel_subdomain' => 'menu-guarded',
            'slug' => 'menu-guarded',
        ])->save();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereIn('module_key', [
                'product_data_hub',
                'advanced_catalog',
                'customer_portal',
                'notification_center',
                'reporting',
                'api_access',
            ])
            ->delete();

        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'portal_enabled', false, 'boolean');

        TenantSupplierAccess::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->update(['is_active' => false]);
    }

    public function test_tenant_layout_renders_service_driven_menu_and_hides_unavailable_optional_items(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'));

        $response->assertOk();
        $response->assertSee('Gösterge Paneli');
        $response->assertSee('Promosyon Teklifleri');
        $response->assertSee('Firmalar / Cari Kartlar');
        $response->assertDontSee('Baski Teklifleri');
        $response->assertDontSee('Product Data Hub');
        $response->assertDontSee('Müşteri Portalı');
        $response->assertDontSee('Moduller');
        $response->assertDontSee('Super Ayarlar');
        $response->assertSee('class="pd-sidebar-item active', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'product_data_hub',
            'feature_key' => 'tenant_catalog_projection',
            'is_enabled' => true,
        ]);

        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');

        $supplier = Supplier::query()->create([
            'name' => 'Visible PDH Supplier',
            'code' => 'VISIBLE-PDH',
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

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'));

        $response->assertOk();
        $response->assertDontSee('Product Data Hub');
        $response->assertSee('Müşteri Portalı');
    }

    public function test_super_admin_layout_shows_only_super_admin_menu_items(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Super Admin Paneli');
        $response->assertSee('Tenantlar');
        $response->assertSee('Product Data Hub');
        $response->assertSee('Standart Kategori Ağacı');
        $response->assertDontSee('Promosyon Teklifleri');
        $response->assertDontSee('Firmalar / Cari Kartlar');
        $response->assertDontSee('Moduller');
        $response->assertDontSee('Super Ayarlar');
        $response->assertSee('data-sidebar-group="product-data-hub"', false);
    }
}
