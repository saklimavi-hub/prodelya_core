<?php

namespace App\Services\ProductDataHub;

use Illuminate\Support\Str;

class CategorySignalNormalizer
{
    public function normalizeText(string $value): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ];

        $normalized = strtr($value, $map);
        $normalized = Str::lower($normalized);
        $normalized = preg_replace('/[\/,_\-]+/u', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        $normalized = str_replace(['urunleri', 'urunler', 'ürünleri', 'ürünler'], 'urun', $normalized);

        return trim($normalized);
    }

    public function tokenizeText(string $value): array
    {
        $normalized = $this->normalizeText($value);
        $tokens = preg_split('/\s+/u', $normalized) ?: [];

        return array_values(array_filter($tokens, fn (string $token) => mb_strlen($token, 'UTF-8') >= 2));
    }

    public function countKeywordMatches(string $haystack, array $needles): int
    {
        $normalizedHaystack = $this->normalizeText($haystack);
        $count = 0;

        foreach ($needles as $needle) {
            if (str_contains($normalizedHaystack, $this->normalizeText($needle))) {
                $count++;
            }
        }

        return $count;
    }
}
