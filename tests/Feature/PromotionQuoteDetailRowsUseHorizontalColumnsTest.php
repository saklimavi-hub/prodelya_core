<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailRowsUseHorizontalColumnsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_rows_render_with_horizontal_column_headers_and_structure(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Kalem');
        $response->assertSee('Adet');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Toplam');
        $response->assertSee('promotion-quote-line-header', false);
        $response->assertSee('promotion-quote-line-cell-value', false);
    }
}
