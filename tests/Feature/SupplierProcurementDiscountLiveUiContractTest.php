<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class SupplierProcurementDiscountLiveUiContractTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_edit_screen_exposes_live_discount_recalculation_contract_for_usd_item(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-LIVE-UI',
            'PZ-CH30SY',
            'PZ CH30SY Pozitron',
            '12.5000',
            'USD',
            '47.00980000',
            10
        );

        $item->forceFill([
            'discount_rate' => 55,
            'purchase_calculated_unit_price' => 264.430125,
            'purchase_manual_unit_price' => null,
            'purchase_manual_override' => false,
            'purchase_unit_price' => 264.43,
            'purchase_total' => 2644.30,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request->fresh()));

        $response->assertOk();
        $response->assertSee('data-purchase-row', false);
        $response->assertSee('data-list-price-try="587.622500"', false);
        $response->assertSee('data-requested-quantity-input', false);
        $response->assertSee('data-discount-rate-input', false);
        $response->assertSee('data-purchase-total-cell', false);
        $response->assertSee('data-calculated-unit-value="264.430125"', false);
        $response->assertSee('Hesaplanan: <span data-calculated-display>264,43 TL</span>', false);
        $response->assertSee('İskonto değişse de final alış birim fiyatı manuel değerde korunur.');
        $response->assertSee('syncRow(row, true);', false);
    }
}
