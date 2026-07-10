<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailNoAlertWhenNoFlashTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_quote_alert_area_is_not_rendered_without_flash(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertDontSee('quote-alert', false);
        $response->assertDontSee('data-testid="quote-alert', false);
    }
}
