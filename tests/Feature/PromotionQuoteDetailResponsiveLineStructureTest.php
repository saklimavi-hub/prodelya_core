<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailResponsiveLineStructureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_layout_has_expected_product_print_line_classes(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('promotion-quote-lines', false);
        $response->assertSee('promotion-quote-lines-head', false);
        $response->assertSee('promotion-quote-line-header', false);
        $response->assertSee('promotion-quote-line promotion-quote-line-product', false);
        $response->assertSee('promotion-quote-line promotion-quote-line-print', false);
        $response->assertSee('promotion-quote-line-index', false);
        $response->assertSee('promotion-quote-line-main', false);
        $response->assertSee('promotion-quote-line-cell', false);
        $response->assertSee('promotion-quote-lines-total-band', false);
    }
}
