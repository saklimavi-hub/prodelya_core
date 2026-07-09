<?php

namespace Tests\Feature;

use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServicePermissionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_rejects_unauthorized_user(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);

        $this->expectException(InvalidArgumentException::class);
        app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->unauthorizedUser);
    }
}
