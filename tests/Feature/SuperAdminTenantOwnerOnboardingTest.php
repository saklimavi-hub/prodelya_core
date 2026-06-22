<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminTenantOwnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $demoTenant;
    private Role $adminRole;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_platform_admin_can_open_owner_create_form(): void
    {
        $tenant = $this->createTenant('owner-create-form');

        $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.owner.create', $tenant))
            ->assertOk()
            ->assertSee('Tenant Owner Oluştur')
            ->assertSee(route('admin.super.tenants.owner.store', $tenant), false);
    }

    public function test_tenant_owner_and_demo_tenant_admin_cannot_open_owner_create_form(): void
    {
        $tenant = $this->createTenant('owner-form-blocked');
        $tenantOwner = $this->createTenantUser($tenant, 'tenant_owner', 'blocked-owner@example.test');
        $demoAdmin = $this->createTenantUser($this->demoTenant, 'admin', 'blocked-demo-admin@example.test');

        $this->actingAs($tenantOwner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/owner/create'))
            ->assertForbidden();

        $this->actingAs($demoAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/owner/create'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_create_tenant_owner_with_expected_role_and_security_flags(): void
    {
        $tenant = $this->createTenant('owner-store-ok');
        $tenantModulesCount = \App\Models\TenantModule::query()->count();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.owner.create', $tenant))
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Owner User',
                'email' => 'owner-store-ok@example.test',
                'phone' => '05320000000',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
                'is_active' => '1',
            ]);

        $user = User::query()->where('email', 'owner-store-ok@example.test')->firstOrFail();

        $response->assertRedirect(route('admin.super.tenants.edit', $tenant));
        $response->assertSessionHas('success');

        $this->assertFalse((bool) $user->is_platform_admin);
        $this->assertSame('05320000000', $user->phone);
        $this->assertNotSame('TenantOwner123!', $user->password);
        $this->assertTrue(Hash::check('TenantOwner123!', $user->password));
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);
        $this->assertSame($tenantModulesCount, \App\Models\TenantModule::query()->count());

        $edit = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.edit', $tenant));

        $edit->assertOk();
        $edit->assertSee('Owner User');
        $edit->assertSee('owner-store-ok@example.test');
        $edit->assertDontSee($user->password, false);
        $edit->assertDontSee('Owner kullanıcı henüz oluşturulmadı.');
    }

    public function test_owner_can_log_in_on_own_tenant_host_but_cannot_access_super_admin_or_foreign_tenant(): void
    {
        $tenant = $this->createTenant('owner-login-home');
        $foreignTenant = $this->createTenant('owner-login-foreign');

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Tenant Owner Login',
                'email' => 'tenant-owner-login@example.test',
                'phone' => '',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $owner = User::query()->where('email', 'tenant-owner-login@example.test')->firstOrFail();

        $this->post($this->centralUrl('/login'), [
            'email' => $owner->email,
            'password' => 'TenantOwner123!',
        ])->assertRedirect($this->tenantUrl($tenant, '/admin/dashboard'));

        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->assertNotNull($owner->fresh()->last_login_ip);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($foreignTenant, '/admin/dashboard'))
            ->assertForbidden()
            ->assertSee('Bu tenant paneline erişim yetkiniz yok.');
    }

    public function test_duplicate_email_and_platform_admin_email_are_blocked(): void
    {
        $tenant = $this->createTenant('owner-email-rules');

        User::factory()->create([
            'email' => 'duplicate-owner@example.test',
            'is_platform_admin' => false,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.owner.create', $tenant))
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Duplicate Owner',
                'email' => 'duplicate-owner@example.test',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
            ])
            ->assertRedirect(route('admin.super.tenants.owner.create', $tenant))
            ->assertSessionHasErrors(['email']);

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.owner.create', $tenant))
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Platform Email Owner',
                'email' => 'admin@prodelya.local',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
            ])
            ->assertRedirect(route('admin.super.tenants.owner.create', $tenant))
            ->assertSessionHasErrors(['email']);
    }

    public function test_show_and_edit_surfaces_missing_owner_warning_and_existing_owner_details(): void
    {
        $tenant = $this->createTenant('owner-visibility');

        $showBefore = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $showBefore->assertOk();
        $showBefore->assertSee('Owner kullanıcı henüz oluşturulmadı.');
        $showBefore->assertSee('Owner Oluştur');

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Visible Owner',
                'email' => 'visible-owner@example.test',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
            ]);

        $showAfter = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $showAfter->assertOk();
        $showAfter->assertSee('Visible Owner');
        $showAfter->assertSee('visible-owner@example.test');
        $showAfter->assertSee('Owner Hazır');
    }

    public function test_generated_password_is_flashed_once_and_not_stored_in_plain_text(): void
    {
        $tenant = $this->createTenant('owner-generated-password');

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Generated Password Owner',
                'email' => 'generated-password-owner@example.test',
                'password' => '',
                'role' => 'tenant_owner',
            ]);

        $user = User::query()->where('email', 'generated-password-owner@example.test')->firstOrFail();
        $temporaryPassword = session('owner_temporary_password');

        $response->assertRedirect(route('admin.super.tenants.edit', $tenant));
        $this->assertIsString($temporaryPassword);
        $this->assertNotSame('', $temporaryPassword);
        $this->assertNotSame($temporaryPassword, $user->password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
    }

    public function test_owner_create_does_not_break_public_links_or_customer_portal_boundaries(): void
    {
        $tenant = $this->createTenant('owner-public-regression');

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'Boundary Owner',
                'email' => 'boundary-owner@example.test',
                'password' => 'TenantOwner123!',
                'role' => 'tenant_owner',
            ]);

        $owner = User::query()->where('email', 'boundary-owner@example.test')->firstOrFail();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->get($this->centralUrl('/takip/is-formu/missing-token'))->assertNotFound();
        $this->get($this->centralUrl('/teklif/onay/missing-token'))->assertNotFound();
        $this->get($this->centralUrl('/grafik/onay/missing-token'))->assertNotFound();
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
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, string $roleKey, string $email): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user = User::factory()->create([
            'email' => $email,
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
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
