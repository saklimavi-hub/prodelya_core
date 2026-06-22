<?php

namespace Tests\Feature;

use App\Services\Notifications\NotificationEventCatalogService;
use Tests\TestCase;

class NotificationEventCatalogTest extends TestCase
{
    public function test_canonical_notification_event_catalog_is_available_and_aliases_normalize(): void
    {
        $catalog = app(NotificationEventCatalogService::class);

        $this->assertNotEmpty($catalog->events());
        $this->assertNotNull($catalog->getEvent('quote_sent_to_customer'));
        $this->assertSame('quote_sent_to_customer', $catalog->normalizeEventKey('quote_sent_email'));
        $this->assertSame('quote_sent_to_customer', $catalog->normalizeEventKey('quote_sent_whatsapp'));
        $this->assertSame('procurement_received', $catalog->normalizeEventKey('procurement_completed'));
        $this->assertSame('production_problem_reported', $catalog->normalizeEventKey('production_issue_reported'));
        $this->assertSame(['email', 'whatsapp_link', 'internal'], $catalog->allowedChannels('quote_sent_to_customer'));
        $this->assertSame('customer', $catalog->defaultAudience('quote_sent_to_customer'));
    }

    public function test_sms_channel_stays_passive_in_notification_config(): void
    {
        $smsChannel = config('prodelya_notifications.channels.sms');

        $this->assertIsArray($smsChannel);
        $this->assertSame('sms', $smsChannel['key'] ?? null);
        $this->assertSame('passive', $smsChannel['status'] ?? null);
    }
}
