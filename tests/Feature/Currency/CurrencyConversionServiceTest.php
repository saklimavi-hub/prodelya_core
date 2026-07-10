<?php

namespace Tests\Feature\Currency;

use App\DTOs\Currency\ManualExchangeRateOverrideData;
use App\Exceptions\Currency\ManualExchangeRateReasonRequiredException;
use App\Models\ExchangeRate;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\CurrencyMath;
use App\Services\Currency\ExchangeRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_usd_to_try_conversion_works(): void
    {
        $this->seedRates();
        $service = $this->service();

        $result = $service->convert('100', 'USD', 'TRY', '2026-07-10');

        $this->assertSame('4325', $result->targetAmount);
    }

    public function test_try_to_usd_conversion_works(): void
    {
        $this->seedRates();
        $service = $this->service();

        $result = $service->convert('4325', 'TRY', 'USD', '2026-07-10');

        $this->assertSame('100', $result->targetAmount);
    }

    public function test_usd_to_eur_conversion_works(): void
    {
        $this->seedRates();
        $service = $this->service();

        $result = $service->convert('100', 'USD', 'EUR', '2026-07-10');

        $this->assertSame('93.51', $result->targetAmount);
        $this->assertCount(2, $result->legs);
    }

    public function test_zero_amount_is_supported(): void
    {
        $this->seedRates();

        $result = $this->service()->convert('0', 'USD', 'TRY', '2026-07-10');

        $this->assertSame('0', $result->targetAmount);
    }

    public function test_negative_amount_is_supported(): void
    {
        $this->seedRates();

        $result = $this->service()->convert('-10', 'USD', 'TRY', '2026-07-10');

        $this->assertSame('-432.5', $result->targetAmount);
    }

    public function test_manual_override_is_supported(): void
    {
        $override = new ManualExchangeRateOverrideData(rate: '40', reason: 'Test amaçlı', overriddenBy: 1, overriddenAt: '2026-07-10T10:00:00+03:00');

        $result = $this->service()->convert('10', 'USD', 'TRY', '2026-07-10', manualOverride: $override);

        $this->assertTrue($result->isManual);
        $this->assertSame('400', $result->targetAmount);
    }

    public function test_manual_override_requires_reason(): void
    {
        $this->expectException(ManualExchangeRateReasonRequiredException::class);

        new ManualExchangeRateOverrideData(rate: '40', reason: '');
    }

    private function service(): CurrencyConversionService
    {
        $math = CurrencyMath::fromConfig();

        return new CurrencyConversionService(
            new CurrencyCodeNormalizer(),
            new ExchangeRateResolver(new CurrencyCodeNormalizer(), $math),
            $math,
        );
    }

    private function seedRates(): void
    {
        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'rate' => '43.25000000',
            'payload_hash' => 'usd',
        ]);

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => 'EUR',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'rate' => '46.25000000',
            'payload_hash' => 'eur',
        ]);
    }
}
