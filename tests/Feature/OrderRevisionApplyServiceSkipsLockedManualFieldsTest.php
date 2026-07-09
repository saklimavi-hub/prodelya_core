<?php

namespace Tests\Feature;

use App\Models\OrderRevisionChange;
use App\Services\OrderRevisionApplyService;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionApplyServiceSkipsLockedManualFieldsTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_does_not_apply_locked_or_manual_fields(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionProduct($draft, 'REV-LOCK', 'Kilitli Ürün');
        $this->mutateRevisionPrintType($draft, 'Tampon Baskı');
        $this->setProcurementStatus($sourceOrder, \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED);
        $this->setProductionStatus($sourceOrder, \App\Models\OrderItemPrintProduction::STATUS_INTERNAL);

        $comparison = app(OrderRevisionComparisonService::class)->build($draft->fresh(['items.prints', 'sourceOrder']));
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder->fresh(), $draft->fresh(), $comparison, $this->adminUser);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(OrderRevisionApplyService::NO_APPLICABLE_MESSAGE);

        try {
            app(OrderRevisionApplyService::class)->apply($revision, $this->adminUser);
        } finally {
            $revision->refresh()->load('changes');
            $this->assertSame(OrderRevisionChange::APPLY_STATUS_BLOCKED, $revision->changes->firstWhere('field_key', 'urun_degisimi')?->apply_status);
            $this->assertSame(OrderRevisionChange::APPLY_STATUS_BLOCKED, $revision->changes->firstWhere('field_key', 'baski_tipi')?->apply_status);
            $this->assertSame('REV-001', $sourceOrder->fresh()->items()->firstOrFail()->product_code);
        }
    }
}
