<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailResponsiveStructureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_layout_has_expected_structure_classes(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('quote-detail-compact', false);
        $response->assertSee('quote-strip', false);
        $response->assertSee('quote-priority-block', false);
        $response->assertSee('quote-item-row', false);
        $response->assertSee('quote-send-card', false);
        $response->assertSee('quote-right-summary', false);
        $response->assertSee('quote-bottom-bar', false);
        $response->assertSee('quoteSendModal', false);
    }
}
