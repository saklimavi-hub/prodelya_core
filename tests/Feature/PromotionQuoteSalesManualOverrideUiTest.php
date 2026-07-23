<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSalesManualOverrideUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_sales_presentation_payload_marks_manual_override_and_keeps_final_unit_separate(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $service = app(QuoteCurrencyPricingService::class);

        $pricing = $service->buildItemPricing(
            $tenant,
            'USD',
            [
                'source_price' => 4.0,
                'source_currency' => 'USD',
                'base_price' => 140.0,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
            ],
            [
                'unit_price' => 5.0,
                'calculated_unit_price' => 4.0,
                'manual_unit_price' => true,
                'discount_rate' => 0,
            ],
            '2026-07-10'
        );

        $presentation = $service->buildSalesPresentationPayload($pricing);

        $this->assertTrue($presentation['sales_manual_override']);
        $this->assertSame(4.0, $presentation['sales_calculated_unit_try']);
        $this->assertSame(5.0, $presentation['sales_final_unit_try']);
        $this->assertSame('USD', $presentation['sales_document_currency']);
    }
}
