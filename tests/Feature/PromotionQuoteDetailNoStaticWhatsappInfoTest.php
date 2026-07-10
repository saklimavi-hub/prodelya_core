<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailNoStaticWhatsappInfoTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_static_whatsapp_info_box_is_not_visible(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertDontSee('WhatsApp için e-posta şartı yoktur. 0212 sabit WhatsApp numarası desteklenir. Gönderim kaydı ve public onay akışı mevcut hotfix davranışlarıyla korunur.');
        $response->assertDontSee('Yanlış e-posta hata mesajı bu statik arayüzde gösterilmez; yalnız gerçek gönderimde koşul olarak uygulanır.');
    }
}
