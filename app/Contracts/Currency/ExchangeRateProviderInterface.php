<?php

namespace App\Contracts\Currency;

use App\DTOs\Currency\ExchangeRateBatch;
use Carbon\CarbonInterface;

interface ExchangeRateProviderInterface
{
    public function supports(string $provider): bool;

    public function fetchForDate(CarbonInterface $date, string $rateType): ExchangeRateBatch;
}
