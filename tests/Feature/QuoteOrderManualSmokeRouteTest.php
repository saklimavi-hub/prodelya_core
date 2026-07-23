<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteOrderManualSmokeRouteTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quote_and_order_list_routes_for_manual_smoke_views_render_successfully(): void
    {
        $this->createQuote(['document_number' => 'TK-SMOKE-ACTIVE']);
        $this->createConvertedQuote(
            ['document_number' => 'TK-SMOKE-CONVERTED'],
            ['document_number' => 'SP-SMOKE-CONVERTED']
        );
        $this->createQuote([
            'document_number' => 'TK-SMOKE-ARCHIVED',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_REJECTED,
        ]);

        $this->createOrder(['document_number' => 'SP-SMOKE-ACTIVE']);
        $this->createOrder(['document_number' => 'SP-SMOKE-COMPLETED'], [
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
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get($url)
                ->assertOk();
        }
    }
}
