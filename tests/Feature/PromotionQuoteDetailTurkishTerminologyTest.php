<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailTurkishTerminologyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_turkish_terminology_is_clean(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Promosyon Teklif Detayı');
        $response->assertSee('Ürün &amp; Baskı', false);
        $response->assertSee('Geçmiş');
        $response->assertSee('Geçerlilik');
        foreach (['Musteri', 'Siparis', 'Gonderim', 'Gecmis', 'Urun', 'Baski'] as $brokenWord) {
            $response->assertDontSee($brokenWord);
        }
    }
}
