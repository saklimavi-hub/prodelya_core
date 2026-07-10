<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailTabsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_tab_titles_are_visible(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Ürün &amp; Baskı', false);
        $response->assertSee('Gönderim');
        $response->assertSee('Müşteri Onayı');
        $response->assertSee('Geçmiş');
        $response->assertSee('Notlar');
    }
}
