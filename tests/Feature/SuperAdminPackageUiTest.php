<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\AdminMenuService;
use App\Services\PackageCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminPackageUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const TENANT_HOST = 'demo.prodelya.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_super_admin_package_crud_and_config_surfaces_work(): void
    {
        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.index'));

        $index->assertOk();
        $index->assertSee('Paketler');
        $index->assertSee('Starter');

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.create'));

        $create->assertOk();
        $create->assertSee('Yeni Paket');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.packages.store'), [
                'key' => 'campaign_plus',
                'name' => 'Campaign Plus',
                'description' => 'UI test paketi',
                'status' => 'active',
                'is_public' => '1',
                'trial_days' => 10,
                'monthly_price' => '1500',
                'yearly_price' => '15000',
                'currency' => 'TRY',
                'sort_order' => 55,
                'notes' => 'test note',
            ])
            ->assertRedirect();

        $package = Package::query()->where('key', 'campaign_plus')->firstOrFail();
        $this->assertSame('Campaign Plus', $package->name);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.edit', $package));

        $edit->assertOk();
        $edit->assertSee('Paket Bilgileri');
        $edit->assertSee('Modül Yönetimi');
        $edit->assertSee('Feature Yönetimi');
        $edit->assertSee('Limit Yönetimi');
        $edit->assertSee('Cekirdek Sistem');
        $edit->assertSee('Product Data Hub');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.packages.update', $package), [
                'key' => 'campaign_plus',
                'name' => 'Campaign Plus Updated',
                'description' => 'updated',
                'status' => 'passive',
                'is_public' => '0',
                'trial_days' => 12,
                'monthly_price' => '1750',
                'yearly_price' => '17000',
                'currency' => 'USD',
                'sort_order' => 65,
                'notes' => 'updated note',
            ])
            ->assertRedirect(route('admin.super.packages.edit', $package));

        $package->refresh();
        $this->assertSame('Campaign Plus Updated', $package->name);
        $this->assertSame('passive', $package->status);
        $this->assertSame('USD', $package->currency);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.packages.modules.update', $package), [
                'modules' => ['promotion_orders', 'product_data_hub', 'quality_control'],
            ])
            ->assertRedirect(route('admin.super.packages.edit', $package));

        $package->refresh();
        $this->assertDatabaseHas('package_modules', [
            'package_id' => $package->id,
            'module_key' => 'order_flow',
        ]);
        $this->assertDatabaseHas('package_modules', [
            'package_id' => $package->id,
            'module_key' => 'product_data_hub',
        ]);
        $this->assertDatabaseHas('package_modules', [
            'package_id' => $package->id,
            'module_key' => 'production_qc',
        ]);
        $this->assertSame(3, $package->modules()->count());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.packages.features.update', $package), [
                'features' => ['customer_quote_approval', 'tenant_catalog_projection'],
            ])
            ->assertRedirect(route('admin.super.packages.edit', $package));

        $this->assertDatabaseHas('package_features', [
            'package_id' => $package->id,
            'feature_key' => 'public_quote_approval',
        ]);
        $this->assertDatabaseHas('package_features', [
            'package_id' => $package->id,
            'feature_key' => 'tenant_catalog_projection',
        ]);
        $this->assertSame(2, $package->fresh()->features()->count());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.packages.limits.update', $package), [
                'limits' => [
                    'users' => ['limit_value' => '15', 'is_unlimited' => '0', 'notes' => 'team'],
                    'orders' => ['limit_value' => '1200', 'is_unlimited' => '0'],
                    'api_tokens' => ['limit_value' => '', 'is_unlimited' => '1'],
                ],
            ])
            ->assertRedirect(route('admin.super.packages.edit', $package));

        $this->assertDatabaseHas('package_limits', [
            'package_id' => $package->id,
            'limit_key' => 'users',
            'limit_value' => 15,
            'is_unlimited' => 0,
        ]);
        $this->assertDatabaseHas('package_limits', [
            'package_id' => $package->id,
            'limit_key' => 'api_tokens',
            'limit_value' => null,
            'is_unlimited' => 1,
        ]);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.show', $package));

        $show->assertOk();
        $show->assertSee('Campaign Plus Updated');
        $show->assertSee('Product Data Hub');
        $show->assertSee('tenant_catalog_projection');
        $show->assertSee('API Token');
        $show->assertDontSee('smtp_password', false);
        $show->assertDontSee('API key', false);
        $show->assertDontSee('file_path', false);

        $catalog = app(PackageCatalogService::class);
        $this->assertTrue($catalog->hasModule($package->fresh(), 'product_data_hub'));

        $tenant = TenantAccount::query()->create([
            'name' => 'Package UI Tenant',
            'legal_name' => 'Package UI Tenant Ltd.',
            'slug' => 'package-ui-tenant',
            'panel_subdomain' => 'package-ui-tenant',
            'status' => 'active',
            'package_key' => 'campaign_plus',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $access = app(TenantAccessService::class);
        $usage = app(TenantUsageService::class);

        $this->assertTrue($access->canAccessModule($tenant, 'product_data_hub'));
        $this->assertFalse($access->canAccessModule($tenant, 'production_qc'));
        $this->assertTrue($access->canAccessFeature($tenant, 'customer_quote_approval', 'quote_customer_approval'));
        $this->assertSame(15, $usage->getUsageForKey($tenant, 'users')['limit']);
        $this->assertSame('unlimited', $usage->getUsageForKey($tenant, 'api_tokens')['status']);
    }

    public function test_super_admin_package_routes_and_menu_are_not_visible_to_tenant_context(): void
    {
        $tenant = TenantAccount::query()->firstOrCreate(
            ['panel_subdomain' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'legal_name' => 'Demo Tenant Ltd.',
                'slug' => 'demo',
                'status' => 'active',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'number_format_locale' => 'tr_TR',
            ]
        );

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/super-admin/packages')
            ->assertForbidden();

        $menuService = app(AdminMenuService::class);
        $tenantMenuItems = $menuService->tenantMenu($tenant, $this->adminUser);
        $tenantLabels = collect($tenantMenuItems)
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();

        $this->assertNotContains('Paketler', $tenantLabels);

        $superAdminMenuItems = $menuService->superAdminMenu($this->adminUser);
        $superAdminLabels = collect($superAdminMenuItems)
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();

        $this->assertContains('Paketler', $superAdminLabels);
    }
}
