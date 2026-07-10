<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class ConvertedQuotesListTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_converted_quotes_are_listed_under_converted_view_only(): void
    {
        $activeQuote = $this->createQuote(['document_number' => 'TK-ACT-101']);
        [$convertedQuote, $order] = $this->createConvertedQuote(['document_number' => 'TK-CONV-101'], ['document_number' => 'SP-CONV-101']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index', ['view' => 'converted']));

        $response->assertOk();
        $response->assertSee($convertedQuote->document_number);
        $response->assertSee($order->document_number);
        $response->assertDontSee($activeQuote->document_number);
        $response->assertSee('Siparişe Dönüşenler');
    }
}
