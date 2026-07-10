<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailAlertPlacementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_success_flash_message_is_rendered_in_quote_alert_area(): void
    {
        $quote = $this->createPromotionQuote();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::DETAIL_HOST])
            ->withSession([
                'success' => 'Teklif müşteriye e-posta olarak gönderildi. Public onay linki hazır. WhatsApp hazır mesaj linki üretildi.',
            ])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('quote-alert quote-alert-success', false);
        $response->assertSee('data-testid="quote-alert-success"', false);
        $response->assertDontSee('quote-send-success-flash', false);
        $response->assertDontSee('WhatsApp için e-posta şartı yoktur. 0212 sabit WhatsApp numarası desteklenir. Gönderim kaydı ve public onay akışı mevcut hotfix davranışlarıyla korunur.');
    }
}
