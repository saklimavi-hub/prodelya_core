<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailPrintRowsShowQuantityUnitTotalTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_print_row_shows_name_quantity_unit_price_and_total(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('UV Baskı');
        $response->assertSee('Tek taraf');
        $response->assertSee('Logo sağ üst köşede kullanılacak.');
        $response->assertSee('1.000 Adet');
        $response->assertSee('5,00 TL');
        $response->assertSee('5.000,00 TL');
    }
}
