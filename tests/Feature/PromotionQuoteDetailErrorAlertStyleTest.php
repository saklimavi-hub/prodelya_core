<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailErrorAlertStyleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_error_flash_uses_error_alert_class(): void
    {
        $quote = $this->createPromotionQuote();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::DETAIL_HOST])
            ->withSession([
                'error' => 'WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.',
            ])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('quote-alert quote-alert-error', false);
        $response->assertSee('data-testid="quote-alert-error"', false);
        $response->assertSee('WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.');
    }
}
