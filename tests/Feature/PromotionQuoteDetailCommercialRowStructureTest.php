<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailCommercialRowStructureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_and_print_area_renders_as_commercial_row_structure(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('promotion-quote-lines', false);
        $response->assertSee('promotion-quote-lines-body', false);
        $response->assertSee('promotion-quote-line-main', false);
        $response->assertSee('promotion-quote-line-title', false);
        $response->assertSee('promotion-quote-line-meta', false);
        $response->assertSee('promotion-quote-line-note', false);
        $response->assertSee('promotion-quote-lines-total-band', false);
    }
}
