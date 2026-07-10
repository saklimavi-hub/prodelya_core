<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailProductRowColumnAlignmentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_row_uses_same_grid_for_quantity_unit_price_and_total(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('promotion-quote-line promotion-quote-line-product', false);
        $response->assertSee('promotion-quote-line-cell promotion-quote-line-cell-qty', false);
        $response->assertSee('promotion-quote-line-cell promotion-quote-line-cell-unit', false);
        $response->assertSee('promotion-quote-line-cell promotion-quote-line-cell-total promotion-quote-line-total', false);
        $response->assertSee('1.000 Adet');
        $response->assertSee('4,73 TL');
        $response->assertSee('4.730,00 TL');
    }
}
