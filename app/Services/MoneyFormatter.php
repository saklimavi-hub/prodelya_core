<?php

namespace App\Services;

class MoneyFormatter
{
    /**
     * Format money amount with Turkish standards
     */
    public static function format(float $amount, string $currency = 'TL'): string
    {
        $formatted = number_format($amount, 2, ',', '.');
        
        return match ($currency) {
            'TL' => $formatted . ' TL',
            'USD' => '$' . $formatted,
            'EUR' => $formatted . ' €',
            default => $formatted . ' ' . $currency,
        };
    }

    /**
     * Format money amount without currency symbol
     */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    /**
     * Format money amount for display in tables
     */
    public static function formatTable(float $amount, string $currency = 'TL'): string
    {
        $formatted = self::format($amount, $currency);
        
        return '<span class="font-medium">' . $formatted . '</span>';
    }

    /**
     * Format money amount with color coding (positive/negative)
     */
    public static function formatWithColor(float $amount, string $currency = 'TL'): string
    {
        $color = $amount >= 0 ? 'text-green-600' : 'text-red-600';
        $formatted = self::format($amount, $currency);
        
        return '<span class="' . $color . ' font-medium">' . $formatted . '</span>';
    }

    /**
     * Format large amount with abbreviation
     */
    public static function formatLarge(float $amount, string $currency = 'TL'): string
    {
        $abs = abs($amount);
        
        if ($abs >= 1000000) {
            $formatted = number_format($amount / 1000000, 1, ',', '.') . 'M';
        } elseif ($abs >= 1000) {
            $formatted = number_format($amount / 1000, 1, ',', '.') . 'K';
        } else {
            $formatted = number_format($amount, 0, ',', '.');
        }
        
        return match ($currency) {
            'TL' => $formatted . ' TL',
            'USD' => '$' . $formatted,
            'EUR' => $formatted . ' €',
            default => $formatted . ' ' . $currency,
        };
    }

    /**
     * Convert currency to TL using exchange rate
     */
    public static function convertToTL(float $amount, string $fromCurrency, float $exchangeRate = 1): float
    {
        if ($fromCurrency === 'TL') {
            return $amount;
        }
        
        return $amount * $exchangeRate;
    }

    /**
     * Format currency exchange information
     */
    public static function formatExchangeInfo(float $originalAmount, string $originalCurrency, float $exchangeRate, string $targetCurrency = 'TL'): string
    {
        $tlAmount = self::convertToTL($originalAmount, $originalCurrency, $exchangeRate);
        
        return sprintf(
            '%s (%s = %s)',
            self::format($originalAmount, $originalCurrency),
            number_format($exchangeRate, 4, ',', '.'),
            self::format($tlAmount, $targetCurrency)
        );
    }
}
