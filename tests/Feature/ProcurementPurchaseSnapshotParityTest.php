<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseSnapshotParityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_snapshot_keeps_source_currency_rate_gross_calculated_final_and_quantity_together(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-SNAPSHOT-PARITY',
            'PZ-CH30SY',
            'PZ CH30SY Pozitron',
            '12.5000',
            'USD',
            '47.00980000',
            10
        );

        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '10',
            'purchase_list_price' => '587.62',
            'discount_rate' => '55',
            'use_calculated_price' => true,
        ]], $this->adminUser);

        $item->refresh();
        $snapshot = $item->purchase_price_snapshot;

        $this->assertSame(12.5, round((float) data_get($snapshot, 'purchase_source_amount'), 1));
        $this->assertSame('USD', data_get($snapshot, 'purchase_source_currency'));
        $this->assertSame(47.0098, round((float) data_get($snapshot, 'purchase_fx_rate'), 4));
        $this->assertSame(587.6225, round((float) data_get($snapshot, 'purchase_list_price_try'), 4));
        $this->assertSame(55.0, (float) data_get($snapshot, 'purchase_discount_rate'));
        $this->assertSame(264.430125, round((float) data_get($snapshot, 'purchase_calculated_unit_price'), 6));
        $this->assertFalse((bool) data_get($snapshot, 'purchase_manual_override'));
        $this->assertNull(data_get($snapshot, 'purchase_manual_unit_price'));
        $this->assertSame(264.430125, round((float) data_get($snapshot, 'purchase_final_unit_price'), 6));
        $this->assertSame(10.0, (float) data_get($snapshot, 'quantity_basis'));
        $this->assertSame(2644.30, round((float) data_get($snapshot, 'purchase_total'), 2));
    }
}
