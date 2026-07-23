<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseServerRecalculationTest extends TestCase
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

    public function test_backend_recomputes_canonical_purchase_unit_and_total_instead_of_trusting_client_totals(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-SERVER-RECALC',
            'PZ-CH30SY',
            'PZ CH30SY Pozitron',
            '12.5000',
            'USD',
            '47.00980000',
            10
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $request), [
                'submit_action' => 'draft',
                'items' => [[
                    'id' => $item->id,
                    'included' => true,
                    'requested_quantity' => '10',
                    'purchase_list_price' => '587.62',
                    'discount_rate' => '55',
                    'purchase_unit_price' => '999.99',
                    'purchase_calculated_unit_price' => '1.00',
                    'purchase_total' => '1.00',
                    'use_calculated_price' => '1',
                ]],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $request));

        $item->refresh();

        $this->assertSame(264.430125, round((float) $item->purchase_calculated_unit_price, 6));
        $this->assertSame(264.43, round((float) $item->purchase_unit_price, 2));
        $this->assertSame(2644.30, round((float) $item->purchase_total, 2));
    }
}
