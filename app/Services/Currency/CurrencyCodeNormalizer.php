<?php

namespace App\Services\Currency;

use App\Exceptions\Currency\UnsupportedCurrencyException;

class CurrencyCodeNormalizer
{
    public function normalize(?string $currency): string
    {
        $value = $this->normalizeInput($currency);

        return match ($value) {
            'TL', 'TRY', '₺' => 'TRY',
            'USD', '$', 'DOLAR' => 'USD',
            'EUR', '€', 'EURO', 'AVRO' => 'EUR',
            default => throw new UnsupportedCurrencyException((string) $currency),
        };
    }

    public function normalizeOrDefault(?string $currency, string $default = 'TRY'): string
    {
        if ($currency === null || trim($currency) === '') {
            return $this->normalize($default);
        }

        return $this->normalize($currency);
    }

    private function normalizeInput(?string $currency): string
    {
        $value = trim((string) $currency);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = str_replace(["\u{00A0}", ' '], '', $value);
        $value = strtr($value, [
            'İ' => 'I',
            'ı' => 'I',
        ]);

        return $value;
    }
}
