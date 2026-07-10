<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class ActiveQuotesHideConvertedOrdersTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_converted_quotes_are_hidden_from_active_quotes_list(): void
    {
        $activeQuote = $this->createQuote(['document_number' => 'TK-ACT-001']);
        [$convertedQuote] = $this->createConvertedQuote(['document_number' => 'TK-CONV-001']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee($activeQuote->document_number);
        $response->assertDontSee($convertedQuote->document_number);
        $response->assertSee('Açık Teklifler');
    }
}
