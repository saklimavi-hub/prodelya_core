<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $demoTenant;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_super_admin_tenant_list_marks_demo_and_uses_abone_firma_wording(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.index'));

        $response->assertOk();
        $response->assertSee('Abone Firmalar');
        $response->assertSee('Yeni Abone Firma');
        $response->assertSee('Demo');
        $response->assertDontSee('SAKLImavi');
    }

    public function test_super_admin_context_does_not_render_demo_tenant_fallback_for_platform_only_admin(): void
    {
        $platformOnlyUser = User::query()->create([
            'name' => 'Platform Only Admin',
            'email' => 'platform-only-lifecycle@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => true,
        ]);

        $response = $this->actingAs($platformOnlyUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Super Admin');
        $response->assertDontSee('Demo Tenant');
        $response->assertDontSee('Abone Firma Paneli:');
    }

    public function test_platform_admin_login_redirects_to_super_admin_dashboard(): void
    {
        $this->post($this->centralUrl('/login'), [
            'email' => $this->platformAdmin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.super.dashboard'));
    }

    public function test_platform_admin_tenant_panel_context_is_rendered_explicitly(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'));

        $response->assertOk();
        $response->assertSee('Abone Firma Paneli: Demo Şirketi');
    }

    public function test_tenant_detail_shows_owner_supplier_and_projection_gaps(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Eksik Hazirlik',
            'legal_name' => 'Eksik Hazirlik Ltd.',
            'slug' => 'eksik-hazirlik',
            'panel_subdomain' => 'eksik-hazirlik',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'customer_portal',
            'feature_key' => null,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $tenant));

        $response->assertOk();
        $response->assertSee('Abone Firma Hazırlık Durumu');
        $response->assertSee('Owner kullanıcı eksik');
        $response->assertSee('Hazır tedarikçi erişimi yok');
        $response->assertSee('Katalog projection yapılmamış');
        $response->assertSee('Abone Firma Paneline Gir');
    }

    public function test_supplier_access_routes_are_open_to_super_admin_and_closed_to_tenant_owner(): void
    {
        $tenantOwner = User::query()->create([
            'name' => 'Tenant Owner',
            'email' => 'tenant-owner-hardening@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantOwner->id,
            'tenant_account_id' => $this->demoTenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenant-supplier-access.index'))
            ->assertOk();

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenant-supplier-access.index'))
            ->assertForbidden();
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
