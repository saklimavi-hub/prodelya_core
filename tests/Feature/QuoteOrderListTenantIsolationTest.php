<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteOrderListTenantIsolationTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quote_and_order_lists_remain_tenant_scoped(): void
    {
        $localQuote = $this->createQuote(['document_number' => 'TK-LOCAL-001']);
        $localOrder = $this->createOrder(['document_number' => 'SP-LOCAL-001']);
        [, , $foreignQuote, $foreignOrder] = $this->createForeignTenantFixtures();

        $quoteResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $orderResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $quoteResponse->assertOk()
            ->assertSee($localQuote->document_number)
            ->assertDontSee($foreignQuote->document_number);

        $orderResponse->assertOk()
            ->assertSee($localOrder->document_number)
            ->assertDontSee($foreignOrder->document_number);
    }
}
