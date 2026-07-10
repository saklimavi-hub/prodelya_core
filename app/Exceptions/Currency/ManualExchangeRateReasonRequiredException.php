<?php

namespace App\Exceptions\Currency;

use InvalidArgumentException;

class ManualExchangeRateReasonRequiredException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Manuel kur override işlemi için gerekçe zorunludur.');
    }
}
