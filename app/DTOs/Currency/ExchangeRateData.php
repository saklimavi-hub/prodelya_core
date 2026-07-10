<?php

namespace App\DTOs\Currency;

use App\Exceptions\Currency\InvalidExchangeRateException;

final class ExchangeRateData
{
    public function __construct(
        public readonly string $provider,
        public readonly string $rateType,
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly string $rateDate,
        public readonly int $sourceUnit,
        public readonly string $rate,
        public readonly string $fetchedAt,
        public readonly string $payloadHash,
        public readonly array $meta = [],
    ) {
        if ($this->sourceUnit <= 0) {
            throw InvalidExchangeRateException::becauseSourceUnitIsInvalid($this->sourceUnit);
        }

        if ((float) $this->rate <= 0) {
            throw InvalidExchangeRateException::becauseRateIsInvalid($this->rate);
        }
    }

    public function toDatabaseRecord(): array
    {
        return [
            'provider' => $this->provider,
            'rate_type' => $this->rateType,
            'source_currency' => $this->sourceCurrency,
            'target_currency' => $this->targetCurrency,
            'rate_date' => $this->rateDate,
            'source_unit' => $this->sourceUnit,
            'rate' => $this->rate,
            'fetched_at' => $this->fetchedAt,
            'payload_hash' => $this->payloadHash,
            'meta_json' => $this->meta,
        ];
    }
}
