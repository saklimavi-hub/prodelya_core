<?php

namespace Tests\Feature\Currency;

use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Models\ExchangeRate;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\CurrencyMath;
use App\Services\Currency\ExchangeRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_currency_returns_identity_rate(): void
    {
        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('TRY', 'TRY', '2026-07-10');

        $this->assertSame('1.00000000', $resolved->rate);
    }

    public function test_it_resolves_exact_date(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-10', '43.25000000');

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('USD', 'TRY', '2026-07-10');

        $this->assertSame('43.25', $resolved->rate);
        $this->assertFalse($resolved->isFallbackDate);
    }

    public function test_it_uses_previous_valid_date(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-10', '43.25000000');

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('USD', 'TRY', '2026-07-12');

        $this->assertTrue($resolved->isFallbackDate);
        $this->assertSame('2026-07-10', $resolved->resolvedRateDate);
    }

    public function test_it_resolves_reverse_pair(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-10', '40.00000000');

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('TRY', 'USD', '2026-07-10');

        $this->assertSame('0.025', $resolved->rate);
    }

    public function test_it_resolves_cross_currency_via_try(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-10', '40.00000000');
        $this->seedRate('EUR', 'TRY', '2026-07-10', '50.00000000');

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('USD', 'EUR', '2026-07-10');

        $this->assertSame('0.8', $resolved->rate);
        $this->assertCount(2, $resolved->legs);
    }

    public function test_it_marks_stale_rates(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-07', '43.25000000');

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolved = $resolver->resolve('USD', 'TRY', '2026-07-10');

        $this->assertTrue($resolved->isStale);
    }

    public function test_it_throws_when_lookback_is_too_old(): void
    {
        $this->seedRate('USD', 'TRY', '2026-07-01', '43.25000000');

        $this->expectException(ExchangeRateNotFoundException::class);

        $resolver = new ExchangeRateResolver(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $resolver->resolve('USD', 'TRY', '2026-07-10');
    }

    private function seedRate(string $source, string $target, string $date, string $rate): void
    {
        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => $source,
            'target_currency' => $target,
            'rate_date' => $date,
            'source_unit' => 1,
            'rate' => $rate,
            'payload_hash' => sha1($source . $target . $date . $rate),
        ]);
    }
}
