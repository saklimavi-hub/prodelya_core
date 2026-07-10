<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailFlowSummaryInsideProductTabTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_flow_summary_is_shown_before_tabs_and_only_once(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'Akış Özeti'));
        $this->assertStringContainsString('pd-quote-detail__flow-summary', $html);
        $response->assertSee('data-quote-panel="items"', false);
        $response->assertSee('Teklif Durumu ve Sıradaki Karar');
        $this->assertTrue(
            strpos($html, 'Akış Özeti') < strpos($html, 'quote-tabs'),
            'Akış Özeti sekmelerden önce görünmelidir.'
        );
    }
}
