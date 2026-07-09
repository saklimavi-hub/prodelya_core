<?php

namespace Tests\Feature;

use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServicePreventsDuplicateRevisionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_does_not_create_duplicate_revision_record_for_same_quote(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);
        $service = app(OrderRevisionRecordService::class);

        $first = $service->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);
        $second = $service->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $sourceOrder->revisions()->count());
    }
}
