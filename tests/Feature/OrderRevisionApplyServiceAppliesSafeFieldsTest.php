<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrderRevision;
use App\Models\OrderRevisionChange;
use App\Services\OrderRevisionApplyService;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionApplyServiceAppliesSafeFieldsTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_applies_safe_quantity_price_print_note_and_delivery_changes(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft([
            'with_finance' => false,
        ]);
        $this->mutateRevisionQuantity($draft, 135);
        $this->mutateRevisionPrice($draft, 42);
        $this->mutateRevisionPrintNote($draft, 'Yeni baskı notu');
        $this->mutateRevisionDeliveryType($draft, 'Ofis Teslim');

        $comparison = app(OrderRevisionComparisonService::class)->build($draft->fresh(['items.prints', 'sourceOrder']));
        $revision = app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $draft->fresh(), $comparison, $this->adminUser);

        $applied = app(OrderRevisionApplyService::class)->apply($revision, $this->adminUser);

        $sourceOrder->refresh()->load('items.prints');
        $item = $sourceOrder->items->firstOrFail();
        $print = $item->prints->firstOrFail();

        $this->assertSame(OrderRevision::STATUS_PARTIALLY_APPLIED, $applied->status);
        $this->assertNotNull($applied->applied_at);
        $this->assertSame('135.0000', (string) $item->quantity);
        $this->assertSame('42.0000', (string) $item->unit_price);
        $this->assertSame('5670.0000', (string) $item->line_total);
        $this->assertSame('Yeni baskı notu', $print->note);
        $this->assertSame('Ofis Teslim', $sourceOrder->delivery_type);
        $this->assertSame(OrderRevisionChange::APPLY_STATUS_APPLIED, $applied->changes->firstWhere('field_key', 'adet_degisimi')?->apply_status);
        $this->assertSame(OrderRevisionChange::APPLY_STATUS_APPLIED, $applied->changes->firstWhere('field_key', 'baski_notu')?->apply_status);
        $this->assertSame(OrderRevisionChange::APPLY_STATUS_APPLIED, $applied->changes->firstWhere('field_key', 'fiyat')?->apply_status);
        $this->assertSame(OrderRevisionChange::APPLY_STATUS_APPLIED, $applied->changes->firstWhere('field_key', 'teslim_bilgisi')?->apply_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order_revision_applied',
            'entity_type' => OrderRevision::class,
            'entity_id' => $applied->id,
        ]);
        $this->assertGreaterThan(0, (float) $sourceOrder->fresh()->grand_total);
    }
}
