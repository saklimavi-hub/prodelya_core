<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteUnavailablePriceReasonCodeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_shared_quote_payload_marks_unavailable_price_with_reason_code(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Unavailable Quote Payload Tenant',
            'legal_name' => 'Unavailable Quote Payload Tenant',
            'slug' => 'unavailable-quote-payload-' . uniqid(),
            'panel_subdomain' => 'unavailable-quote-payload-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $payload = app(QuoteCurrencyPricingService::class)->buildQuoteDisplayPayload(
            $tenant,
            'TRY',
            ['source_currency' => 'TRY'],
            ['manual_unit_price' => false],
            '2026-07-15'
        );

        $this->assertSame('unavailable', $payload['quote_price_status']);
        $this->assertNull($payload['quote_price_value']);
        $this->assertSame('canonical_quote_price_unavailable', $payload['quote_price_reason_code']);
        $this->assertSame('Ürün satış fiyatı teklif için hazırlanamadı.', $payload['quote_price_message']);
        $this->assertTrue((bool) $payload['quote_price_blocking']);
    }
}
