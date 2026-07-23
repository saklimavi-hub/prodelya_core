<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsNotificationTemplateCssPolishTest extends TestCase
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
            'panel_subdomain' => 'settings-template-css',
            'slug' => 'settings-template-css',
        ])->save();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_settings_and_notification_pages_keep_prodelya_layout_and_single_active_menu_state(): void
    {
        $this->enableNotificationCenterAccess(['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs']);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'recipient_name' => 'Finance Team',
            'status' => NotificationLog::STATUS_FAILED,
            'created_by' => $this->adminUser->id,
        ]);

        $settings = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settings->assertOk();
        $settings->assertSeeInOrder([
            'Firma Profili',
            'Panel ve Portal',
            'Bildirimler',
            'Paket ve Limitler',
            'Kullanıcılar ve Roller',
            'Katalog ve Product Hub',
            'Dosya ve Depolama',
            'Talep Merkezi',
        ]);
        $this->assertSingleActiveSidebarItem($settings->getContent());

        $notifications = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.index'));

        $notifications->assertOk();
        $notifications->assertSee('Acil Dikkat');
        $this->assertSingleActiveSidebarItem($notifications->getContent());

        $logs = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.index'));

        $logs->assertOk();
        $logs->assertSee('Son Bildirimler');
        $logs->assertSee('Başarısızlar');
        $logs->assertSee('WhatsApp Linkleri');
        $logs->assertSee('Mail Önizleme/Pending');

        $templates = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.index'));

        $templates->assertOk();
        $templates->assertSee('Teklif');
        $templates->assertSee('Grafik');
        $templates->assertSee('Tedarik');
        $templates->assertSee('Üretim');
        $templates->assertSee('Teslimat');
        $templates->assertSee('Finans');

        $smtp = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'));

        $smtp->assertOk();
        $smtp->assertSee('Mail Gönderimi');
        $smtp->assertSee('mevcut şifre korunur');
        $this->assertSingleActiveSidebarItem($smtp->getContent());

        $whatsapp = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'));

        $whatsapp->assertOk();
        $whatsapp->assertSee('WhatsApp Hazır Mesaj');
        $whatsapp->assertSee('otomatik API gönderimi yapmaz');
        $this->assertSingleActiveSidebarItem($whatsapp->getContent());
    }

    private function assertSingleActiveSidebarItem(string $html): void
    {
        $activeCount = substr_count($html, 'class="pd-sidebar-item active')
            + substr_count($html, 'class="pd-sidebar-submenu-item active');

        $this->assertSame(1, $activeCount);
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
