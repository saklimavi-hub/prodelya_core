<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveReadinessAndDemoDataChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $demoTenant;
    private Role $tenantOwnerRole;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_dashboard_shows_live_readiness_and_demo_cleanup_sections(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Canlıya Hazırlık');
        $response->assertSee('Canlıya Hazır Abone Firma');
        $response->assertSee('Demo/Test Abone Firma');
        $response->assertSee('Sistem Genel Readiness');
        $response->assertSee('Dosya Depolama / Public Storage');
        $response->assertSee('Kuyruk İşleyici');
        $response->assertSee('Canlıya Geçiş Öncesi Demo Kontrolü');
        $response->assertSee('Demo/Test Abone Firma');
        $response->assertSee('Gerçek Tenant ile Karışma');
        $response->assertSee('Kontrol Edilmeli');
    }

    public function test_tenant_show_renders_live_readiness_checklist_with_domain_security_and_lifecycle_signals(): void
    {
        $tenant = $this->createTenant('readiness-tenant', 'active');
        $this->assignTenantUser($tenant, 'owner-readiness@example.test', $this->tenantOwnerRole);
        $this->assignTenantUser($tenant, 'admin-readiness@example.test', $this->adminRole);

        TenantSetting::setValue($tenant->id, 'company_display_name', 'Readiness Tenant A.Ş.', 'string');
        TenantSetting::setValue($tenant->id, 'company_email', 'hello@readiness.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_host', 'smtp.readiness.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_from_email', 'notify@readiness.test', 'string');
        TenantSetting::setValue($tenant->id, 'whatsapp_test_phone', '905551112233', 'string');
        TenantSetting::setValue($tenant->id, 'work_folder_root_name', 'ISLER', 'string');

        Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'name' => 'Readiness Cari',
            'legal_name' => 'Readiness Cari A.Ş.',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $tenant));

        $response->assertOk();
        $response->assertSee('Abone Firma Hazırlık Durumu');
        $response->assertSee('Owner / Yönetici kullanıcı');
        $response->assertSee('Aktif kullanıcı');
        $response->assertSee('Paket seçimi');
        $response->assertSee('Panel adresi');
        $response->assertSee('SMTP durumu');
        $response->assertSee('WhatsApp durumu');
        $response->assertSee('Katalog / Product Hub erişimi');
        $response->assertSee('Public tracking güvenliği');
        $response->assertSee('Storage / upload durumu');
        $response->assertSee('Lifecycle durumu');
        $response->assertSee('Canlı Adayı');
    }

    public function test_demo_cleanup_report_is_read_only_and_does_not_delete_demo_records(): void
    {
        $beforeUsers = UserRole::query()
            ->where('tenant_account_id', $this->demoTenant->id)
            ->count();
        $beforeCompanies = $this->demoTenant->companies()->count();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Demo/Test');

        $this->assertSame($beforeUsers, UserRole::query()->where('tenant_account_id', $this->demoTenant->id)->count());
        $this->assertSame($beforeCompanies, $this->demoTenant->fresh()->companies()->count());
    }

    public function test_tenant_admin_cannot_access_super_admin_live_readiness_surfaces(): void
    {
        $tenant = $this->createTenant('readiness-isolation', 'active');
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin-readiness@example.test',
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
