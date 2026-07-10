<?php

namespace App\Exceptions\Currency;

use InvalidArgumentException;

class UnsupportedCurrencyException extends InvalidArgumentException
{
    public function __construct(string $currency)
    {
        parent::__construct("Desteklenmeyen para birimi: {$currency}");
    }
}
