<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Priority-1 binding: notification settings (SMTP/WhatsApp) and templates
 * were previously reachable by any tenant user. This proves
 * view_notification_center / view_notification_logs / manage_notification_settings
 * / manage_notification_templates are now enforced.
 */
class NotificationPermissionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $tenantOwner;
    private User $adminUser;
    private User $financeUser;
    private User $productionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill(['package_key' => 'enterprise'])->save();

        foreach ([null, 'smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs'] as $featureKey) {
            TenantModule::query()->updateOrCreate(
                ['tenant_account_id' => $this->tenant->id, 'module_key' => 'notification_center', 'feature_key' => $featureKey],
                ['is_enabled' => true]
            );
        }

        $this->tenantOwner = $this->makeUser('tenant_owner', 'owner-notif-perm@example.test');
        $this->adminUser = $this->makeUser('admin', 'admin-notif-perm@example.test');
        $this->financeUser = $this->makeUser('finance', 'finance-notif-perm@example.test');
        $this->productionUser = $this->makeUser('production', 'production-notif-perm@example.test');
    }

    public function test_notifications_index_requires_view_notification_center_permission(): void
    {
        $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/bildirimler'))
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/bildirimler'))
            ->assertOk();

        $this->actingAs($this->tenantOwner, 'web')
            ->get($this->tenantUrl('/admin/bildirimler'))
            ->assertOk();
    }

    public function test_notification_logs_index_requires_view_notification_logs_permission(): void
    {
        $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/bildirimler/kayitlar'))
            ->assertForbidden();

        // finance has view_notification_logs explicitly in config, unlike production.
        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/bildirimler/kayitlar'))
            ->assertOk();

        $this->actingAs($this->tenantOwner, 'web')
            ->get($this->tenantUrl('/admin/bildirimler/kayitlar'))
            ->assertOk();
    }

    public function test_smtp_settings_require_manage_notification_settings_permission(): void
    {
        // finance has view_notification_center/logs but NOT manage_notification_settings:
        // this is the scoping-proof case (a role with other notification perms still blocked here).
        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/ayarlar/bildirimler/smtp'))
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/smtp'), [])
            ->assertForbidden();

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/ayarlar/bildirimler/smtp'))
            ->assertOk();

        $this->actingAs($this->adminUser, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/smtp'), [])
            ->assertRedirect();

        $this->actingAs($this->tenantOwner, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/smtp'), [])
            ->assertRedirect();
    }

    public function test_whatsapp_settings_require_manage_notification_settings_permission(): void
    {
        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/ayarlar/bildirimler/whatsapp'))
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/whatsapp'), [])
            ->assertForbidden();

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/ayarlar/bildirimler/whatsapp'))
            ->assertOk();

        $this->actingAs($this->adminUser, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/whatsapp'), [])
            ->assertRedirect();

        $this->actingAs($this->tenantOwner, 'web')
            ->put($this->tenantUrl('/admin/settings/notifications/whatsapp'), [])
            ->assertRedirect();
    }

    public function test_notification_template_writes_require_manage_notification_templates_permission(): void
    {
        $template = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'customer',
            'title' => 'Existing',
            'subject' => 'Existing subject',
            'body' => 'Existing body',
            'is_active' => true,
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/notifications/templates/sync-defaults'))
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/notifications/templates'), [])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->put($this->tenantUrl('/admin/notifications/templates/' . $template->id), [])
            ->assertForbidden();

        $this->actingAs($this->adminUser, 'web')
            ->post($this->tenantUrl('/admin/notifications/templates/sync-defaults'))
            ->assertStatus(302);

        $this->actingAs($this->adminUser, 'web')
            ->put($this->tenantUrl('/admin/notifications/templates/' . $template->id), [])
            ->assertStatus(302);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/notifications/templates/sync-defaults'))
            ->assertStatus(302);
    }

    private function makeUser(string $roleKey, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
