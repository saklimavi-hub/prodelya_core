<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class CompletedOrdersListTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_completed_orders_are_visible_in_completed_view_only(): void
    {
        $activeOrder = $this->createOrder(['document_number' => 'SP-ACT-101']);
        $completedOrder = $this->createOrder(['document_number' => 'SP-CMP-101'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'completed']));

        $response->assertOk();
        $response->assertSee($completedOrder->document_number);
        $response->assertDontSee($activeOrder->document_number);
        $response->assertSee('Tamamlanan Siparişler');
    }
}
