<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class ActiveOrdersHideCompletedOrdersTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_completed_orders_are_hidden_from_active_orders_list(): void
    {
        $activeOrder = $this->createOrder(['document_number' => 'SP-ACT-001']);
        $completedOrder = $this->createOrder(['document_number' => 'SP-CMP-001'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $response->assertOk();
        $response->assertSee($activeOrder->document_number);
        $response->assertDontSee($completedOrder->document_number);
        $response->assertSee('Aktif Siparişler');
    }
}
