<?php

namespace App\Services\Currency;

use App\DTOs\Currency\CurrencyConversionResult;
use App\DTOs\Currency\ManualExchangeRateOverrideData;

class CurrencyConversionService
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly ExchangeRateResolver $resolver,
        private readonly CurrencyMath $math,
    ) {
    }

    public function convert(
        int|float|string $amount,
        string $sourceCurrency,
        string $targetCurrency,
        string $requestedDate,
        ?string $rateType = null,
        ?ManualExchangeRateOverrideData $manualOverride = null,
    ): CurrencyConversionResult {
        $source = $this->normalizer->normalize($sourceCurrency);
        $target = $this->normalizer->normalize($targetCurrency);
        $normalizedAmount = $this->math->normalizeNumber($amount);
        $rateType ??= (string) config('prodelya_currency.default_rate_type', 'forex_selling');
        $resolved = $this->resolver->resolve($source, $target, $requestedDate, $rateType, $manualOverride);
        $precision = (int) config('prodelya_currency.money_precision', 2);
        $targetAmount = $this->math->round(
            $this->math->multiply($normalizedAmount, $resolved->rate, (int) config('prodelya_currency.calculation_precision', 12)),
            $precision
        );

        return new CurrencyConversionResult(
            sourceAmount: $normalizedAmount,
            sourceCurrency: $source,
            targetAmount: $targetAmount,
            targetCurrency: $target,
            effectiveRate: $resolved->rate,
            requestedDate: $requestedDate,
            resolvedRateDate: $resolved->resolvedRateDate,
            rateSource: $resolved->rateSource,
            rateType: $resolved->rateType,
            isFallbackDate: $resolved->isFallbackDate,
            isStale: $resolved->isStale,
            isManual: $resolved->isManual,
            roundingPrecision: $precision,
            legs: $resolved->legs,
            manualOverride: $manualOverride?->toArray(),
        );
    }
}
