<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationOutboxCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_notification_logs_are_hardened_for_v1_outbox_usage(): void
    {
        foreach ([
            'audience_type',
            'template_id',
            'attempt_count',
            'scheduled_at',
            'next_retry_at',
            'provider_response',
            'response_code',
            'meta_json',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('notification_logs', $column));
        }

        $dispatch = app(NotificationDispatchService::class);
        $template = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => 'Teklifiniz hazır',
            'body' => 'Merhaba {{customer_name}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $pending = $dispatch->logPending([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'template_id' => $template->id,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'customer',
            'recipient_email' => 'customer@example.test',
            'subject' => 'Teklifiniz hazır',
            'message_preview' => 'Merhaba',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertSame(NotificationLog::STATUS_PENDING, $pending->status);
        $this->assertSame($template->id, $pending->template_id);
        $this->assertSame(NotificationTemplate::AUDIENCE_CUSTOMER, $pending->audience_type);

        $preview = $dispatch->dispatchEmailPreview($this->tenant, [
            'notification_key' => 'quote_sent_email',
            'template_id' => $template->id,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'customer',
            'recipient_email' => 'preview@example.test',
            'subject' => 'Preview',
            'body' => 'smtp_password file_path C:\\secret group_code ABC',
            'provider_response' => ['api_key' => 'secret'],
            'meta_json' => ['token' => 'hidden', 'safe' => 'ok'],
        ], $this->adminUser);

        $this->assertSame(NotificationLog::STATUS_PREVIEW, $preview->status);
        $this->assertStringNotContainsString('smtp_password', (string) $preview->message_preview);
        $this->assertStringNotContainsString('file_path', (string) $preview->message_preview);
        $this->assertStringNotContainsString('group_code', (string) $preview->message_preview);
        $this->assertSame(['safe' => 'ok'], $preview->meta_json);

        $whatsapp = $dispatch->createWhatsappLink($this->tenant, '0532 000 00 00', 'Merhaba group_code smtp_password', [
            'notification_key' => 'quote_sent_whatsapp',
            'template_id' => $template->id,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'customer',
            'recipient_name' => 'ABC İnşaat',
        ], $this->adminUser);

        $this->assertTrue($whatsapp['log']->isWhatsappLinkCreated());
        $this->assertSame(NotificationLog::STATUS_LINK_CREATED, $whatsapp['log']->status);

        $internal = $dispatch->dispatchInternal($this->tenant, [
            'notification_key' => 'quote_customer_approved',
            'template_id' => $template->id,
            'audience_type' => NotificationTemplate::AUDIENCE_ADMIN,
            'recipient_type' => 'team',
            'recipient_name' => 'İç Ekip',
            'subject' => 'Onay geldi',
            'body' => 'Internal body',
        ], $this->adminUser);

        $this->assertTrue($internal->isSent());
        $this->assertSame(NotificationLog::STATUS_SENT, $internal->status);

        $sms = $dispatch->dispatch($this->tenant, NotificationTemplate::CHANNEL_SMS, [
            'notification_key' => 'quote_sent_to_customer',
            'recipient_type' => 'customer',
            'body' => 'SMS içeriği',
        ], $this->adminUser);

        $this->assertInstanceOf(NotificationLog::class, $sms);
        $this->assertSame(NotificationLog::STATUS_SKIPPED, $sms->status);
    }
}
