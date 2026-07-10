<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailNoDuplicateProductSummaryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_summary_is_not_rendered_twice(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'Ürün &amp; Baskı Kalemleri'));
        $this->assertSame(1, substr_count($html, 'Kompakt Görünüm Test Ürünü'));
    }
}
