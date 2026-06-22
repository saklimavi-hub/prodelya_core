<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaklimaviTenantBootstrapSmokeTest extends TestCase
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

    public function test_fixture_bootstrap_flow_creates_tenant_owner_and_defaults_without_demo_shortcuts(): void
    {
        $tenantPayload = [
            'name' => 'SAKLImavi Smoke',
            'legal_name' => 'SAKLImavi Smoke Ltd. Şti.',
            'slug' => 'saklimavi-smoke',
            'panel_subdomain' => 'saklimavi-smoke',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ];

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.store'), $tenantPayload)
            ->assertRedirect();

        $tenant = TenantAccount::query()->where('slug', 'saklimavi-smoke')->firstOrFail();

        $this->assertSame('saklimavi-smoke', $tenant->slug);
        $this->assertSame('saklimavi-smoke', $tenant->panel_subdomain);
        $this->assertSame('enterprise', $tenant->package_key);
        $this->assertSame('active', $tenant->status);

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.owner.store', $tenant), [
                'name' => 'SAKLImavi Smoke Admin',
                'email' => 'admin@saklimavi-smoke.local',
                'password' => 'SakliMavi123!',
                'role' => 'tenant_owner',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $owner = User::query()->where('email', 'admin@saklimavi-smoke.local')->firstOrFail();

        $this->assertFalse((bool) $owner->is_platform_admin);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $owner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.prepare-defaults', $tenant))
            ->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $this->assertNotNull(TenantSetting::getValue($tenant->id, 'work_folder_root_name'));
        $this->assertTrue(TenantSetting::query()->where('tenant_account_id', $tenant->id)->where('key', 'portal_enabled')->exists());
        $this->assertTrue($tenant->printSettings()->exists());
        $this->assertTrue($tenant->notificationTemplates()->exists());

        $this->post($this->centralUrl('/login'), [
            'email' => $owner->email,
            'password' => 'SakliMavi123!',
        ])->assertRedirect($this->tenantUrl($tenant, '/admin/dashboard'));

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($this->demoTenant, '/admin/dashboard'))
            ->assertForbidden();
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
