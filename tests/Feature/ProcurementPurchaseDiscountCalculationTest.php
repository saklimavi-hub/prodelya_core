<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseDiscountCalculationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_usd_discount_is_applied_to_gross_try_for_qty_ten(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-DISC-USD-A',
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

        $this->assertSame(587.6225, round((float) $item->purchase_list_price_try, 4));
        $this->assertSame(264.430125, round((float) $item->purchase_calculated_unit_price, 6));
        $this->assertSame(264.43, round((float) $item->purchase_unit_price, 2));
        $this->assertSame(2644.30, round((float) $item->purchase_total, 2));
    }

    public function test_usd_discount_is_applied_to_second_exact_variant(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-DISC-USD-B',
            'PZ-CH60SY',
            'PZ CH60SY Pozitron',
            '3.5000',
            'USD',
            '47.00980000',
            1
        );

        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '1',
            'purchase_list_price' => '164.53',
            'discount_rate' => '55',
            'use_calculated_price' => true,
        ]], $this->adminUser);

        $item->refresh();

        $this->assertSame(164.5343, round((float) $item->purchase_list_price_try, 4));
        $this->assertSame(74.040435, round((float) $item->purchase_calculated_unit_price, 6));
        $this->assertSame(74.04, round((float) $item->purchase_unit_price, 2));
        $this->assertSame(74.04, round((float) $item->purchase_total, 2));
    }

    public function test_try_discount_uses_identity_rate_and_discounted_purchase_unit(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-DISC-TRY',
            'AK-1020-KIRMIZI',
            'Akdeniz TRY Ürün',
            '30.5000',
            'TRY',
            '1',
            1
        );

        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '1',
            'purchase_list_price' => '30.50',
            'discount_rate' => '55',
            'use_calculated_price' => true,
        ]], $this->adminUser);

        $item->refresh();

        $this->assertSame(1.0, (float) $item->purchase_fx_rate);
        $this->assertSame(30.5, round((float) $item->purchase_list_price_try, 2));
        $this->assertSame(13.725, round((float) $item->purchase_calculated_unit_price, 3));
        $this->assertSame(13.73, round((float) $item->purchase_unit_price, 2));
    }
}
