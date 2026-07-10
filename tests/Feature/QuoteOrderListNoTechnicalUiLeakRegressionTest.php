<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteOrderListNoTechnicalUiLeakRegressionTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quote_and_order_list_views_do_not_leak_technical_snapshot_fields_across_tabs(): void
    {
        $this->createQuote(['document_number' => 'TK-LEAK-ACTIVE']);
        $this->createConvertedQuote(
            ['document_number' => 'TK-LEAK-CONVERTED'],
            ['document_number' => 'SP-LEAK-CONVERTED']
        );
        $this->createQuote([
            'document_number' => 'TK-LEAK-ARCHIVED',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_EXPIRED,
        ]);
        $this->createOrder(['document_number' => 'SP-LEAK-ACTIVE']);
        $this->createOrder(['document_number' => 'SP-LEAK-COMPLETED'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        foreach ([
            route('admin.promotion-quotes.index'),
            route('admin.promotion-quotes.index', ['filter' => 'converted']),
            route('admin.promotion-quotes.index', ['filter' => 'archived']),
            route('admin.orders.index'),
            route('admin.orders.index', ['filter' => 'completed']),
            route('admin.orders.index', ['filter' => 'all']),
        ] as $url) {
            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get($url);

            $response->assertOk();
            $response->assertDontSee('group_code', false);
            $response->assertDontSee('file_path', false);
            $response->assertDontSee('transaction_id', false);
            $response->assertDontSee('price_snapshot', false);
            $response->assertDontSee('projection', false);
            $response->assertDontSee('raw', false);
        }
    }
}
