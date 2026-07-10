<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\TenantWhatsappLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPublicApprovalTokenSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_notification_log_storage_redacts_public_approval_links_in_preview_and_meta(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $token = str_repeat('a', 64);
        $publicUrl = route('public.quotes.approval.show', ['token' => $token]);
        $dispatch = app(NotificationDispatchService::class);

        $log = $dispatch->logPreview([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'customer',
            'recipient_name' => 'Ayse Musteri',
            'recipient_email' => 'ayse@example.test',
            'message_preview' => '<a href="' . $publicUrl . '">' . $publicUrl . '</a>',
            'meta_json' => [
                'public_link' => $publicUrl,
                'approval_url' => $publicUrl,
                'url' => 'https://wa.me/905321234567?text=' . rawurlencode("Merhaba\n" . $publicUrl),
                'group_code' => 'SECRET-GROUP',
                'safe_label' => 'ok',
            ],
        ]);

        $this->assertSame('[public-onay-linki-gizlendi]', data_get($log->meta_json, 'public_link'));
        $this->assertSame('[public-onay-linki-gizlendi]', data_get($log->meta_json, 'approval_url'));
        $this->assertSame('[public-onay-linki-gizlendi]', data_get($log->meta_json, 'url'));
        $this->assertSame('ok', data_get($log->meta_json, 'safe_label'));
        $this->assertArrayNotHasKey('group_code', $log->meta_json);
        $this->assertStringNotContainsString($token, (string) $log->message_preview);
        $this->assertStringNotContainsString('/teklif/onay/', (string) $log->message_preview);
        $this->assertStringContainsString('[public-onay-linki-gizlendi]', (string) $log->message_preview);
    }

    public function test_whatsapp_link_returns_real_url_but_stores_redacted_log_meta(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $token = str_repeat('b', 64);
        $publicUrl = route('public.quotes.approval.show', ['token' => $token]);
        $service = app(TenantWhatsappLinkService::class);

        $result = $service->createManualLink($tenant, [
            'customer_name' => 'Ayse Musteri',
            'recipient_phone' => '05321234567',
            'message_type' => TenantWhatsappLinkService::TYPE_QUOTE_LINK,
            'public_link' => $publicUrl,
            'quote_number' => 'TK-SAFE-001',
        ]);

        $this->assertStringStartsWith('https://wa.me/905321234567?text=', (string) $result['url']);
        $this->assertStringContainsString(rawurlencode($publicUrl), (string) $result['url']);
        $this->assertSame('[public-onay-linki-gizlendi]', data_get($result['log']->meta_json, 'url'));
        $this->assertStringNotContainsString($token, (string) $result['log']->message_preview);
        $this->assertStringNotContainsString('/teklif/onay/', (string) $result['log']->message_preview);
        $this->assertStringContainsString('[public-onay-linki-gizlendi]', (string) $result['log']->message_preview);
    }
}
