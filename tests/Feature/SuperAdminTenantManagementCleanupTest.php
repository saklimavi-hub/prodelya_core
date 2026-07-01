<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantManagementCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_tenant_index_and_detail_show_operational_sections_and_hide_missing_route_actions(): void
    {
        $readyTenant = $this->createTenant('operasyonel-tenant', 'active');
        $readyTenant->forceFill([
            'custom_domain' => 'app.operasyonel.test',
            'portal_domain' => 'portal.operasyonel.test',
        ])->save();
        $this->assignTenantUser($readyTenant, 'owner-operasyonel@example.test', $this->tenantOwnerRole);
        $missingTenant = $this->createTenant('eksik-tenant', 'active');

        $index = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.index'));

        $index->assertOk();
        $index->assertSee('Abone Firmalar');
        $index->assertSee('Kullanım / Limit');
        $index->assertSee('Owner');
        $index->assertSee('Panel Adresi');
        $index->assertSee('Onboarding');
        $index->assertSee($readyTenant->name);
        $index->assertSee('Owner eksik');

        $filtered = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.index', ['domain_missing' => 1]));

        $filtered->assertOk();
        $filtered->assertSee($missingTenant->name);
        $filtered->assertDontSee($readyTenant->name);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $readyTenant));

        $show->assertOk();
        $show->assertSee('Genel Bilgiler ve Panel Adresleri');
        $show->assertSee('Abone Firma Cari / Fatura Bilgileri');
        $show->assertSee('Owner / Ekip Durumu');
        $show->assertSee('Abonelik / Paket ve Kullanım');
        $show->assertSee('SaaS Cari Özet');
        $show->assertSee('Modül / Override Özeti');
        $show->assertSee('Operasyon Checklist');
        $show->assertSee('Hızlı İşlemler');
        $show->assertSee('Paneli Aç');
        $show->assertSee('Katalog Durumunu Gör');
        $show->assertSee('Kullanıcıları Gör');
        $show->assertDontSee($missingTenant->name);
    }

    public function test_tenant_admin_cannot_access_super_admin_dashboard_or_tenant_detail(): void
    {
        $tenant = $this->createTenant('tenant-admin-blocked', 'active');
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin-cleanup@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->adminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $tenant))
            ->assertForbidden();
    }

    private function createTenant(string $subdomain, string $status): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => $status,
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function assignTenantUser(TenantAccount $tenant, string $email, Role $role): void
    {
        $user = User::query()->create([
            'name' => strtok($email, '@'),
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
    }
}
