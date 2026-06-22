<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'notification-guarded',
            'slug' => 'notification-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_notification_center_index_requires_module_and_shows_safe_summary(): void
    {
        $this->enableNotificationCenterAccess(['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs']);

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'customer',
            'recipient_email' => 'customer@example.test',
            'subject' => 'Preview',
            'message_preview' => 'Safe preview',
            'status' => NotificationLog::STATUS_PREVIEW,
            'created_by' => $this->adminUser->id,
        ]);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'recipient_name' => 'Finance Team',
            'subject' => 'Payment log',
            'message_preview' => 'Safe finance preview',
            'status' => NotificationLog::STATUS_FAILED,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.index'));

        $response->assertOk();
        $response->assertSee('Bildirim Merkezi');
        $response->assertSee('Acil Dikkat');
        $response->assertSee('Son Bildirimler');
        $response->assertSee('Mail Gönderimi');
        $response->assertSee('WhatsApp');
        $response->assertSee('Başarısızları Gör');
        $response->assertSee(route('admin.notifications.logs.index'), false);
        $response->assertSee(route('admin.notifications.templates.index'), false);
        $response->assertSee(route('admin.settings.notifications.smtp'), false);
        $response->assertSee(route('admin.settings.notifications.whatsapp'), false);
        $response->assertDontSee('smtp_password', false);
        $response->assertDontSee('api_key', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('group_code', false);

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->whereNull('feature_key')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.index'))
            ->assertForbidden();
    }

    public function test_settings_landing_notification_links_follow_feature_access(): void
    {
        $this->enableNotificationCenterAccess(['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs']);

        $visible = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $visible->assertOk();
        $visible->assertSee(route('admin.settings.notifications.smtp'), false);
        $visible->assertSee(route('admin.settings.notifications.whatsapp'), false);
        $visible->assertSee(route('admin.notifications.templates.index'), false);
        $visible->assertSee(route('admin.notifications.logs.index'), false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_templates',
            ],
            ['is_enabled' => false]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_logs',
            ],
            ['is_enabled' => false]
        );

        $hidden = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $hidden->assertOk();
        $hidden->assertDontSee(route('admin.notifications.templates.index'), false);
        $hidden->assertDontSee(route('admin.notifications.logs.index'), false);
        $hidden->assertSee(route('admin.settings.notifications.smtp'), false);
        $hidden->assertSee(route('admin.settings.notifications.whatsapp'), false);
    }

    private function enableNotificationCenterAccess(array $features): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        foreach ($features as $feature) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $this->tenant->id,
                    'module_key' => 'notification_center',
                    'feature_key' => $feature,
                ],
                ['is_enabled' => true]
            );
        }
    }
}
