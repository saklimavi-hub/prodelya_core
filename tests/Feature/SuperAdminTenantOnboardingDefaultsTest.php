<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantOnboardingDefaultsTest extends TestCase
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

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_platform_admin_can_see_onboarding_status_card_on_show_and_edit(): void
    {
        $tenant = $this->createTenant('onboarding-status');

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('Başlangıç Ayarları');
        $show->assertSee('Başlangıç Ayarlarını Hazırla');
        $show->assertSee(route('admin.super.tenants.prepare-defaults', $tenant), false);

        $edit = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.edit', $tenant));

        $edit->assertOk();
        $edit->assertSee('Başlangıç Ayarları');
        $edit->assertSee('SMTP ayar ekranı hazırdır ancak bu fazda SMTP kurulmuş sayılmaz.');
    }

    public function test_tenant_owner_and_demo_admin_cannot_run_prepare_defaults_route(): void
    {
        $tenant = $this->createTenant('onboarding-guarded');
        $tenantOwner = $this->createTenantUser($tenant, $this->tenantOwnerRole, 'guarded-owner@example.test');
        $demoAdmin = $this->createTenantUser($this->demoTenant, $this->adminRole, 'guarded-demo@example.test');

        $this->actingAs($tenantOwner, 'web')
            ->post($this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/prepare-defaults'))
            ->assertForbidden();

        $this->actingAs($demoAdmin, 'web')
            ->post($this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/prepare-defaults'))
            ->assertForbidden();
    }

    public function test_prepare_defaults_creates_missing_records_without_overwriting_existing_overrides(): void
    {
        $tenant = $this->createTenant('onboarding-prepare');
        $type = StandardPrintType::query()->where('status', StandardPrintType::STATUS_ACTIVE)->firstOrFail();

        TenantPrintSetting::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_print_type_id' => $type->id,
            'custom_name' => 'Korunacak Baskı',
            'is_active' => true,
            'production_mode' => $type->default_production_mode,
            'default_currency' => 'USD',
            'requires_graphic' => true,
            'requires_production' => true,
            'requires_setup' => false,
            'setup_types' => [],
        ]);

        NotificationTemplate::query()->create([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'internal',
            'title' => 'Korunacak Şablon',
            'subject' => 'Özel konu',
            'body' => 'Özel içerik',
            'is_active' => true,
            'variables_json' => [],
        ]);

        TenantSetting::setValue($tenant->id, 'default_currency', 'USD', 'string');
        TenantSetting::setValue($tenant->id, 'work_folder_root_name', 'OZEL-KLASOR', 'string');
        TenantSetting::setValue($tenant->id, 'portal_enabled', false, 'boolean');

        $beforeAccess = app(TenantAccessService::class)->canAccessModule($tenant->fresh(), 'customer_portal');

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.prepare-defaults', $tenant));

        $response->assertRedirect(route('admin.super.tenants.edit', $tenant));
        $response->assertSessionHas('success');
        $response->assertSessionHas('onboarding_defaults_summary');

        $typeSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_print_type_id', $type->id)
            ->firstOrFail();

        $this->assertSame('Korunacak Baskı', $typeSetting->custom_name);
        $this->assertSame('USD', $typeSetting->default_currency);

        $template = NotificationTemplate::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', 'email')
            ->where('audience_type', 'internal')
            ->firstOrFail();

        $this->assertSame('Özel konu', $template->subject);
        $this->assertSame('Özel içerik', $template->body);
        $this->assertSame('USD', TenantSetting::getValue($tenant->id, 'default_currency'));
        $this->assertSame('OZEL-KLASOR', TenantSetting::getValue($tenant->id, 'work_folder_root_name'));
        $this->assertFalse((bool) TenantSetting::getValue($tenant->id, 'portal_enabled', true));
        $this->assertSame($beforeAccess, app(TenantAccessService::class)->canAccessModule($tenant->fresh(), 'customer_portal'));
        $this->assertTrue(TenantSetting::query()->where('tenant_account_id', $tenant->id)->where('key', 'timezone')->exists());
        $this->assertTrue(TenantSetting::query()->where('tenant_account_id', $tenant->id)->where('key', 'enable_customer_portal')->exists());
    }

    public function test_prepare_defaults_is_idempotent_and_does_not_create_sensitive_settings(): void
    {
        $tenant = $this->createTenant('onboarding-idempotent');

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.prepare-defaults', $tenant))
            ->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $settingsCount = TenantSetting::query()->where('tenant_account_id', $tenant->id)->count();
        $templateCount = NotificationTemplate::query()->where('tenant_account_id', $tenant->id)->count();
        $printCount = TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->count();

        $second = $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.prepare-defaults', $tenant));

        $second->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $this->assertSame($settingsCount, TenantSetting::query()->where('tenant_account_id', $tenant->id)->count());
        $this->assertSame($templateCount, NotificationTemplate::query()->where('tenant_account_id', $tenant->id)->count());
        $this->assertSame($printCount, TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->count());
        $this->assertDatabaseMissing('tenant_settings', [
            'tenant_account_id' => $tenant->id,
            'key' => 'smtp_password',
        ]);
        $this->assertDatabaseMissing('tenant_settings', [
            'tenant_account_id' => $tenant->id,
            'key' => 'api_key',
        ]);
    }

    public function test_prepare_defaults_keeps_owner_login_and_public_customer_boundaries_stable_without_sensitive_leakage(): void
    {
        $tenant = $this->createTenant('onboarding-boundary');
        $owner = User::query()->create([
            'name' => 'Onboarding Owner',
            'email' => 'onboarding-owner@example.test',
            'password' => 'TenantOwner123!',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $owner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->post(route('admin.super.tenants.prepare-defaults', $tenant))
            ->assertRedirect(route('admin.super.tenants.edit', $tenant));

        $this->post($this->centralUrl('/login'), [
            'email' => $owner->email,
            'password' => 'TenantOwner123!',
        ])->assertRedirect($this->tenantUrl($tenant, '/admin/dashboard'));

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertDontSee('smtp_password', false);
        $show->assertDontSee('physical_path', false);
        $show->assertDontSee('file_path', false);

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

    private function createTenantUser(TenantAccount $tenant, Role $role, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Tenant User',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
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
