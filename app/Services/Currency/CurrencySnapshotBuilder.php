<?php

namespace App\Services\Currency;

use App\DTOs\Currency\CurrencyConversionResult;

class CurrencySnapshotBuilder
{
    public function build(CurrencyConversionResult $result): array
    {
        return [
            'source_currency' => $result->sourceCurrency,
            'target_currency' => $result->targetCurrency,
            'source_amount' => $result->sourceAmount,
            'converted_amount' => $result->targetAmount,
            'effective_rate' => $result->effectiveRate,
            'rate_source' => $result->rateSource,
            'rate_type' => $result->rateType,
            'requested_date' => $result->requestedDate,
            'rate_date' => $result->resolvedRateDate,
            'fallback_used' => $result->isFallbackDate,
            'manual_override' => $result->manualOverride,
            'rounding' => [
                'money_precision' => $result->roundingPrecision,
                'rate_precision' => (int) config('prodelya_currency.rate_precision', 8),
                'calculation_precision' => (int) config('prodelya_currency.calculation_precision', 12),
            ],
            'conversion_legs' => $result->legs,
        ];
    }
}
