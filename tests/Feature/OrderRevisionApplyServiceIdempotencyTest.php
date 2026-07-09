<?php

namespace Tests\Feature;

use App\Services\OrderRevisionApplyService;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionApplyServiceIdempotencyTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_rejects_second_apply_attempt_with_turkish_message(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft(['with_finance' => false]);
        $this->mutateRevisionQuantity($draft, 140);

        $comparison = app(OrderRevisionComparisonService::class)->build($draft->fresh(['items.prints', 'sourceOrder']));
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft->fresh(), $comparison, $this->adminUser);

        app(OrderRevisionApplyService::class)->apply($revision, $this->adminUser);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(OrderRevisionApplyService::ALREADY_APPLIED_MESSAGE);

        app(OrderRevisionApplyService::class)->apply($revision->fresh(), $this->adminUser);
    }
}
