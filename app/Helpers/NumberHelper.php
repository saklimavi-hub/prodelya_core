<?php

namespace App\Helpers;

class NumberHelper
{
    /**
     * Normalize Turkish decimal format
     */
    public static function normalizeDecimal($value): float
    {
        if ($value === null || $value === '') return 0;
        $value = (string) $value;
        
        // If both dot and comma exist, assume Turkish format: 1.234,56 -> 1234.56
        if (strpos($value, '.') !== false && strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (strpos($value, ',') !== false) {
            // Only comma exists: 9,20 -> 9.20
            $value = str_replace(',', '.', $value);
        }
        // If only dot exists, keep as is: 9.20 -> 9.20
        
        return (float) $value;
    }
}
