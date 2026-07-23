<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class ConvertedQuoteListVisibilityTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_converted_quote_is_hidden_from_active_view_and_visible_in_converted_view_with_exact_order_link(): void
    {
        $activeQuote = $this->createQuoteViaHttp(['document_number' => 'TK-B1-LIST-ACTIVE']);
        $convertedQuote = $this->createQuoteViaHttp(['document_number' => 'TK-B1-LIST-CONVERTED']);
        $order = $this->convertQuote($convertedQuote);

        $activeResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.promotion-quotes.index', ['view' => 'active']));

        $activeResponse->assertOk();
        $activeResponse->assertSee($activeQuote->document_number);
        $activeResponse->assertDontSee($convertedQuote->document_number);

        $convertedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.promotion-quotes.index', ['view' => 'converted']));

        $convertedResponse->assertOk();
        $convertedResponse->assertSee($convertedQuote->document_number);
        $convertedResponse->assertSee($order->document_number);
        $convertedResponse->assertSee(route('admin.orders.show', $order), false);
    }
}
