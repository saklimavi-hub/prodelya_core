<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndNotificationUxPolishTest extends TestCase
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
            'panel_subdomain' => 'settings-notification-ux',
            'slug' => 'settings-notification-ux',
        ])->save();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_settings_and_notification_surfaces_use_task_based_language_and_keep_guards_safe(): void
    {
        $this->enableNotificationCenterAccess(['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs']);

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 10, 'integer');
        TenantSetting::setValue($this->tenant->id, 'storage_used_mb', 90, 'integer');
        TenantSetting::setValue($this->tenant->id, 'limit_storage_mb', 100, 'integer');
        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'smtp_password', 'enc::hidden-value', 'string');
        TenantSetting::setValue($this->tenant->id, 'api_key', 'secret-key', 'string');
        TenantSetting::setValue($this->tenant->id, 'raw_token', 'secret-token', 'string');

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'recipient_name' => 'Finance Team',
            'subject' => 'Fail',
            'message_preview' => 'Safe preview',
            'status' => NotificationLog::STATUS_FAILED,
            'created_by' => $this->adminUser->id,
        ]);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'whatsapp_link',
            'audience_type' => 'customer',
            'recipient_name' => 'Demo Customer',
            'subject' => 'Link',
            'message_preview' => 'Link preview',
            'status' => NotificationLog::STATUS_LINK_CREATED,
            'created_by' => $this->adminUser->id,
        ]);

        $settings = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settings->assertOk();
        $settings->assertSee('Firma ve Genel Bilgiler');
        $settings->assertSee('Operasyon Ayarları');
        $settings->assertSee('Müşteri Portalı');
        $settings->assertSee('Bildirimler');
        $settings->assertSee('Paket ve Limitler');
        $settings->assertSee('Kullanıcılar ve Yetkiler');
        $settings->assertSee('Bildirim Merkezi');
        $settings->assertSee('Portal ve Uyarılar');
        $settings->assertSee('Limit dolmak üzere');
        $settings->assertDontSee('notification_center', false);
        $settings->assertDontSee('smtp_password', false);
        $settings->assertDontSee('secret-key', false);
        $settings->assertDontSee('secret-token', false);

        $dashboard = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.index'));

        $dashboard->assertOk();
        $dashboard->assertSee('Acil Dikkat');
        $dashboard->assertSee('Başarısız bildirimler var');
        $dashboard->assertSee('Başarısızları Gör');
        $dashboard->assertSee('SMTP Ayarları');
        $dashboard->assertSee('WhatsApp Ayarları');
        $dashboard->assertSee('Hızlı Bağlantılar');
        $dashboard->assertSee('Mail Gönderimi');
        $dashboard->assertSee('WhatsApp');
        $dashboard->assertDontSee('smtp_password', false);
        $dashboard->assertDontSee('api_key', false);
        $dashboard->assertDontSee('raw_token', false);

        $logs = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.index'));

        $logs->assertOk();
        $logs->assertSee('Son Bildirimler');
        $logs->assertSee('Başarısızlar');
        $logs->assertSee('WhatsApp Linkleri');
        $logs->assertSee('Mail Önizleme/Pending');

        $smtp = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'));

        $smtp->assertOk();
        $smtp->assertSee('Mail Gönderimi');
        $smtp->assertDontSee('hidden-value', false);

        $whatsapp = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'));

        $whatsapp->assertOk();
        $whatsapp->assertSee('WhatsApp Hazır Mesaj');
        $whatsapp->assertSee('otomatik API gönderimi yapmaz');

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'smtp_settings',
            ],
            ['is_enabled' => false]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => false]
        );

        $settingsAfterFeatureClose = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settingsAfterFeatureClose->assertOk();
        $settingsAfterFeatureClose->assertDontSee(route('admin.settings.notifications.smtp'), false);
        $settingsAfterFeatureClose->assertDontSee(route('admin.settings.notifications.whatsapp'), false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => false]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.index'))
            ->assertForbidden();
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
