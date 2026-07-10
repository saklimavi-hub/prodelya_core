<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Services\OrderListSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class OrderCompletedDecisionSafetyTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_completed_decision_requires_real_delivery_completion_when_delivery_records_exist(): void
    {
        $completed = $this->createOrder([
            'document_number' => 'SP-COMP-SAFE-001',
            'status' => 'completed',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $falsePositive = $this->createOrder([
            'document_number' => 'SP-COMP-SAFE-002',
            'status' => 'completed',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        $rows = app(OrderListSummaryService::class)->buildRows(
            Order::query()
                ->with(['customer', 'sourceQuote', 'workForms', 'procurements', 'printProductions', 'deliveries', 'payments'])
                ->whereIn('id', [$completed->id, $falsePositive->id])
                ->orderBy('id')
                ->get(),
            true
        );

        $completedRows = app(OrderListSummaryService::class)->filterRows($rows, 'completed', true);
        $openRows = app(OrderListSummaryService::class)->filterRows($rows, 'open', true);

        $this->assertTrue($completedRows->contains(fn (array $row) => $row['order']->id === $completed->id));
        $this->assertFalse($completedRows->contains(fn (array $row) => $row['order']->id === $falsePositive->id));
        $this->assertTrue($openRows->contains(fn (array $row) => $row['order']->id === $falsePositive->id));
    }
}
