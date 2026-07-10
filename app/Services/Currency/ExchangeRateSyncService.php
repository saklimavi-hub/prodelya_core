<?php

namespace App\Services\Currency;

use App\DTOs\Currency\ExchangeRateBatch;
use App\DTOs\Currency\ExchangeRateData;
use App\Exceptions\Currency\ExchangeRateProviderException;
use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ExchangeRateSyncService
{
    public function __construct(
        private readonly ExchangeRateProviderFactory $providerFactory,
    ) {
    }

    public function sync(
        ?string $requestedDate = null,
        ?string $provider = null,
        ?string $rateType = null,
        bool $dryRun = false,
        ?int $lookback = null,
        bool $force = false,
    ): array {
        $provider ??= (string) config('prodelya_currency.default_rate_source', 'tcmb');
        $rateType ??= (string) config('prodelya_currency.default_rate_type', 'forex_selling');
        $lookback ??= (int) config('prodelya_currency.fallback_lookback_days', 7);
        $requested = CarbonImmutable::parse($requestedDate ?: now()->toDateString());
        $batch = $this->resolveBatch($requested, $provider, $rateType, $lookback, $force);

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $failed = 0;

        $runner = function () use ($batch, &$created, &$updated, &$unchanged, &$failed): void {
            foreach ($batch->rates as $rate) {
                try {
                    [$status] = $this->upsertRate($rate);
                    if ($status === 'created') {
                        $created++;
                    } elseif ($status === 'updated') {
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                } catch (\Throwable) {
                    $failed++;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $runner();
            } finally {
                DB::rollBack();
            }
        } else {
            $runner();
        }

        return [
            'requested_date' => $requested->toDateString(),
            'resolved_rate_date' => $batch->resolvedRateDate,
            'provider' => $provider,
            'rate_type' => $rateType,
            'created_count' => $created,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
            'failed_count' => $failed,
            'is_fallback_date' => $requested->toDateString() !== $batch->resolvedRateDate,
            'is_dry_run' => $dryRun,
        ];
    }

    /**
     * @return array{0:string,1:ExchangeRate}
     */
    public function upsertRate(ExchangeRateData $rate): array
    {
        $record = ExchangeRate::query()
            ->where('provider', $rate->provider)
            ->where('rate_type', $rate->rateType)
            ->where('source_currency', $rate->sourceCurrency)
            ->where('target_currency', $rate->targetCurrency)
            ->whereDate('rate_date', $rate->rateDate)
            ->first();

        if (!$record) {
            $record = new ExchangeRate([
                'provider' => $rate->provider,
                'rate_type' => $rate->rateType,
                'source_currency' => $rate->sourceCurrency,
                'target_currency' => $rate->targetCurrency,
                'rate_date' => $rate->rateDate,
            ]);
        }

        $record->fill($rate->toDatabaseRecord());

        $status = $record->exists ? 'unchanged' : 'created';

        if ($record->exists && $record->isDirty()) {
            $status = 'updated';
        }

        $record->save();

        return [$status, $record];
    }

    private function resolveBatch(
        CarbonInterface $requestedDate,
        string $provider,
        string $rateType,
        int $lookback,
        bool $force,
    ): ExchangeRateBatch {
        $service = $this->providerFactory->make($provider);

        for ($i = 0; $i <= $lookback; $i++) {
            $date = CarbonImmutable::instance($requestedDate)->subDays($i);

            if (!$force && $date->greaterThan(CarbonImmutable::today())) {
                continue;
            }

            try {
                return $service->fetchForDate($date, $rateType);
            } catch (ExchangeRateProviderException $exception) {
                if ($exception->reason !== 'rate_date_unavailable') {
                    throw $exception;
                }
            }
        }

        throw new ExchangeRateNotFoundException('USD', 'TRY', $requestedDate->toDateString(), $rateType);
    }
}
