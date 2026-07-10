<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailProductRowsShowQuantityUnitTotalTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_row_shows_name_quantity_unit_price_and_total(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Kompakt Görünüm Test Ürünü');
        $response->assertSee('QD-001');
        $response->assertSee('1.000 Adet');
        $response->assertSee('4,73 TL');
        $response->assertSee('4.730,00 TL');
    }
}
