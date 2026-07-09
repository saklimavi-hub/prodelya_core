<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceCreatesRevisionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_creates_revision_record_for_revision_quote(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $comparison = app(OrderRevisionComparisonService::class)->build($draft);

        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft, $comparison, $this->adminUser);

        $this->assertDatabaseHas('order_revisions', [
            'id' => $revision->id,
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $sourceOrder->id,
            'revision_quote_id' => $draft->id,
            'revision_number' => 1,
        ]);
        $this->assertSame(OrderRevision::STATUS_DRAFT, $revision->status);
    }
}
