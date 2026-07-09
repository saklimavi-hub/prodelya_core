<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceNoOrderMutationTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_does_not_mutate_order_items_or_operations_or_finance(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);

        $before = [
            'items' => $sourceOrder->items()->count(),
            'prints' => $sourceOrder->items()->withCount('prints')->get()->sum('prints_count'),
            'procurements' => OrderItemProcurement::query()->where('order_id', $sourceOrder->id)->count(),
            'productions' => OrderItemPrintProduction::query()->where('order_id', $sourceOrder->id)->count(),
            'deliveries' => OrderItemWorkFormDelivery::query()->where('order_id', $sourceOrder->id)->count(),
            'payments' => OrderPayment::query()->where('order_id', $sourceOrder->id)->count(),
            'transactions' => CurrentAccountTransaction::query()->where('source_type', 'order')->where('source_id', $sourceOrder->id)->count(),
        ];

        app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);

        $this->assertSame($before['items'], $sourceOrder->fresh()->items()->count());
        $this->assertSame($before['procurements'], OrderItemProcurement::query()->where('order_id', $sourceOrder->id)->count());
        $this->assertSame($before['productions'], OrderItemPrintProduction::query()->where('order_id', $sourceOrder->id)->count());
        $this->assertSame($before['deliveries'], OrderItemWorkFormDelivery::query()->where('order_id', $sourceOrder->id)->count());
        $this->assertSame($before['payments'], OrderPayment::query()->where('order_id', $sourceOrder->id)->count());
        $this->assertSame($before['transactions'], CurrentAccountTransaction::query()->where('source_type', 'order')->where('source_id', $sourceOrder->id)->count());
    }
}
