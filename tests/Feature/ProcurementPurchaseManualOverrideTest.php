<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseManualOverrideTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_manual_override_preserves_final_unit_while_discount_changes_refresh_calculated_reference(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-MANUAL-OVERRIDE',
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
            'purchase_unit_price' => '250.00',
            'use_calculated_price' => false,
        ]], $this->adminUser);

        $item->refresh();
        $this->assertSame(264.430125, round((float) $item->purchase_calculated_unit_price, 6));
        $this->assertSame(250.0, (float) $item->purchase_manual_unit_price);
        $this->assertTrue((bool) $item->purchase_manual_override);
        $this->assertSame(250.0, (float) $item->purchase_unit_price);
        $this->assertSame(2500.0, (float) $item->purchase_total);

        app(SupplierProcurementRequestService::class)->updateRequestItems($request->fresh('items.procurement'), [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '10',
            'purchase_list_price' => '587.62',
            'discount_rate' => '60',
            'purchase_unit_price' => '250.00',
            'use_calculated_price' => false,
        ]], $this->adminUser);

        $item->refresh();
        $this->assertSame(235.049, round((float) $item->purchase_calculated_unit_price, 3));
        $this->assertSame(250.0, (float) $item->purchase_unit_price);
        $this->assertSame(2500.0, (float) $item->purchase_total);
    }
}
