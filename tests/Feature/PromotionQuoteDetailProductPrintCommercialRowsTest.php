<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailProductPrintCommercialRowsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_product_print_area_shows_commercial_column_headers(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Ürün &amp; Baskı Kalemleri', false);
        $response->assertSee('Kalem');
        $response->assertSee('Adet');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Toplam');
    }
}
