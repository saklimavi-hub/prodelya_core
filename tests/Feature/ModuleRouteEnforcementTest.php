<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleRouteEnforcementTest extends TestCase
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
            'panel_subdomain' => 'module-guarded',
            'slug' => 'module-guarded',
        ])->save();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereIn('module_key', ['product_data_hub', 'customer_portal', 'production_qc'])
            ->delete();

        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'portal_enabled', false, 'boolean');

        TenantSupplierAccess::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->update(['is_active' => false]);

        if (!Route::has('test.module-alias-order-flow')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'module.enabled:promotion_orders'])
                ->get('/admin/test/module-alias-order-flow', fn () => response('ok', 200))
                ->name('test.module-alias-order-flow');
        }

        if (!Route::has('test.module-alias-quality-control')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'module.enabled:quality_control'])
                ->get('/admin/test/module-alias-quality-control', fn () => response('ok', 200))
                ->name('test.module-alias-quality-control');
        }

        if (!Route::has('test.customer-portal')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'module.enabled:customer_portal'])
                ->get('/admin/test/customer-portal', fn () => response('portal', 200))
                ->name('test.customer-portal');
        }
    }

    public function test_optional_module_routes_are_hard_stopped_when_disabled_and_open_when_enabled(): void
    {
        $disabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.index'));

        $disabled->assertForbidden();
        $disabled->assertSee('aktif değil');
        $disabled->assertDontSee('Stack trace');

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'product_data_hub',
            'feature_key' => 'tenant_catalog_projection',
            'is_enabled' => true,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Route Enforcement Supplier',
            'code' => 'ROUTE-ENF-001',
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

        $enabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.index'));

        $enabled->assertForbidden();
        $enabled->assertSee('yalnız Super Admin tarafından yönetilir.');

        $portalDisabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/customer-portal');

        $portalDisabled->assertForbidden();

        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');

        $portalEnabled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/customer-portal');

        $portalEnabled->assertOk();
        $portalEnabled->assertSee('portal');
    }

    public function test_planned_modules_alias_routes_and_tenant_lifecycle_are_enforced(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.print-service-quotes.index'))
            ->assertForbidden()
            ->assertSee('aktif değil');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/module-alias-order-flow')
            ->assertOk()
            ->assertSee('ok');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/test/module-alias-quality-control')
            ->assertForbidden()
            ->assertSee('aktif değil');

        $this->tenant->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->tenant->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'))
            ->assertForbidden();

        $this->tenant->forceFill(['status' => 'active'])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertOk();

        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->tenant->forceFill(['status' => 'trial'])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_super_admin_and_public_tracking_routes_are_not_broken_by_tenant_module_guards(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertOk();

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();
    }
}
