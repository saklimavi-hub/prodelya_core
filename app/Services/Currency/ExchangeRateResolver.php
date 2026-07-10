<?php

namespace App\Services\Currency;

use App\DTOs\Currency\ManualExchangeRateOverrideData;
use App\DTOs\Currency\ResolvedExchangeRate;
use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

class ExchangeRateResolver
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly CurrencyMath $math,
    ) {
    }

    public function resolve(
        string $sourceCurrency,
        string $targetCurrency,
        string $requestedDate,
        ?string $rateType = null,
        ?ManualExchangeRateOverrideData $manualOverride = null,
    ): ResolvedExchangeRate {
        $source = $this->normalizer->normalize($sourceCurrency);
        $target = $this->normalizer->normalize($targetCurrency);
        $rateType ??= (string) config('prodelya_currency.default_rate_type', 'forex_selling');
        $date = CarbonImmutable::parse($requestedDate);

        if ($manualOverride) {
            return new ResolvedExchangeRate(
                sourceCurrency: $source,
                targetCurrency: $target,
                rate: $this->math->ensurePositiveRate($manualOverride->rate),
                requestedDate: $date->toDateString(),
                resolvedRateDate: $date->toDateString(),
                rateSource: 'manual',
                rateType: $rateType,
                isFallbackDate: false,
                isStale: false,
                isManual: true,
                legs: [[
                    'source' => $source,
                    'target' => $target,
                    'rate' => $this->math->ensurePositiveRate($manualOverride->rate),
                    'rate_date' => $date->toDateString(),
                    'provider' => 'manual',
                ]],
            );
        }

        if ($source === $target) {
            return ResolvedExchangeRate::identity($source, $date->toDateString(), $rateType);
        }

        $direct = $this->findPairRate($source, $target, $date, $rateType);
        if ($direct) {
            return $direct;
        }

        if ($source !== 'TRY' && $target !== 'TRY') {
            $left = $this->findPairRate($source, 'TRY', $date, $rateType);
            $right = $this->findPairRate('TRY', $target, $date, $rateType);

            if ($left && $right) {
                $resolvedDate = $left->resolvedRateDate >= $right->resolvedRateDate ? $left->resolvedRateDate : $right->resolvedRateDate;
                $rate = $this->math->multiply($left->rate, $right->rate, (int) config('prodelya_currency.rate_precision', 8));

                return new ResolvedExchangeRate(
                    sourceCurrency: $source,
                    targetCurrency: $target,
                    rate: $this->math->ensurePositiveRate($rate),
                    requestedDate: $date->toDateString(),
                    resolvedRateDate: $resolvedDate,
                    rateSource: 'tcmb',
                    rateType: $rateType,
                    isFallbackDate: $left->isFallbackDate || $right->isFallbackDate,
                    isStale: $left->isStale || $right->isStale,
                    isManual: false,
                    legs: array_merge($left->legs, $right->legs),
                );
            }
        }

        throw new ExchangeRateNotFoundException($source, $target, $date->toDateString(), $rateType);
    }

    private function findPairRate(string $source, string $target, CarbonImmutable $requestedDate, string $rateType): ?ResolvedExchangeRate
    {
        if ($source === $target) {
            return ResolvedExchangeRate::identity($source, $requestedDate->toDateString(), $rateType);
        }

        $record = ExchangeRate::query()
            ->where('rate_type', $rateType)
            ->where('source_currency', $source)
            ->where('target_currency', $target)
            ->whereDate('rate_date', '<=', $requestedDate->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if ($record) {
            return $this->buildResolvedRate($record->rate, $record->provider, $source, $target, $requestedDate, (string) $record->rate_date->toDateString(), $rateType);
        }

        $reverse = ExchangeRate::query()
            ->where('rate_type', $rateType)
            ->where('source_currency', $target)
            ->where('target_currency', $source)
            ->whereDate('rate_date', '<=', $requestedDate->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if (!$reverse) {
            return null;
        }

        $inverseRate = $this->math->divide('1', (string) $reverse->rate, (int) config('prodelya_currency.rate_precision', 8));

        return $this->buildResolvedRate($inverseRate, $reverse->provider, $source, $target, $requestedDate, (string) $reverse->rate_date->toDateString(), $rateType);
    }

    private function buildResolvedRate(
        string $rate,
        string $provider,
        string $source,
        string $target,
        CarbonImmutable $requestedDate,
        string $resolvedRateDate,
        string $rateType,
    ): ResolvedExchangeRate {
        $warningThreshold = (int) config('prodelya_currency.stale_warning_threshold', 2);
        $hardFailThreshold = (int) config('prodelya_currency.hard_fail_threshold', 7);
        $diff = CarbonImmutable::parse($resolvedRateDate)->diffInDays($requestedDate);

        if ($diff > $hardFailThreshold) {
            throw new ExchangeRateNotFoundException($source, $target, $requestedDate->toDateString(), $rateType);
        }

        return new ResolvedExchangeRate(
            sourceCurrency: $source,
            targetCurrency: $target,
            rate: $this->math->ensurePositiveRate($rate),
            requestedDate: $requestedDate->toDateString(),
            resolvedRateDate: $resolvedRateDate,
            rateSource: $provider,
            rateType: $rateType,
            isFallbackDate: $resolvedRateDate !== $requestedDate->toDateString(),
            isStale: $diff > $warningThreshold,
            isManual: false,
            legs: [[
                'source' => $source,
                'target' => $target,
                'rate' => $this->math->ensurePositiveRate($rate),
                'rate_date' => $resolvedRateDate,
                'provider' => $provider,
            ]],
        );
    }
}
