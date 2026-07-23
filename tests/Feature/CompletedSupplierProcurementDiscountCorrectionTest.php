<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class CompletedSupplierProcurementDiscountCorrectionTest extends TestCase
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

    public function test_completed_request_use_calculated_price_clears_manual_override_without_unlocking_quantity(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-COMP-DISC',
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
            'purchase_manual_unit_price' => 250.00,
            'purchase_manual_override' => true,
            'purchase_unit_price' => 250.00,
            'purchase_total' => 2500.00,
        ])->save();

        $request = $this->markRequestCompleted($request);
        $item = $request->items()->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $request), [
                'note' => 'Completed use calculated',
                'items' => [[
                    'id' => $item->id,
                    'requested_quantity' => '10.00',
                    'purchase_list_price' => '587.62',
                    'discount_rate' => '55',
                    'purchase_unit_price' => '250.00',
                    'use_calculated_price' => '1',
                    'note' => 'Completed row',
                ]],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $request));

        $item->refresh();

        $this->assertSame(10.0, (float) $item->requested_quantity);
        $this->assertSame(10.0, (float) $item->received_quantity);
        $this->assertSame(0.0, (float) $item->remaining_quantity);
        $this->assertFalse((bool) $item->purchase_manual_override);
        $this->assertNull($item->purchase_manual_unit_price);
        $this->assertSame(264.43, round((float) $item->purchase_unit_price, 2));
        $this->assertSame(2644.30, round((float) $item->purchase_total, 2));
    }
}
