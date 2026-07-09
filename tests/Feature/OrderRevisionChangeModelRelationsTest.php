<?php

namespace Tests\Feature;

use App\Models\OrderRevisionChange;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionChangeModelRelationsTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_order_revision_change_relations_work(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);

        $change = OrderRevisionChange::query()->with(['tenantAccount', 'revision', 'order'])->where('order_revision_id', $revision->id)->firstOrFail();

        $this->assertSame($this->tenant->id, $change->tenantAccount->id);
        $this->assertSame($revision->id, $change->revision->id);
        $this->assertSame($sourceOrder->id, $change->order->id);
        $this->assertNull($change->orderItem);
        $this->assertNull($change->orderItemPrint);
    }
}
