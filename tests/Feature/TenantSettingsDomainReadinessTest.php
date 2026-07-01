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

class TenantSettingsDomainReadinessTest extends TestCase
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

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_tenant_settings_landing_shows_company_panel_domain_and_readiness_sections(): void
    {
        $tenant = $this->createTenant('settings-domain-ready');
        $owner = $this->createOwner($tenant, 'settings-domain-ready-owner@example.test');

        $tenant->forceFill([
            'custom_domain' => 'app.settings-domain-ready.test',
            'portal_domain' => 'portal.settings-domain-ready.test',
        ])->save();

        TenantSetting::setValue($tenant->id, 'company_display_name', 'Hazir Tenant', 'string');
        TenantSetting::setValue($tenant->id, 'company_phone', '02120000000', 'string');
        TenantSetting::setValue($tenant->id, 'company_email', 'info@hazir-tenant.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_host', 'smtp.hazir-tenant.test', 'string');
        TenantSetting::setValue($tenant->id, 'whatsapp_default_country_code', '+90', 'string');

        $response = $this->actingAs($owner, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('Kurulum Merkezi');
        $response->assertSee('Firma Profili');
        $response->assertSee('Panel ve Portal');
        $response->assertSee('Bildirimler');
        $response->assertSee('Katalog ve Product Hub');
        $response->assertSee('Kurulum Özeti');
        $response->assertSee('Hazir Tenant');
        $response->assertSee('app.settings-domain-ready.test');
        $response->assertSee('portal.settings-domain-ready.test');
        $response->assertSee('SMTP durumu');
        $response->assertSee('WhatsApp telefonu');
        $response->assertDontSee('Domain Yönetimi');
    }

    public function test_super_admin_show_and_edit_surface_domain_settings_and_safe_validation(): void
    {
        $tenant = $this->createTenant('settings-domain-admin');
        $other = $this->createTenant('taken-panel');
        $other->forceFill([
            'custom_domain' => 'taken.customer.test',
            'portal_domain' => 'portal.taken.customer.test',
        ])->save();

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('Genel Bilgiler ve Panel Adresleri');
        $show->assertSee('Panel Alt Alanı');
        $show->assertSee('Özel Domain');
        $show->assertSee('Portal Domaini');
        $show->assertSee('Varsayılan Para Birimi');

        $edit = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.edit', $tenant));

        $edit->assertOk();
        $edit->assertSee('Cari / Fatura Bilgileri');
        $edit->assertSee('Panel Alt Alanı');
        $edit->assertSee('Panel ve Domain');

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.edit', $tenant))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.tenants.update', $tenant), [
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name,
                'panel_subdomain' => 'demo',
                'custom_domain' => self::CENTRAL_HOST,
                'portal_domain' => 'taken.customer.test',
                'package_key' => $tenant->package_key,
                'status' => 'active',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'company_display_name' => $tenant->name,
                'company_legal_name' => $tenant->legal_name,
                'company_phone' => '',
                'company_email' => '',
                'company_country' => 'Türkiye',
            ])
            ->assertRedirect(route('admin.super.tenants.edit', $tenant))
            ->assertSessionHasErrors(['panel_subdomain', 'custom_domain', 'portal_domain']);
    }

    public function test_tenant_admin_cannot_open_foreign_tenant_settings_screen(): void
    {
        $tenant = $this->createTenant('settings-guard-a');
        $otherTenant = $this->createTenant('settings-guard-b');
        $owner = $this->createOwner($tenant, 'settings-guard-owner@example.test');

        $this->actingAs($owner, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($otherTenant)])
            ->get('http://' . $otherTenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/settings')
            ->assertForbidden();
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

    private function createOwner(TenantAccount $tenant, string $email): User
    {
        $owner = User::query()->create([
            'name' => 'Tenant Owner',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        return $owner;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
