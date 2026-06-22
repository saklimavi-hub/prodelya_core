<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppLinkCreationTest extends TestCase
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
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->tenant->forceFill(['package_key' => 'starter'])->save();
        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();

        $this->enableNotificationCenterAccess();
    }

    public function test_whatsapp_link_creation_normalizes_phone_encodes_message_and_logs_safely(): void
    {
        $payload = [
            'customer_name' => 'Ayşe',
            'recipient_phone' => '0532 000 00 00',
            'message_type' => 'general',
            'message' => 'Merhaba KDV bakiye maliyet {{group_code}} {{file_path}} {{pdh_raw}}',
            'public_link' => 'https://example.test/takip/abc123',
        ];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.whatsapp.create-link'), $payload);

        $response->assertRedirect(route('admin.settings.notifications.whatsapp'));
        $response->assertSessionHas('success');
        $response->assertSessionHas('whatsapp_preview');
        $response->assertSessionHas('whatsapp_result');

        $result = session('whatsapp_result');
        $this->assertIsArray($result);
        $this->assertStringStartsWith('https://wa.me/905320000000?text=', (string) $result['url']);
        $this->assertStringContainsString(rawurlencode('Merhaba Ayşe,'), (string) $result['url']);
        $this->assertStringNotContainsString(rawurlencode('KDV'), (string) $result['url']);
        $this->assertStringNotContainsString(rawurlencode('bakiye'), (string) $result['url']);
        $this->assertStringNotContainsString(rawurlencode('maliyet'), (string) $result['url']);

        $log = NotificationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('whatsapp_manual_link', $log->notification_key);
        $this->assertSame(NotificationLog::CHANNEL_WHATSAPP_LINK, $log->channel);
        $this->assertSame(NotificationLog::STATUS_LINK_CREATED, $log->status);
        $this->assertNotSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('905320000000', $log->recipient_phone);
        $this->assertStringNotContainsString('KDV', (string) $log->message_preview);
        $this->assertStringNotContainsString('bakiye', (string) $log->message_preview);
        $this->assertStringNotContainsString('maliyet', (string) $log->message_preview);
        $this->assertStringNotContainsString('group_code', (string) $log->message_preview);
        $this->assertStringNotContainsString('file_path', (string) $log->message_preview);
        $this->assertStringNotContainsString('pdh_raw', (string) $log->message_preview);
    }

    public function test_public_tracking_surface_is_not_affected_by_whatsapp_link_creation(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.whatsapp.create-link'), [
                'customer_name' => 'Ayşe',
                'recipient_phone' => '0532 000 00 00',
                'message_type' => 'quote_link',
                'message' => '',
                'public_link' => 'https://example.test/teklif/abc123',
            ])
            ->assertRedirect(route('admin.settings.notifications.whatsapp'));

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();
    }

    private function enableNotificationCenterAccess(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );
    }
}
