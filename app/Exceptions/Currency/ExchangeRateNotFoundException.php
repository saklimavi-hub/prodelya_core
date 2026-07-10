<?php

namespace App\Exceptions\Currency;

use RuntimeException;

class ExchangeRateNotFoundException extends RuntimeException
{
    public function __construct(
        string $sourceCurrency,
        string $targetCurrency,
        string $requestedDate,
        string $rateType,
    ) {
        parent::__construct("Kur bulunamadı: {$sourceCurrency}/{$targetCurrency} {$requestedDate} ({$rateType})");
    }
}
