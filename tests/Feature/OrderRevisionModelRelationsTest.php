<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionModelRelationsTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_order_revision_relations_work(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);

        $revision = OrderRevision::query()->with([
            'tenantAccount',
            'order',
            'revisionQuote',
            'requestedBy',
            'changes',
        ])->findOrFail($revision->id);

        $this->assertSame($this->tenant->id, $revision->tenantAccount->id);
        $this->assertSame($sourceOrder->id, $revision->order->id);
        $this->assertSame($draft->id, $revision->revisionQuote->id);
        $this->assertSame($this->adminUser->id, $revision->requestedBy->id);
        $this->assertTrue($sourceOrder->revisions->contains(fn (OrderRevision $item) => $item->id === $revision->id));
        $this->assertSame($revision->id, $sourceOrder->latestRevision->id);
        $this->assertSame($revision->id, $draft->orderRevision->id);
        $this->assertSame($revision->id, $draft->revisionRecord->id);
    }
}
