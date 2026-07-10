<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailRightSummaryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_right_summary_panel_contains_expected_cards(): void
    {
        $response = $this->showQuote($this->createSentPromotionQuote());

        $response->assertOk();
        $response->assertSee('Teklif Özeti');
        $response->assertSee('Hızlı Aksiyon');
        $response->assertSee('Karar Bilgisi');
        $response->assertSee('Son Kayıtlar');
    }
}
