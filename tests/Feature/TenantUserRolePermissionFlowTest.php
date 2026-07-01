<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserRolePermissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private User $ownerUser;
    private User $tenantAdminUser;
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('ekip-tenant');
        $this->otherTenant = $this->createTenant('diger-ekip-tenant');
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->ownerUser = $this->createTenantUser($this->tenant, 'tenant_owner', 'owner@ekip.test', 'Ekip Owner');
        $this->tenantAdminUser = $this->createTenantUser($this->tenant, 'admin', 'admin@ekip.test', 'Ekip Admin');
        $this->createTenantUser($this->tenant, 'finance', 'finance@ekip.test', 'Ekip Finans');
        $this->createTenantUser($this->otherTenant, 'admin', 'other-admin@ekip.test', 'Other Admin');
    }

    public function test_tenant_admin_lists_only_own_tenant_users_with_turkish_labels(): void
    {
        $response = $this->actingAs($this->tenantAdminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('Abone Firma Kullanıcıları');
        $response->assertSee('Hesap Sahibi');
        $response->assertSee('Yönetici');
        $response->assertSee('Finans Yetkili');
        $response->assertDontSee('Other Admin');
        $response->assertDontSee('admin@prodelya.local');
    }

    public function test_tenant_admin_cannot_access_foreign_tenant_user_edit_screen(): void
    {
        $otherUser = User::query()->where('email', 'other-admin@ekip.test')->firstOrFail();

        $this->actingAs($this->tenantAdminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get('http://' . $this->tenantHost($this->tenant) . '/admin/users/' . $otherUser->id . '/edit')
            ->assertForbidden();
    }

    public function test_super_admin_tenant_show_displays_team_summary(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant));

        $response->assertOk();
        $response->assertSee('Owner / Ekip Durumu');
        $response->assertSee('Toplam Kullanıcı');
        $response->assertSee('Finans Yetkili');
        $response->assertSee('Kullanıcıları Gör');
    }

    public function test_last_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->tenantAdminUser, 'web')
            ->from(route('admin.users.index'))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->delete(route('admin.users.destroy', $this->ownerUser))
            ->assertForbidden();

        $this->assertDatabaseHas('user_roles', [
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->ownerUser->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);
    }

    public function test_finance_permission_protection_stays_intact(): void
    {
        $deliveryUser = $this->createTenantUser($this->tenant, 'delivery', 'delivery@ekip.test', 'Ekip Teslimat');
        $financeUser = User::query()->where('email', 'finance@ekip.test')->firstOrFail();

        $this->actingAs($deliveryUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get('http://' . $this->tenantHost($this->tenant) . '/admin/finance')
            ->assertForbidden();

        $this->actingAs($financeUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get('http://' . $this->tenantHost($this->tenant) . '/admin/finance')
            ->assertOk();
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, string $roleKey, string $email, string $name): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
