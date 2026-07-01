<?php

namespace App\Services\ProductDataHub;

use App\Support\ProductDisplayNameFormatter;

class ProductAttributeValueNormalizer
{
    private const SUPPORTED_FIELDS = [
        'renk' => 'color',
        'renk_adi' => 'color',
        'color' => 'color',
        'variant_color' => 'color',
        'ölçü' => 'size',
        'olcu' => 'size',
        'olcu_adi' => 'size',
        'size' => 'size',
        'variant_size' => 'size',
        'ebat' => 'size',
        'measure' => 'size',
        'kapasite' => 'capacity',
        'capacity' => 'capacity',
        'beden' => 'size',
        'malzeme' => 'material',
        'material' => 'material',
        'option' => 'option',
        'opsiyon' => 'option',
    ];

    private const WORD_MAP = [
        'ACIK' => 'Açık',
        'KOYU' => 'Koyu',
        'KIRMIZI' => 'Kırmızı',
        'SIYAH' => 'Siyah',
        'SARI' => 'Sarı',
        'YESIL' => 'Yeşil',
        'MAVI' => 'Mavi',
        'LACIVERT' => 'Lacivert',
        'TURUNCU' => 'Turuncu',
        'MOR' => 'Mor',
        'PEMBE' => 'Pembe',
        'BEYAZ' => 'Beyaz',
        'GUMUS' => 'Gümüş',
        'GRI' => 'Gri',
        'SEFFAF' => 'Şeffaf',
        'FUME' => 'Füme',
        'KAHVERENGI' => 'Kahverengi',
        'TURKUAZ' => 'Turkuaz',
        'ALTIN' => 'Altın',
        'BORDO' => 'Bordo',
        'KREM' => 'Krem',
        'METALIK' => 'Metalik',
        'GOLD' => 'Gold',
        'SILVER' => 'Silver',
        'ROSE' => 'Rose',
        'MAT' => 'Mat',
        'PARLAK' => 'Parlak',
        'CUKUR' => 'Çukur',
        'OLCU' => 'Ölçü',
        'KAPASITE' => 'Kapasite',
        'MALZEME' => 'Malzeme',
    ];

    private const TOKEN_PRESERVE = [
        'XS',
        'S',
        'M',
        'L',
        'XL',
        'XXL',
        'XXXL',
        '3XL',
        '4XL',
        '5XL',
        'GB',
        'TB',
        'USB',
        'PVC',
        'LED',
        'LCD',
        'A4',
        'A5',
    ];

    public function normalize(?string $field, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normalizeAttributes($value);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return $value;
        }

        $canonicalField = $this->canonicalField($field);
        if ($canonicalField === null) {
            return $value;
        }

        return $this->normalizeValue((string) $value, $canonicalField);
    }

    public function normalizeDisplayValue(mixed $value, ?string $attributeType = null): mixed
    {
        return $this->normalize($attributeType, $value);
    }

    public function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$key] = $this->normalize((string) $key, $value);
        }

        return $normalized;
    }

    public function supports(?string $field): bool
    {
        return $this->canonicalField($field) !== null;
    }

    private function canonicalField(?string $field): ?string
    {
        if ($field === null) {
            return null;
        }

        $normalized = ProductDisplayNameFormatter::normalizeTurkishText($field);
        $normalized = str_replace(['-', ' '], '_', mb_strtolower($normalized, 'UTF-8'));

        return self::SUPPORTED_FIELDS[$normalized] ?? null;
    }

    private function normalizeValue(string $value, string $field): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/[_\-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $tokens = preg_split('/\s+/u', $text) ?: [];
        $resolved = [];

        foreach ($tokens as $token) {
            $normalizedToken = ProductDisplayNameFormatter::normalizeTurkishText($token);

            if (isset(self::WORD_MAP[$normalizedToken])) {
                $resolved[] = self::WORD_MAP[$normalizedToken];
                continue;
            }

            if (in_array($normalizedToken, self::TOKEN_PRESERVE, true)) {
                $resolved[] = $normalizedToken;
                continue;
            }

            $resolved[] = ProductDisplayNameFormatter::titleCaseTurkish($token);
        }

        $resolvedText = ProductDisplayNameFormatter::titleCaseTurkish(implode(' ', $resolved));

        if ($field === 'capacity') {
            $resolvedText = preg_replace('/\bMl\b/u', 'ml', $resolvedText) ?? $resolvedText;
            $resolvedText = preg_replace('/\bMah\b/u', 'mAh', $resolvedText) ?? $resolvedText;
            $resolvedText = preg_replace('/\bGb\b/u', 'GB', $resolvedText) ?? $resolvedText;
        }

        if ($field === 'size') {
            $resolvedText = preg_replace('/\bCm\b/u', 'cm', $resolvedText) ?? $resolvedText;
            $resolvedText = preg_replace('/\bMm\b/u', 'mm', $resolvedText) ?? $resolvedText;
        }

        return ProductDisplayNameFormatter::normalizeWhitespace($resolvedText);
    }
}
