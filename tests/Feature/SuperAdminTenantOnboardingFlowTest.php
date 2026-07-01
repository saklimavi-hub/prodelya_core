<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantOnboardingFlowTest extends TestCase
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

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_super_admin_can_create_tenant_with_owner_and_defaults_in_single_flow(): void
    {
        $package = Package::query()->where('key', 'enterprise')->where('status', 'active')->firstOrFail();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.store'), [
                'name' => 'Onboarding Flow Tenant',
                'legal_name' => 'Onboarding Flow Tenant Ltd.',
                'slug' => 'Onboarding Flow Tenant',
                'panel_subdomain' => '',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Onboarding Owner',
                'owner_email' => 'onboarding-flow-owner@example.test',
                'owner_password' => '',
                'owner_phone' => '05320000001',
                'custom_domain' => 'https://app.onboarding-flow.test/login',
                'portal_domain' => 'portal.onboarding-flow.test',
            ]);

        $tenant = TenantAccount::query()->where('slug', 'onboarding-flow-tenant')->firstOrFail();
        $owner = User::query()->where('email', 'onboarding-flow-owner@example.test')->firstOrFail();

        $response->assertRedirect(route('admin.super.tenants.show', $tenant));
        $response->assertSessionHas('success');
        $response->assertSessionHas('onboarding_defaults_summary');
        $response->assertSessionHas('owner_temporary_password');

        $this->assertSame('onboarding-flow-tenant', $tenant->panel_subdomain);
        $this->assertSame('app.onboarding-flow.test', $tenant->custom_domain);
        $this->assertSame('portal.onboarding-flow.test', $tenant->portal_domain);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $owner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);
        $this->assertFalse((bool) $owner->is_platform_admin);
        $this->assertTrue($tenant->settings()->exists());
        $this->assertTrue($tenant->notificationTemplates()->exists());
        $this->assertTrue($tenant->printSettings()->exists());
        $this->assertNotNull(TenantSetting::getValue($tenant->id, 'work_folder_root_name'));

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('Onboarding Owner');
        $show->assertSee('onboarding-flow-owner@example.test');
        $show->assertSee('Abone Firma Hazırlık Durumu');
        $show->assertSee('Paneli Aç');
        $show->assertSee('Tenant ayarları varsayılanları');
    }

    public function test_create_without_owner_keeps_onboarding_visible_and_incomplete(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.store'), [
                'name' => 'Ownerless Tenant',
                'legal_name' => 'Ownerless Tenant Ltd.',
                'slug' => 'ownerless-tenant',
                'panel_subdomain' => 'ownerless-tenant',
                'status' => 'active',
                'package_key' => 'starter',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
            ]);

        $tenant = TenantAccount::query()->where('slug', 'ownerless-tenant')->firstOrFail();

        $response->assertRedirect(route('admin.super.tenants.show', $tenant));
        $response->assertSessionHas('onboarding_defaults_summary');

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('Owner kullanıcı henüz oluşturulmadı.');
        $show->assertSee('Owner Oluştur');
        $show->assertSee('Panel girişi için aktif tenant, panel adresi ve owner gerekir');
    }

    public function test_duplicate_slug_subdomain_and_owner_email_are_blocked_safely(): void
    {
        TenantAccount::query()->create([
            'name' => 'Existing Onboarding Tenant',
            'legal_name' => 'Existing Onboarding Tenant Ltd.',
            'slug' => 'existing-onboarding-tenant',
            'panel_subdomain' => 'existing-onboarding-tenant',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $user = User::factory()->create([
            'email' => 'existing-tenant-user@example.test',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $this->demoTenant->id,
            'role_id' => Role::query()->where('key', 'admin')->firstOrFail()->id,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.create'))
            ->post(route('admin.super.tenants.store'), [
                'name' => 'Duplicate Flow Tenant',
                'legal_name' => '',
                'slug' => 'existing-onboarding-tenant',
                'panel_subdomain' => 'existing-onboarding-tenant',
                'status' => 'active',
                'package_key' => 'starter',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Existing Owner',
                'owner_email' => 'existing-tenant-user@example.test',
            ])
            ->assertRedirect(route('admin.super.tenants.create'))
            ->assertSessionHasErrors(['slug', 'panel_subdomain', 'owner_email']);
    }

    public function test_tenant_admin_cannot_access_super_admin_create_flow(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Tenant Admin Guard',
            'legal_name' => 'Tenant Admin Guard Ltd.',
            'slug' => 'tenant-admin-guard',
            'panel_subdomain' => 'tenant-admin-guard',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $tenantOwner = User::factory()->create([
            'email' => 'tenant-admin-guard@example.test',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantOwner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($tenantOwner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/create'))
            ->assertForbidden();
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
