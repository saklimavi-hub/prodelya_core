<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailWhatsappUiRuleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_whatsapp_ui_rules_are_explained(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Kanalı seçin. WhatsApp link için e-posta şartı yoktur.');
        $response->assertSee('Örnek: 05** *** ** ** veya 0212 *** ** **');
        $response->assertDontSee('0212 sabit WhatsApp numarası desteklenir');
        $response->assertDontSee('Müşteri e-posta adresi olmadığı için teklif maili gönderilemedi.');
    }
}
