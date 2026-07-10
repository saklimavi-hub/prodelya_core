<?php

namespace Tests\Feature\Currency;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCurrencyRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_to_database(): void
    {
        Http::fake(['*' => Http::response($this->fixture('tcmb-20260710.xml'), 200)]);

        $this->artisan('prodelya:currency-rates-sync', [
            '--date' => '2026-07-10',
            '--dry-run' => true,
        ])->expectsOutputToContain('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.')
            ->assertSuccessful();

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_normal_sync_is_idempotent(): void
    {
        Http::fake(['*' => Http::response($this->fixture('tcmb-20260710.xml'), 200)]);

        $this->artisan('prodelya:currency-rates-sync', ['--date' => '2026-07-10'])->assertSuccessful();
        $this->artisan('prodelya:currency-rates-sync', ['--date' => '2026-07-10'])->assertSuccessful();

        $this->assertDatabaseCount('exchange_rates', 2);
    }

    public function test_fallback_date_is_reported(): void
    {
        Http::fake([
            'https://www.tcmb.gov.tr/kurlar/202607/12072026.xml' => Http::response('', 404),
            'https://www.tcmb.gov.tr/kurlar/202607/11072026.xml' => Http::response('', 404),
            'https://www.tcmb.gov.tr/kurlar/202607/10072026.xml' => Http::response($this->fixture('tcmb-20260710.xml'), 200),
        ]);

        $this->artisan('prodelya:currency-rates-sync', ['--date' => '2026-07-12'])
            ->expectsOutputToContain('Fallback tarih kullanıldı.')
            ->assertSuccessful();

        $this->assertDatabaseHas('exchange_rates', [
            'source_currency' => 'USD',
            'target_currency' => 'TRY',
            'rate_date' => '2026-07-10 00:00:00',
        ]);
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->artisan('prodelya:currency-rates-sync', ['--date' => '10-07-2026'])
            ->expectsOutputToContain('Geçersiz tarih. YYYY-MM-DD kullanın.')
            ->assertFailed();
    }

    public function test_unsupported_provider_is_rejected(): void
    {
        $this->artisan('prodelya:currency-rates-sync', ['--provider' => 'ecb'])
            ->expectsOutputToContain('Desteklenmeyen provider.')
            ->assertFailed();
    }

    public function test_invalid_rate_type_is_rejected(): void
    {
        $this->artisan('prodelya:currency-rates-sync', ['--rate-type' => 'spot'])
            ->expectsOutputToContain('Desteklenmeyen rate type.')
            ->assertFailed();
    }

    public function test_missing_rate_is_reported_as_failure(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('prodelya:currency-rates-sync', ['--date' => '2026-07-12', '--lookback' => 1])
            ->expectsOutputToContain('Kur bulunamadı')
            ->assertFailed();
    }

    public function test_command_help_is_available(): void
    {
        $this->artisan('prodelya:currency-rates-sync', ['--help' => true])->assertSuccessful();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Currency/' . $name));
    }
}
