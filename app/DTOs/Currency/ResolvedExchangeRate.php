<?php

namespace App\DTOs\Currency;

final class ResolvedExchangeRate
{
    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function __construct(
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly string $rate,
        public readonly string $requestedDate,
        public readonly string $resolvedRateDate,
        public readonly string $rateSource,
        public readonly string $rateType,
        public readonly bool $isFallbackDate,
        public readonly bool $isStale,
        public readonly bool $isManual,
        public readonly array $legs,
    ) {
    }

    public static function identity(string $currency, string $requestedDate, string $rateType): self
    {
        return new self(
            sourceCurrency: $currency,
            targetCurrency: $currency,
            rate: '1.00000000',
            requestedDate: $requestedDate,
            resolvedRateDate: $requestedDate,
            rateSource: 'identity',
            rateType: $rateType,
            isFallbackDate: false,
            isStale: false,
            isManual: false,
            legs: [[
                'source' => $currency,
                'target' => $currency,
                'rate' => '1.00000000',
                'rate_date' => $requestedDate,
                'provider' => 'identity',
            ]],
        );
    }

    public function toArray(): array
    {
        return [
            'source_currency' => $this->sourceCurrency,
            'target_currency' => $this->targetCurrency,
            'rate' => $this->rate,
            'requested_date' => $this->requestedDate,
            'resolved_rate_date' => $this->resolvedRateDate,
            'rate_source' => $this->rateSource,
            'rate_type' => $this->rateType,
            'is_fallback_date' => $this->isFallbackDate,
            'is_stale' => $this->isStale,
            'is_manual' => $this->isManual,
            'legs' => $this->legs,
        ];
    }
}
