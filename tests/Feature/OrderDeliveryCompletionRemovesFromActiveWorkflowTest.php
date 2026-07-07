<?php

namespace Tests\Feature;

use App\Services\OrderDeliveryPlanningService;
use App\Services\OrderListSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryCompletionRemovesFromActiveWorkflowTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_delivered_order_moves_out_of_active_operation_but_finance_tracking_remains(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '2']);
        $orderItem = $order->items()->firstOrFail();
        $service = app(OrderDeliveryPlanningService::class);

        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Koli 1',
                'package_type' => 'box',
                'items' => [['order_item_id' => $orderItem->id, 'quantity' => 2]],
            ],
        ], $this->orderShowAdminUser);

        $service->completeDelivery($order->fresh(['deliveries', 'deliveryPackages']), [], $this->orderShowAdminUser);

        $row = app(OrderListSummaryService::class)->buildRow($order->fresh([
            'customer',
            'sourceQuote',
            'workForms',
            'procurements',
            'printProductions',
            'deliveries',
            'payments',
        ]), true);

        $this->assertTrue($row['is_completed']);
        $this->assertFalse($row['has_open_operation']);
        $this->assertTrue($row['is_payment_pending']);
        $this->assertSame('Tahsilat bekliyor', $row['next_action_label']);
    }
}
