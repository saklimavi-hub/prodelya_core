<?php

namespace App\DTOs\Currency;

final class CurrencyConversionResult
{
    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function __construct(
        public readonly string $sourceAmount,
        public readonly string $sourceCurrency,
        public readonly string $targetAmount,
        public readonly string $targetCurrency,
        public readonly string $effectiveRate,
        public readonly string $requestedDate,
        public readonly string $resolvedRateDate,
        public readonly string $rateSource,
        public readonly string $rateType,
        public readonly bool $isFallbackDate,
        public readonly bool $isStale,
        public readonly bool $isManual,
        public readonly int $roundingPrecision,
        public readonly array $legs,
        public readonly ?array $manualOverride = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'source_amount' => $this->sourceAmount,
            'source_currency' => $this->sourceCurrency,
            'target_amount' => $this->targetAmount,
            'target_currency' => $this->targetCurrency,
            'effective_rate' => $this->effectiveRate,
            'requested_date' => $this->requestedDate,
            'resolved_rate_date' => $this->resolvedRateDate,
            'rate_source' => $this->rateSource,
            'rate_type' => $this->rateType,
            'is_fallback_date' => $this->isFallbackDate,
            'is_stale' => $this->isStale,
            'is_manual' => $this->isManual,
            'rounding_precision' => $this->roundingPrecision,
            'legs' => $this->legs,
            'manual_override' => $this->manualOverride,
        ];
    }
}
