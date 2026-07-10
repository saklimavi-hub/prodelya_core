<?php

namespace App\Console\Commands;

use App\Exceptions\Currency\ExchangeRateProviderException;
use App\Services\Currency\ExchangeRateSyncService;
use Illuminate\Console\Command;

class SyncCurrencyRatesCommand extends Command
{
    protected $signature = 'prodelya:currency-rates-sync
        {--date= : YYYY-MM-DD}
        {--provider=tcmb : Provider adı}
        {--rate-type=forex_selling : Kur tipi}
        {--dry-run : DB yazmadan çalıştır}
        {--lookback= : Geriye bakılacak gün sayısı}
        {--force : Gelecek tarih/schedule korumalarını zorla}';

    protected $description = 'Prodelya ortak döviz kuru çekirdeğini günceller.';

    public function __construct(
        private readonly ExchangeRateSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date');
        $provider = (string) $this->option('provider');
        $rateType = (string) $this->option('rate-type');
        $lookback = $this->option('lookback');

        if ($date !== null && !$this->isValidDate((string) $date)) {
            $this->error('Geçersiz tarih. YYYY-MM-DD kullanın.');

            return self::FAILURE;
        }

        if (!in_array($provider, array_keys((array) config('prodelya_currency.providers', [])), true)) {
            $this->error('Desteklenmeyen provider.');

            return self::FAILURE;
        }

        $validRateTypes = array_keys((array) config("prodelya_currency.providers.{$provider}.supported_rate_types", []));
        if (!in_array($rateType, $validRateTypes, true)) {
            $this->error('Desteklenmeyen rate type.');

            return self::FAILURE;
        }

        if ($lookback !== null && (!is_numeric($lookback) || (int) $lookback < 0)) {
            $this->error('Lookback değeri sıfır veya pozitif tam sayı olmalıdır.');

            return self::FAILURE;
        }

        try {
            $result = $this->syncService->sync(
                requestedDate: $date ? (string) $date : null,
                provider: $provider,
                rateType: $rateType,
                dryRun: (bool) $this->option('dry-run'),
                lookback: $lookback !== null ? (int) $lookback : null,
                force: (bool) $this->option('force'),
            );
        } catch (ExchangeRateProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Kur senkronizasyonu başarısız: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Talep edilen tarih: ' . $result['requested_date']);
        $this->line('Kullanılan kur tarihi: ' . $result['resolved_rate_date']);
        $this->line('Kaynak: ' . $result['provider']);
        $this->line('Kur tipi: ' . $result['rate_type']);
        $this->line('Yeni: ' . $result['created_count']);
        $this->line('Güncellenen: ' . $result['updated_count']);
        $this->line('Değişmeyen: ' . $result['unchanged_count']);
        $this->line('Hata: ' . $result['failed_count']);

        if ($result['is_fallback_date']) {
            $this->warn('Fallback tarih kullanıldı.');
        }

        if ($result['is_dry_run']) {
            $this->comment('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.');
        }

        if ((int) $result['failed_count'] > 0) {
            $this->error('Kur senkronizasyonu bazı kayıtları işleyemedi.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
