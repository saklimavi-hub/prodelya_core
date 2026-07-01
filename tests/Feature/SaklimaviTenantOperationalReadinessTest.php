<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use App\Services\TenantOnboardingDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaklimaviTenantOperationalReadinessTest extends TestCase
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

    public function test_fixture_saklimavi_tenant_is_operationally_ready_without_auto_company_bootstrap_or_sensitive_leakage(): void
    {
        $tenant = $this->createTenant('saklimavi-readiness');
        $owner = $this->createOwner($tenant);

        app(TenantOnboardingDefaultsService::class)->prepareDefaults($tenant, $this->platformAdmin);

        $access = app(TenantAccessService::class);
        $this->assertSame('enterprise', $tenant->package_key);
        $this->assertTrue($access->canAccessModule($tenant->fresh(), 'notification_center'));
        $this->assertTrue($access->canAccessModule($tenant->fresh(), 'current_accounts'));
        $this->assertTrue($access->canAccessModule($tenant->fresh(), 'order_flow'));
        $this->assertDatabaseHas('user_roles', [
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $printCount = $tenant->printSettings()->count();
        $templateCount = $tenant->notificationTemplates()->count();
        $settingsCount = $tenant->settings()->count();

        app(TenantOnboardingDefaultsService::class)->prepareDefaults($tenant->fresh(), $this->platformAdmin);

        $this->assertSame($printCount, $tenant->fresh()->printSettings()->count());
        $this->assertSame($templateCount, $tenant->fresh()->notificationTemplates()->count());
        $this->assertSame($settingsCount, $tenant->fresh()->settings()->count());
        $this->assertTrue($tenant->fresh()->printSettings()->exists());
        $this->assertTrue($tenant->fresh()->notificationTemplates()->exists());
        $this->assertNotNull(TenantSetting::getValue($tenant->id, 'work_folder_root_name'));
        $this->assertSame(0, $tenant->fresh()->companies()->count());
        $this->assertSame(0, $tenant->fresh()->currentAccounts()->count());

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertOk();

        $settings = $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings'));

        $settings->assertOk();
        $settings->assertSee('Kurulum Merkezi');
        $settings->assertSee('Firma Profili');
        $settings->assertSee('Kurulum Özeti');
        $settings->assertSee('Çalışma klasörü kök adı');
        $settings->assertSee('http://saklimavi-readiness.' . self::CENTRAL_HOST . '/admin');
        $settings->assertDontSee('smtp_password', false);
        $settings->assertDontSee('file_path', false);
        $settings->assertDontSee('physical_path', false);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/notifications/smtp'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/notifications/whatsapp'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($this->demoTenant, '/admin/dashboard'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/dashboard'))
            ->assertOk();

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/companies'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/promotion-quotes/create'))
            ->assertOk();

        $this->get($this->centralUrl('/takip/is-formu/missing-token'))->assertNotFound();
        $this->get($this->centralUrl('/teklif/onay/missing-token'))->assertNotFound();
        $this->get($this->centralUrl('/grafik/onay/missing-token'))->assertNotFound();
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'SAKLImavi',
            'legal_name' => 'SAKLImavi Reklam Matbaa İletişim Hizmetleri San. Tic. Ltd. Şti.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createOwner(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'SAKLImavi Admin',
            'email' => 'owner-' . $tenant->panel_subdomain . '@example.test',
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

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
