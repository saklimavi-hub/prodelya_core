<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailReferenceStructureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_reference_structure_blocks_are_present(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('quote-strip', false);
        $response->assertSee('quote-top-metrics', false);
        $response->assertSee('Akış Özeti');
        $response->assertSee('Ürün &amp; Baskı Kalemleri', false);
        $response->assertSee('quote-tabs', false);
        $response->assertSee('quote-right-summary', false);
        $response->assertSee('quote-bottom-bar', false);
        $response->assertSee('quote-send-modal-backdrop', false);
    }
}
