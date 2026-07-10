<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteAndOrderSortingNewestFirstTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quotes_and_orders_are_sorted_newest_first(): void
    {
        $olderQuote = $this->createQuote(['document_number' => 'TK-SORT-OLD'], Carbon::parse('2026-07-01 10:00:00'));
        $newerQuote = $this->createQuote(['document_number' => 'TK-SORT-NEW'], Carbon::parse('2026-07-07 10:00:00'));
        $olderOrder = $this->createOrder(['document_number' => 'SP-SORT-OLD'], [], Carbon::parse('2026-07-01 11:00:00'));
        $newerOrder = $this->createOrder(['document_number' => 'SP-SORT-NEW'], [], Carbon::parse('2026-07-07 11:00:00'));

        $quoteResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $orderResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $quoteContent = $quoteResponse->getContent();
        $orderContent = $orderResponse->getContent();

        $this->assertTrue(strpos($quoteContent, $newerQuote->document_number) < strpos($quoteContent, $olderQuote->document_number));
        $this->assertTrue(strpos($orderContent, $newerOrder->document_number) < strpos($orderContent, $olderOrder->document_number));
    }
}
