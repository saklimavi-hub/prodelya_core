<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\AdminMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMenuServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_admin_menu_service_filters_items_by_route_status_and_access(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Menu Tenant',
            'legal_name' => 'Menu Tenant Ltd.',
            'slug' => 'menu-tenant',
            'panel_subdomain' => 'menu-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $service = app(AdminMenuService::class);

        $tenantMenu = $service->tenantMenu($tenant, $this->adminUser);
        $flatBefore = $this->flattenLabels($tenantMenu);

        $this->assertNotEmpty(config('admin_menu.tenant'));
        $this->assertNotEmpty(config('admin_menu.super_admin'));
        $this->assertContains('Gösterge Paneli', $flatBefore);
        $this->assertContains('Teklifler', $flatBefore);
        $this->assertNotContains('Baskı Teklifleri', $flatBefore);
        $this->assertNotContains('İş Formları', $flatBefore);
        $this->assertNotContains('Product Data Hub', $flatBefore);
        $this->assertNotContains('Müşteri Portalı', $flatBefore);
        $this->assertNotContains('Kalite Kontrol', $flatBefore);
        $this->assertNotContains('Moduller', $flatBefore);
        $this->assertNotContains('Super Ayarlar', $flatBefore);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'product_data_hub',
            'feature_key' => 'tenant_catalog_projection',
            'is_enabled' => true,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'advanced_catalog',
            'feature_key' => 'product_variants',
            'is_enabled' => true,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'reporting',
            'feature_key' => 'sales_analytics',
            'is_enabled' => true,
        ]);

        TenantSetting::setValue($tenant->id, 'enable_customer_portal', true, 'boolean');

        $supplier = Supplier::query()->create([
            'name' => 'Menu PDH Supplier',
            'code' => 'MENU-PDH',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $tenantMenuAfter = $service->tenantMenu($tenant->fresh(), $this->adminUser);
        $flatAfter = $this->flattenLabels($tenantMenuAfter);

        $this->assertContains('Ürün ve Katalog', $flatAfter);
        $this->assertContains('Katalog Ürünleri', $flatAfter);
        $this->assertContains('Kendi Ürünlerim', $flatAfter);
        $this->assertNotContains('Product Data Hub', $flatAfter);
        $this->assertNotContains('Ürün Paneli', $flatAfter);
        $this->assertNotContains('Tedarikçi Ürünleri', $flatAfter);
        $this->assertNotContains('Katalog Görünürlüğü', $flatAfter);
        $this->assertNotContains('Uyarılı Ürünler', $flatAfter);
        $this->assertContains('Baskı / Matbaa Tanımları', $flatAfter);
        $this->assertContains('Kurulum Merkezi', $flatAfter);
        $this->assertContains('Sistem Ayarları', $flatAfter);
        $this->assertNotContains('Müşteri Portalı', $flatAfter);
        $this->assertNotContains('Raporlar', $flatAfter);
        $this->assertNotContains('Kalite Kontrol', $flatAfter);

        $superMenu = $service->superAdminMenu($this->adminUser);
        $flatSuper = $this->flattenLabels($superMenu);
        $this->assertContains('Super Admin Paneli', $flatSuper);
        $this->assertContains('Abone Firmalar', $flatSuper);
        $this->assertContains('Başvurular', $flatSuper);
        $this->assertContains('Product Data Hub', $flatSuper);
        $this->assertContains('Standart Kategori Ağacı', $flatSuper);
        $this->assertNotContains('Paket Talepleri', $flatSuper);
        $this->assertNotContains('Modüller', $flatSuper);
        $this->assertNotContains('Super Ayarlar', $flatSuper);
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
