<?php

namespace App\Services;

class NumberFormatter
{
    /**
     * Format number with Turkish standards
     */
    public static function format(float $number, int $decimals = 2): string
    {
        return number_format($number, $decimals, ',', '.');
    }

    /**
     * Format integer number
     */
    public static function formatInt(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }

    /**
     * Format percentage
     */
    public static function formatPercentage(float $number, int $decimals = 1): string
    {
        return number_format($number, $decimals, ',', '.') . '%';
    }

    /**
     * Format large number with abbreviation
     */
    public static function formatLarge(float $number): string
    {
        $abs = abs($number);
        
        if ($abs >= 1000000000) {
            return number_format($number / 1000000000, 1, ',', '.') . 'B';
        } elseif ($abs >= 1000000) {
            return number_format($number / 1000000, 1, ',', '.') . 'M';
        } elseif ($abs >= 1000) {
            return number_format($number / 1000, 1, ',', '.') . 'K';
        } else {
            return number_format($number, 0, ',', '.');
        }
    }

    /**
     * Format phone number (Turkish format)
     */
    public static function formatPhone(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a Turkish number (10 or 11 digits)
        if (strlen($phone) === 10) {
            return preg_replace('/(\d{3})(\d{3})(\d{4})/', '$1 $2 $3', $phone);
        } elseif (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            return preg_replace('/(\d{4})(\d{3})(\d{4})/', '$1 $2 $3', $phone);
        }
        
        return $phone;
    }

    /**
     * Format tax number (Turkish TCKN/VKN)
     */
    public static function formatTaxNumber(string $taxNumber): string
    {
        // Remove all non-numeric characters
        $taxNumber = preg_replace('/[^0-9]/', '', $taxNumber);
        
        if (strlen($taxNumber) === 10) {
            return preg_replace('/(\d{3})(\d{3})(\d{4})/', '$1 $2 $3', $taxNumber);
        } elseif (strlen($taxNumber) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{5})/', '$1 $2 $3', $taxNumber);
        }
        
        return $taxNumber;
    }

    /**
     * Format quantity with unit
     */
    public static function formatQuantity(float $quantity, string $unit = 'adet'): string
    {
        return self::format($quantity, 4) . ' ' . $unit;
    }

    /**
     * Format weight in kg
     */
    public static function formatWeight(float $kg): string
    {
        return self::format($kg, 3) . ' kg';
    }

    /**
     * Format dimensions
     */
    public static function formatDimensions(float $length, float $width, float $height): string
    {
        return sprintf(
            '%s x %s x %s cm',
            self::format($length, 2),
            self::format($width, 2),
            self::format($height, 2)
        );
    }

    /**
     * Format file size
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format number with color coding for status
     */
    public static function formatWithStatus(float $number, string $status = 'neutral'): string
    {
        $color = match ($status) {
            'positive' => 'text-green-600',
            'negative' => 'text-red-600',
            'warning' => 'text-amber-600',
            'info' => 'text-blue-600',
            default => 'text-gray-900',
        };
        
        return '<span class="' . $color . ' font-medium">' . self::format($number) . '</span>';
    }
}
