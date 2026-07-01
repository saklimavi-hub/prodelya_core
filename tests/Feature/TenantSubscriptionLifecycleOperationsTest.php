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

class TenantSubscriptionLifecycleOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private User $tenantAdmin;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'Lifecycle Tenant',
            'legal_name' => 'Lifecycle Tenant Ltd.',
            'slug' => 'lifecycle-tenant',
            'panel_subdomain' => 'lifecycle-tenant',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $adminRoleId = Role::query()->where('key', 'admin')->value('id');
        $this->tenantAdmin = User::query()->create([
            'name' => 'Lifecycle Tenant Admin',
            'email' => 'lifecycle-tenant-admin@example.test',
            'password' => 'password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $this->tenantAdmin->id,
            'role_id' => $adminRoleId,
            'tenant_account_id' => $this->tenant->id,
        ]);
    }

    public function test_super_admin_tenant_list_shows_lifecycle_visibility_and_warnings(): void
    {
        $trialTenant = $this->createLifecycleTenant('trial-list', 'Trial Liste', 'active', [
            'subscription_lifecycle_state' => 'trial',
            'subscription_trial_ends_at' => now()->addDays(3)->toDateString(),
        ]);
        $expiredTenant = $this->createLifecycleTenant('expired-list', 'Expired Liste', 'active', [
            'subscription_lifecycle_state' => 'active',
            'subscription_package_ends_at' => now()->subDay()->toDateString(),
        ]);
        $suspendedTenant = $this->createLifecycleTenant('suspended-list', 'Suspended Liste', 'suspended', [
            'subscription_lifecycle_state' => 'suspended',
            'subscription_suspended_reason' => 'Tahsilat bekleniyor',
        ]);
        $passiveTenant = $this->createLifecycleTenant('passive-list', 'Passive Liste', 'inactive', [
            'subscription_lifecycle_state' => 'passive',
        ]);

        $response = $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.index'));

        $response->assertOk();
        $response->assertSee($trialTenant->name);
        $response->assertSee($expiredTenant->name);
        $response->assertSee($suspendedTenant->name);
        $response->assertSee($passiveTenant->name);
        $response->assertSee('7 gün içinde bitecek');
        $response->assertSee('Süresi Dolmuş');
        $response->assertSee('Askıda');
        $response->assertSee('Pasif');
    }

    public function test_super_admin_tenant_show_displays_subscription_dates_notes_and_hides_missing_actions(): void
    {
        TenantSetting::setValue($this->tenant->id, 'subscription_lifecycle_state', 'trial', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_trial_starts_at', '2026-06-01', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_trial_ends_at', '2026-06-30', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_package_starts_at', '2026-06-01', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_package_ends_at', '2026-07-31', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_status_note', 'Manuel takip ediliyor', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_status_updated_at', now()->toDateTimeString(), 'string');

        $response = $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant));

        $response->assertOk();
        $response->assertSee('Abonelik / Paket ve Kullanım');
        $response->assertSee('Deneme Başlangıcı');
        $response->assertSee('01.06.2026');
        $response->assertSee('30.06.2026');
        $response->assertSee('31.07.2026');
        $response->assertSee('Manuel takip ediliyor');
        $response->assertDontSee('Deneme Süresini Uzat');
        $response->assertDontSee('Askıdan Çıkar / Aktifleştir');
        $response->assertSee('Aboneliği Düzenle');
    }

    public function test_super_admin_tenant_edit_updates_lifecycle_fields_safely(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.tenants.update', $this->tenant), [
                'name' => $this->tenant->name,
                'legal_name' => $this->tenant->legal_name,
                'panel_subdomain' => $this->tenant->panel_subdomain,
                'custom_domain' => '',
                'portal_domain' => '',
                'package_key' => $this->tenant->package_key,
                'status' => 'suspended',
                'subscription_trial_starts_at' => '2026-06-01',
                'subscription_trial_ends_at' => '2026-06-15',
                'subscription_package_starts_at' => '2026-06-01',
                'subscription_package_ends_at' => '2026-07-01',
                'subscription_status_note' => 'Manuel askıya alma',
                'subscription_suspended_reason' => 'Ödeme doğrulaması bekleniyor',
                'default_locale' => $this->tenant->default_locale,
                'default_currency' => $this->tenant->default_currency,
                'timezone' => $this->tenant->timezone,
                'company_display_name' => $this->tenant->name,
                'company_legal_name' => $this->tenant->legal_name,
                'company_phone' => '',
                'company_email' => '',
                'company_country' => 'Türkiye',
            ]);

        $response->assertRedirect(route('admin.super.tenants.edit', $this->tenant));

        $this->assertSame('suspended', $this->tenant->fresh()->status);
        $this->assertSame('suspended', TenantSetting::getValue($this->tenant->id, 'subscription_lifecycle_state'));
        $this->assertSame('2026-06-15', TenantSetting::getValue($this->tenant->id, 'subscription_trial_ends_at'));
        $this->assertSame('2026-07-01', TenantSetting::getValue($this->tenant->id, 'subscription_package_ends_at'));
        $this->assertSame('Manuel askıya alma', TenantSetting::getValue($this->tenant->id, 'subscription_status_note'));
        $this->assertSame('Ödeme doğrulaması bekleniyor', TenantSetting::getValue($this->tenant->id, 'subscription_suspended_reason'));
    }

    public function test_expired_suspended_and_passive_route_behavior_is_enforced_without_breaking_public_tracking(): void
    {
        TenantSetting::setValue($this->tenant->id, 'subscription_lifecycle_state', 'trial', 'string');
        TenantSetting::setValue($this->tenant->id, 'subscription_trial_ends_at', now()->addDays(2)->toDateString(), 'string');

        $this->actingAs($this->tenantAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'))
            ->assertOk();

        TenantSetting::setValue($this->tenant->id, 'subscription_trial_ends_at', now()->subDay()->toDateString(), 'string');

        $this->actingAs($this->tenantAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Suresi Dolmus');

        $this->actingAs($this->tenantAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings'), [
                'work_folder_root_name' => 'EXPIRED-BLOCK',
            ])
            ->assertForbidden();

        TenantSetting::setValue($this->tenant->id, 'subscription_lifecycle_state', 'suspended', 'string');
        $this->tenant->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->tenantAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        TenantSetting::setValue($this->tenant->id, 'subscription_lifecycle_state', 'passive', 'string');
        $this->tenant->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->tenantAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant))
            ->assertOk();

        $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.edit', $this->tenant))
            ->assertOk();

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();
    }

    private function createLifecycleTenant(string $slug, string $name, string $status, array $settings = []): TenantAccount
    {
        $tenant = TenantAccount::query()->create([
            'name' => $name,
            'legal_name' => $name . ' Ltd.',
            'slug' => $slug,
            'panel_subdomain' => $slug,
            'status' => $status,
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        foreach ($settings as $key => $value) {
            TenantSetting::setValue($tenant->id, $key, $value, 'string');
        }

        return $tenant;
    }
}
