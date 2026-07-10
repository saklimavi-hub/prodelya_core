<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class OrderListTabCountersTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_order_tabs_show_consistent_counts_for_active_completed_and_operational_views(): void
    {
        $this->createOrder(['document_number' => 'SP-COUNT-OPEN-001']);
        $this->createOrder(['document_number' => 'SP-COUNT-INOP-001'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);
        $this->createOrder(['document_number' => 'SP-COUNT-COMPLETE-001'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $response->assertOk();
        $response->assertSee('Aktif Siparişler <em>2</em>', false);
        $response->assertSee('Tamamlanan Siparişler <em>1</em>', false);
        $response->assertSee('Tümü <em>3</em>', false);
        $response->assertSee('Operasyonda <em>2</em>', false);
        $response->assertSee('Teslimat Bekleyen <em>0</em>', false);
    }
}
