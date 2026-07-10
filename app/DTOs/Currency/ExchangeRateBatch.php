<?php

namespace App\DTOs\Currency;

final class ExchangeRateBatch
{
    /**
     * @param  array<int, ExchangeRateData>  $rates
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $rateType,
        public readonly string $requestedDate,
        public readonly string $resolvedRateDate,
        public readonly string $fetchedAt,
        public readonly string $payloadHash,
        public readonly array $rates,
    ) {
    }
}
