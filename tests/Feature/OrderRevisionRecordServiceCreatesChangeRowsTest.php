<?php

namespace Tests\Feature;

use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceCreatesChangeRowsTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_creates_change_rows_from_comparison_payload(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionQuantity($draft, 135);
        $comparison = app(OrderRevisionComparisonService::class)->build($draft->fresh(['items.prints', 'sourceOrder']));

        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft->fresh(), $comparison, $this->adminUser);

        $this->assertGreaterThan(0, $revision->changes()->count());
        $this->assertDatabaseHas('order_revision_changes', [
            'order_revision_id' => $revision->id,
            'change_group' => 'decision_matrix',
            'field_key' => 'adet_degisimi',
        ]);
        $this->assertDatabaseHas('order_revision_changes', [
            'order_revision_id' => $revision->id,
            'change_group' => 'item_line',
            'field_key' => 'item_line_1',
        ]);
    }
}
