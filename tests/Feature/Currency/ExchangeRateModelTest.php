<?php

namespace Tests\Feature\Currency;

use App\Exceptions\Currency\InvalidExchangeRateException;
use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExchangeRateModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rates_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('exchange_rates'));

        foreach ([
            'provider',
            'rate_type',
            'source_currency',
            'target_currency',
            'rate_date',
            'source_unit',
            'rate',
            'fetched_at',
            'payload_hash',
            'meta_json',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('exchange_rates', $column));
        }
    }

    public function test_unique_pair_provider_type_and_date_is_enforced(): void
    {
        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'rate' => '43.25000000',
            'payload_hash' => 'hash-a',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'rate' => '43.25000000',
            'payload_hash' => 'hash-b',
        ]);
    }

    public function test_zero_rate_is_rejected(): void
    {
        $this->expectException(InvalidExchangeRateException::class);

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10',
            'source_unit' => 1,
            'rate' => '0',
            'payload_hash' => 'hash-z',
        ]);
    }
}
