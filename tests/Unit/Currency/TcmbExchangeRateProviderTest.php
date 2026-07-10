<?php

namespace Tests\Unit\Currency;

use App\Exceptions\Currency\ExchangeRateProviderException;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\CurrencyMath;
use App\Services\Currency\TcmbExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TcmbExchangeRateProviderTest extends TestCase
{
    public function test_it_parses_usd_and_eur_rates(): void
    {
        Http::fake([
            '*' => Http::response($this->fixture('tcmb-20260710.xml'), 200),
        ]);

        $provider = new TcmbExchangeRateProvider(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $batch = $provider->fetchForDate(CarbonImmutable::parse('2026-07-10'), 'forex_selling');

        $this->assertSame('2026-07-10', $batch->resolvedRateDate);
        $this->assertCount(2, $batch->rates);
        $this->assertSame('USD', $batch->rates[0]->sourceCurrency);
        $this->assertSame('TRY', $batch->rates[0]->targetCurrency);
        $this->assertSame('43.25', $batch->rates[0]->rate);
    }

    public function test_it_normalizes_unit_100_rates(): void
    {
        Http::fake([
            '*' => Http::response($this->fixture('tcmb-20260709-unit100.xml'), 200),
        ]);

        $provider = new TcmbExchangeRateProvider(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $batch = $provider->fetchForDate(CarbonImmutable::parse('2026-07-09'), 'forex_selling');

        $this->assertSame('43.25', $batch->rates[0]->rate);
        $this->assertSame('46.25', $batch->rates[1]->rate);
    }

    public function test_it_throws_for_malformed_xml(): void
    {
        Http::fake([
            '*' => Http::response($this->fixture('tcmb-malformed.xml'), 200),
        ]);

        $this->expectException(ExchangeRateProviderException::class);

        $provider = new TcmbExchangeRateProvider(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $provider->fetchForDate(CarbonImmutable::parse('2026-07-10'), 'forex_selling');
    }

    public function test_it_throws_when_required_currency_is_missing(): void
    {
        Http::fake([
            '*' => Http::response($this->fixture('tcmb-missing-eur.xml'), 200),
        ]);

        $this->expectException(ExchangeRateProviderException::class);

        $provider = new TcmbExchangeRateProvider(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $provider->fetchForDate(CarbonImmutable::parse('2026-07-10'), 'forex_selling');
    }

    public function test_it_throws_for_unavailable_date(): void
    {
        Http::fake([
            '*' => Http::response('', 404),
        ]);

        $this->expectException(ExchangeRateProviderException::class);

        $provider = new TcmbExchangeRateProvider(new CurrencyCodeNormalizer(), CurrencyMath::fromConfig());
        $provider->fetchForDate(CarbonImmutable::parse('2026-07-12'), 'forex_selling');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Currency/' . $name));
    }
}
