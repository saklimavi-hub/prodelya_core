<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteOrderListNoSensitiveLeakTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quote_and_order_lists_do_not_expose_sensitive_fields(): void
    {
        $this->createQuote(['document_number' => 'TK-SAFE-001']);
        $this->createOrder(['document_number' => 'SP-SAFE-001']);

        $quoteResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $orderResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        foreach ([
            'supplier_cost',
            'purchase_price',
            'profit',
            'group_code',
            'file_path',
            'transaction_id',
            'meta_json',
            'current_account_id',
            'price_snapshot',
        ] as $forbiddenText) {
            $quoteResponse->assertDontSee($forbiddenText, false);
            $orderResponse->assertDontSee($forbiddenText, false);
        }
    }
}
