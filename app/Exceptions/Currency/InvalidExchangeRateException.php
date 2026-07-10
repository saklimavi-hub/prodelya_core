<?php

namespace App\Exceptions\Currency;

use InvalidArgumentException;

class InvalidExchangeRateException extends InvalidArgumentException
{
    public static function becauseRateIsInvalid(mixed $rate): self
    {
        return new self('Geçersiz kur değeri: ' . (string) $rate);
    }

    public static function becauseSourceUnitIsInvalid(mixed $sourceUnit): self
    {
        return new self('Geçersiz kaynak birimi: ' . (string) $sourceUnit);
    }
}
