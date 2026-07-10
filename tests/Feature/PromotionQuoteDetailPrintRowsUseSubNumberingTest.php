<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailPrintRowsUseSubNumberingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_and_print_rows_use_sub_numbering(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('>1<', false);
        $response->assertSee('>1a<', false);
        $response->assertSee('>1b<', false);
        $response->assertSee('>2<', false);
        $response->assertSee('>2a<', false);
    }
}
