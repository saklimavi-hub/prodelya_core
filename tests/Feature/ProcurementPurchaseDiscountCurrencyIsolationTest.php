<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProcurementDiscountFixtures;
use Tests\TestCase;

class ProcurementPurchaseDiscountCurrencyIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsProcurementDiscountFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_discount_changes_do_not_mutate_original_source_currency_rate_or_gross_try(): void
    {
        [, , , , $request, $item] = $this->createDraftRequestForRawSource(
            'PROC-CURRENCY-ISO',
            'PZ-CH60SY',
            'PZ CH60SY Pozitron',
            '3.5000',
            'USD',
            '47.00980000',
            1
        );

        $before = [
            'amount' => (float) $item->purchase_source_amount,
            'currency' => $item->purchase_source_currency,
            'rate' => (float) $item->purchase_fx_rate,
            'list_try' => (float) $item->purchase_list_price_try,
        ];

        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => true,
            'requested_quantity' => '1',
            'purchase_list_price' => '164.53',
            'discount_rate' => '55',
            'use_calculated_price' => true,
        ]], $this->adminUser);

        $item->refresh();

        $this->assertSame($before['amount'], (float) $item->purchase_source_amount);
        $this->assertSame($before['currency'], $item->purchase_source_currency);
        $this->assertSame($before['rate'], (float) $item->purchase_fx_rate);
        $this->assertSame($before['list_try'], (float) $item->purchase_list_price_try);
        $this->assertSame(74.04, round((float) $item->purchase_unit_price, 2));
    }
}
