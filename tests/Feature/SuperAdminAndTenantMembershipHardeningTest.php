<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminAndTenantMembershipHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $demoTenant;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_platform_super_admin_can_access_central_super_admin_routes(): void
    {
        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertOk()
            ->assertSee('Abone Firmalar');
    }

    public function test_non_platform_tenant_admins_cannot_access_super_admin_routes_on_central_host(): void
    {
        $tenant = $this->createTenant('tenant-central-blocked');
        $tenantAdmin = $this->createTenantAdmin($tenant, 'tenant-admin-central-blocked@example.test');
        $demoTenantAdmin = $this->createTenantAdmin($this->demoTenant, 'demo-admin-central-blocked@example.test');

        $this->actingAs($tenantAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();

        $this->actingAs($demoTenantAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_access_own_tenant_host_but_not_another_tenant_host(): void
    {
        $memberTenant = $this->createTenant('member-tenant');
        $foreignTenant = $this->createTenant('foreign-tenant');
        $tenantAdmin = $this->createTenantAdmin($memberTenant, 'member-admin@example.test');

        $this->actingAs($tenantAdmin, 'web')
            ->get($this->tenantUrl($memberTenant, '/admin/dashboard'))
            ->assertOk();

        $this->actingAs($tenantAdmin, 'web')
            ->get($this->tenantUrl($foreignTenant, '/admin/dashboard'))
            ->assertForbidden()
            ->assertSee('Bu tenant paneline erişim yetkiniz yok.');
    }

    public function test_central_host_login_redirects_tenant_admin_to_preferred_tenant_dashboard(): void
    {
        $tenant = $this->createTenant('redirect-tenant');
        $tenantAdmin = $this->createTenantAdmin($tenant, 'tenant-login-redirect@example.test');

        $this->post($this->centralUrl('/login'), [
                'email' => $tenantAdmin->email,
                'password' => 'secret-password',
            ])
            ->assertRedirect('http://' . $this->tenantHost($tenant) . '/admin/dashboard');
    }

    public function test_platform_super_admin_cannot_use_tenant_host_as_daily_admin_surface(): void
    {
        $tenant = $this->createTenant('platform-blocked-tenant');

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertForbidden();
    }

    public function test_customer_portal_and_public_routes_keep_their_auth_boundaries(): void
    {
        $tenant = $this->createTenant('portal-boundary-tenant');
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Portal Boundary Company',
            'short_name' => 'Portal Boundary Company',
            'email' => 'portal-boundary-company@example.test',
            'phone' => '02120000000',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        $contact = CompanyContact::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Portal Boundary Contact',
            'email' => 'portal-boundary-contact@example.test',
            'phone' => '02121111111',
            'is_primary' => true,
        ]);
        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'name' => 'Portal Boundary User',
            'email' => 'portal-boundary-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->enablePortalLogin($tenant);

        $this->actingAs($portalUser, 'customer_portal');
        $this->assertTrue(auth('customer_portal')->check());
        $this->assertFalse(auth('web')->check());
        auth('customer_portal')->logout();

        $tenantAdmin = $this->createTenantAdmin($tenant, 'tenant-admin-portal-boundary@example.test');

        $this->actingAs($tenantAdmin, 'web')
            ->get($this->tenantUrl($tenant, '/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->get($this->centralUrl('/takip/is-formu/missing-token'))
            ->assertNotFound();

        $this->get($this->centralUrl('/teklif/onay/missing-token'))
            ->assertNotFound();

        $this->get($this->centralUrl('/grafik/onay/missing-token'))
            ->assertNotFound();
    }

    public function test_demo_tenant_full_access_is_preserved_but_planned_modules_stay_closed(): void
    {
        $demoTenantAdmin = $this->createTenantAdmin($this->demoTenant, 'demo-feature-admin@example.test');

        $this->actingAs($demoTenantAdmin, 'web')
            ->get($this->tenantUrl($this->demoTenant, '/admin/product-data-hub'))
            ->assertForbidden();

        $this->actingAs($demoTenantAdmin, 'web')
            ->get($this->tenantUrl($this->demoTenant, '/admin/print-service-quotes'))
            ->assertForbidden();
    }

    public function test_platform_admin_authorization_does_not_depend_on_tenant_one_membership(): void
    {
        $platformOnlyUser = User::query()->create([
            'name' => 'Platform Only Admin',
            'email' => 'platform-only-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformOnlyUser, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertOk();
    }

    public function test_ensure_super_admin_command_promotes_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'existing-platform-admin@example.test',
            'is_platform_admin' => false,
        ]);

        $this->artisan('app:ensure-super-admin', [
            'email' => $user->email,
            '--no-interaction' => true,
        ])
            ->expectsOutput('Platform Admin Bootstrap Ozeti')
            ->expectsOutputToContain('Mevcut kullanici platform admin olarak guncellenecek')
            ->expectsOutput('Platform admin hazir.')
            ->assertExitCode(0);

        $this->assertTrue((bool) $user->fresh()->is_platform_admin);
    }

    public function test_ensure_super_admin_command_creates_new_platform_admin_user(): void
    {
        $this->artisan('app:ensure-super-admin', [
            'email' => 'created-platform-admin@example.test',
            '--name' => 'Created Platform Admin',
            '--password' => 'SecretPass123!',
            '--no-interaction' => true,
        ])
            ->expectsOutput('Platform Admin Bootstrap Ozeti')
            ->expectsOutputToContain('Yeni platform admin kullanicisi olusturulacak')
            ->expectsOutput('Platform admin hazir.')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'created-platform-admin@example.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->is_platform_admin);
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
    }

    private function createTenantAdmin(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->adminRole->id,
        ]);

        return $user;
    }

    private function enablePortalLogin(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'customer_login',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $this->tenantHost($tenant) . $path;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
