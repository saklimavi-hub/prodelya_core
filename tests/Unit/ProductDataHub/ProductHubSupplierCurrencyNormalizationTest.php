<?php

namespace Tests\Unit\ProductDataHub;

use App\Models\ExchangeRate;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Services\ProductDataHub\ProductHubCurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubSupplierCurrencyNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_it_normalizes_aliases_and_tracks_origin_precedence(): void
    {
        $service = app(ProductHubCurrencyService::class);
        $source = new SupplierSource([
            'config' => ['profile_key' => 'AKDENIZ'],
        ]);

        $productContext = $service->buildRawCurrencyContext(
            $source,
            ['list_price' => 125.5],
            ['kur' => '$'],
            null,
            null,
            'product'
        );

        $this->assertSame('USD', $productContext['source_currency']);
        $this->assertSame('product_field', $productContext['currency_origin']);
        $this->assertSame('resolved', $productContext['currency_status']);
        $this->assertSame(125.5, $productContext['source_price']);

        $variantFallback = $service->buildRawCurrencyContext(
            $source,
            ['list_price' => 88.0],
            [],
            ['list_price' => 88.0],
            ['kur' => '€'],
            'variant'
        );

        $this->assertSame('EUR', $variantFallback['source_currency']);
        $this->assertSame('product_field', $variantFallback['currency_origin']);
    }

    public function test_it_builds_projection_snapshot_and_marks_missing_rate_safely(): void
    {
        $service = app(ProductHubCurrencyService::class);
        $tenant = TenantAccount::query()->create([
            'name' => 'Currency Tenant',
            'legal_name' => 'Currency Tenant A.S.',
            'slug' => 'currency-tenant',
            'panel_subdomain' => 'currency-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        ExchangeRate::query()->create([
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate' => '35.00000000',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
        ]);

        $converted = $service->buildProjectionCurrencySnapshot($tenant, [
            'source_price' => 4.0,
            'source_currency' => 'USD',
            'currency_status' => 'resolved',
            'currency_origin' => 'product_field',
        ], '2026-07-10');

        $this->assertTrue($converted['conversion_available']);
        $this->assertSame('converted', $converted['conversion_status']);
        $this->assertSame(140.0, $converted['base_price']);
        $this->assertSame('TRY', $converted['base_currency']);
        $this->assertSame(35.0, $converted['applied_rate']);

        $missingRate = $service->buildProjectionCurrencySnapshot($tenant, [
            'source_price' => 4.0,
            'source_currency' => 'EUR',
            'currency_status' => 'resolved',
            'currency_origin' => 'product_field',
        ], '2026-07-10');

        $this->assertFalse($missingRate['conversion_available']);
        $this->assertSame('missing_rate', $missingRate['conversion_status']);
        $this->assertNull($missingRate['base_price']);
    }
}
