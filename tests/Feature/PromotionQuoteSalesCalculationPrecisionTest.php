<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSalesCalculationPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_sales_presentation_payload_keeps_original_currency_rate_and_price_contract(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $service = app(QuoteCurrencyPricingService::class);

        $pricing = $service->buildItemPricing(
            $tenant,
            'TRY',
            [
                'source_price' => 7.75,
                'source_currency' => 'USD',
                'base_price' => 364.22,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
            ],
            [
                'calculated_unit_price' => 200.321,
                'unit_price' => 200.321,
                'manual_unit_price' => false,
                'discount_rate' => 45,
            ],
            '2026-07-14'
        );

        $presentation = $service->buildSalesPresentationPayload(array_merge($pricing, [
            'discount_rate' => 45,
        ]));

        $this->assertSame(7.75, $presentation['sales_source_amount']);
        $this->assertSame('USD', $presentation['sales_source_currency']);
        $this->assertSame(364.22, $presentation['sales_list_try']);
        $this->assertSame(45.0, $presentation['sales_discount_percent']);
        $this->assertSame(200.32, $presentation['sales_calculated_unit_try']);
        $this->assertSame(200.32, $presentation['sales_final_unit_try']);
        $this->assertSame('TRY', $presentation['sales_document_currency']);
        $this->assertContains($presentation['conversion_status'], ['converted', 'not_required', 'stale_rate']);
    }
}
