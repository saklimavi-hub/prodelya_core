<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailSendHotfixRegressionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_send_hotfix_behaviour_is_preserved(): void
    {
        $quote = $this->createPromotionQuote('TK-DETAIL-HOTFIX-01');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::DETAIL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => '',
                'contact_phone' => '02125018233',
                'sent_channel' => 'whatsapp_link',
            ])
            ->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $log = NotificationLog::query()
            ->where('related_id', $quote->id)
            ->where('notification_key', 'whatsapp_manual_link')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('902125018233', $log->recipient_phone);

        \App\Models\CompanyContact::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('company_id', $this->customer->id)
            ->delete();

        $this->customer->forceFill([
            'email' => null,
            'phone' => '05320000000',
            'mobile' => '05320000000',
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::DETAIL_HOST])
            ->from(route('admin.promotion-quotes.show', $quote))
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => '',
                'contact_phone' => '05320000000',
                'sent_channel' => 'manual',
            ])
            ->assertSessionHasErrors('error');
    }
}
