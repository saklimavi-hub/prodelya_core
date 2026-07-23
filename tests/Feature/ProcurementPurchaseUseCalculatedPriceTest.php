<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseUseCalculatedPriceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_use_calculated_price_clears_manual_override_for_draft_request(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-USE-CALC-DRAFT',
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

        app(SupplierProcurementRequestService::class)->updateRequestItems($request->fresh('items.procurement'), [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '10',
            'purchase_list_price' => '587.62',
            'discount_rate' => '55',
            'purchase_unit_price' => '999.99',
            'use_calculated_price' => true,
        ]], $this->adminUser);

        $item->refresh();

        $this->assertFalse((bool) $item->purchase_manual_override);
        $this->assertNull($item->purchase_manual_unit_price);
        $this->assertSame(264.43, round((float) $item->purchase_unit_price, 2));
        $this->assertSame(2644.30, round((float) $item->purchase_total, 2));
    }
}
