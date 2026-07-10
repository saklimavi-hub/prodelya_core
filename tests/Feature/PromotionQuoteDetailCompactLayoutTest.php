<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailCompactLayoutTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_page_shows_compact_layout_sections(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Promosyon Teklif Detayı');
        $response->assertSee('Ürün &amp; Baskı', false);
        $response->assertSee('Teklif Özeti');
        $response->assertSee('Hızlı Aksiyon');
        $response->assertSee('Karar Bilgisi');
    }
}
