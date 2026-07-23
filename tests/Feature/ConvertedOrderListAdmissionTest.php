<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class ConvertedOrderListAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_converted_order_enters_open_orders_and_not_completed_orders_with_latest_first_ordering(): void
    {
        $olderOrder = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-ORDER-OLD']));
        $latestOrder = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-ORDER-NEW']));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.orders.index', ['status' => 'open']));

        $response->assertOk();
        $response->assertSeeInOrder([$latestOrder->document_number, $olderOrder->document_number]);

        $completedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.orders.index', ['status' => 'completed']));

        $completedResponse->assertDontSee($latestOrder->document_number);
    }
}
