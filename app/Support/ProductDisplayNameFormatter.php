<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProductDisplayNameFormatter
{
    private const COLOR_MAP = [
        'SIYAH' => 'Siyah',
        'BEYAZ' => 'Beyaz',
        'KIRMIZI' => 'Kırmızı',
        'LACIVERT' => 'Lacivert',
        'MAVI' => 'Mavi',
        'YESIL' => 'Yeşil',
        'SARI' => 'Sarı',
        'TURUNCU' => 'Turuncu',
        'GRI' => 'Gri',
        'GUMUS' => 'Gümüş',
        'ALTIN' => 'Altın',
        'BORDO' => 'Bordo',
        'PEMBE' => 'Pembe',
        'MOR' => 'Mor',
        'KAHVERENGI' => 'Kahverengi',
        'TABA' => 'Taba',
        'FUME' => 'Füme',
        'SEFFAF' => 'Şeffaf',
        'KREM' => 'Krem',
        'TURKUAZ' => 'Turkuaz',
    ];

    private const TECHNICAL_TOKENS = [
        'belirtilmedi',
        'renk belirtilmedi',
        'ebat belirtilmedi',
        'null',
        'undefined',
        'kategori yolu',
        'category path',
        'source category',
        'supplier category',
        'raw field name',
    ];

    private const SUPPLIER_TOKENS = [
        'akdeniz promosyon',
        'etkin promosyon',
        'ilpen',
        'yeni nesil',
    ];

    public static function product(?string $code, ?string $name, ?string ...$parts): string
    {
        return self::format([
            'product_code' => $code,
            'raw_product_name' => $name,
            'parts' => $parts,
        ])['display_name'];
    }

    public static function variant(
        ?string $code,
        ?string $parentName,
        ?string $variantName = null,
        ?string $color = null,
        ?string $size = null,
        ?string $measure = null,
        ?string $capacity = null,
        ?string $option = null,
        array $extraCodes = []
    ): string {
        return self::format([
            'product_code' => $code,
            'supplier_variant_code' => $code,
            'raw_product_name' => $parentName,
            'variant_name' => $variantName,
            'color' => $color,
            'size' => $size,
            'measure' => $measure,
            'capacity' => $capacity,
            'option' => $option,
            'extra_codes' => $extraCodes,
            'is_variant' => true,
        ])['display_name'];
    }

    /**
     * @return array{
     *     display_code:string,
     *     display_name:string,
     *     display_title:string,
     *     search_text:string,
     *     sku:string,
     *     variant_label:string,
     *     attribute_summary:string,
     *     cleanup_warnings:array<int,string>
     * }
     */
    public static function format(array $input): array
    {
        $sku = self::normalizeCode(
            $input['sku']
            ?? $input['supplier_variant_code']
            ?? $input['supplier_product_code']
            ?? $input['product_code']
            ?? null
        );
        $code = self::normalizeCode($input['product_code'] ?? $sku);
        $displayCode = self::displayCode($code);
        $extraCodes = array_values(array_filter(array_unique(array_map(
            fn ($value) => self::normalizeCode(is_scalar($value) ? (string) $value : null),
            array_merge(
                [$code, $sku, $displayCode],
                (array) ($input['extra_codes'] ?? []),
                [
                    $input['supplier_product_code'] ?? null,
                    $input['supplier_variant_code'] ?? null,
                    $input['supplier_group_code'] ?? null,
                    $input['group_code'] ?? null,
                    self::akdenizCatalogCodeToken($code),
                ]
            )
        ))));

        $isAkdeniz = self::isAkdenizCode($code)
            || self::looksLikeSupplier($input['supplier_name'] ?? null, 'akdeniz')
            || self::looksLikeSupplier($input['supplier_source'] ?? null, 'akdeniz');
        $colorFromCode = self::extractColorFromCode($code);
        $colorFromInput = self::normalizeColor($input['color'] ?? null);

        $rawName = self::firstFilled([
            $input['normalized_product_name'] ?? null,
            $input['raw_product_name'] ?? null,
            $input['product_name'] ?? null,
            $input['name'] ?? null,
        ]);
        $variantName = $input['variant_name'] ?? null;

        $baseParent = self::cleanPhrase($rawName, $extraCodes);
        $baseVariant = self::cleanPhrase($variantName, $extraCodes);
        $baseName = self::resolveBaseName($baseParent, $baseVariant);
        $baseName = self::removeDuplicatePhrase($baseName);

        $resolvedColor = $colorFromCode ?: $colorFromInput ?: self::extractColorFromName($baseName);
        if ($isAkdeniz && filled($resolvedColor)) {
            $baseName = self::removeAllColors($baseName);
        }

        $parts = collect([
            $input['size'] ?? null,
            $input['measure'] ?? null,
            $input['capacity'] ?? null,
            $input['material'] ?? null,
            $input['option'] ?? null,
            ...((array) ($input['parts'] ?? [])),
        ])
            ->map(fn ($segment) => self::cleanPhrase(is_scalar($segment) ? (string) $segment : null, $extraCodes))
            ->filter()
            ->reject(fn ($segment) => self::containsWhole($baseName, $segment))
            ->unique(fn ($segment) => self::normalizeTurkishText($segment))
            ->values()
            ->all();

        $colorSegment = filled($resolvedColor) && !self::containsWhole($baseName, $resolvedColor)
            ? $resolvedColor
            : null;

        $nameParts = $isAkdeniz
            ? [$displayCode, $colorSegment, $baseName, ...$parts]
            : [$displayCode, $baseName, $colorSegment, ...$parts];
        $displayName = self::normalizeWhitespace(implode(' ', array_filter($nameParts)));
        $displayName = self::removeDuplicateColor($displayName, $resolvedColor);
        $displayName = self::removeDuplicatePhrase($displayName);
        $displayName = $displayName !== '' ? $displayName : ($displayCode !== '' ? $displayCode : 'Ürün');

        $attributeSummary = self::normalizeWhitespace(implode(' ', array_filter([
            $resolvedColor,
            $input['size'] ?? null,
            $input['measure'] ?? null,
            $input['capacity'] ?? null,
            $input['material'] ?? null,
        ])));

        $warnings = self::detectCleanupWarnings($displayName, $code, $sku, $input);

        return [
            'display_code' => $displayCode,
            'display_name' => $displayName,
            'display_title' => $displayName,
            'search_text' => self::buildSearchText(array_merge($input, [
                'display_code' => $displayCode,
                'display_name' => $displayName,
                'sku' => $sku,
                'color' => $resolvedColor,
            ])),
            'sku' => $sku,
            'variant_label' => $attributeSummary,
            'attribute_summary' => $attributeSummary,
            'cleanup_warnings' => $warnings,
        ];
    }

    public static function normalizeTurkishText(?string $value): string
    {
        $text = mb_strtoupper(trim((string) $value));

        return strtr($text, [
            'İ' => 'I',
            'İ' => 'I',
            'Ş' => 'S',
            'Ğ' => 'G',
            'Ü' => 'U',
            'Ö' => 'O',
            'Ç' => 'C',
            'Â' => 'A',
            'Ê' => 'E',
            'Î' => 'I',
            'Û' => 'U',
        ]);
    }

    public static function cleanSupplierCodeFromName(?string $name, ?string $code): string
    {
        return self::cleanPhrase($name, [self::normalizeCode($code)]);
    }

    public static function cleanVariantCodeFromName(?string $name, ?string $code): string
    {
        return self::cleanPhrase($name, [self::normalizeCode($code), self::displayCode($code)]);
    }

    public static function extractColorFromCode(?string $code): ?string
    {
        $cleanCode = self::normalizeCode($code);
        if ($cleanCode === '' || !str_contains($cleanCode, '-')) {
            return null;
        }

        $parts = explode('-', $cleanCode);
        $last = end($parts);

        return self::normalizeColor($last ?: null);
    }

    public static function extractColorFromName(?string $name): ?string
    {
        $normalized = self::normalizeTurkishText($name);
        foreach (self::COLOR_MAP as $key => $label) {
            if (preg_match('/(^|[^A-Z0-9])' . preg_quote($key, '/') . '([^A-Z0-9]|$)/u', $normalized)) {
                return $label;
            }
        }

        return null;
    }

    public static function removeDuplicateColor(string $name, ?string $color = null): string
    {
        $resolvedColor = self::normalizeColor($color) ?: self::extractColorFromName($name);
        if (!$resolvedColor) {
            return self::normalizeWhitespace($name);
        }

        $key = self::normalizeTurkishText($resolvedColor);
        $words = preg_split('/\s+/u', $name) ?: [];
        $seen = false;
        $result = [];

        foreach ($words as $word) {
            $normalizedWord = self::normalizeTurkishText(trim($word, " \t\n\r\0\x0B,.;:()[]"));
            if ($normalizedWord === $key) {
                if ($seen) {
                    continue;
                }
                $seen = true;
            }

            $result[] = $word;
        }

        return self::normalizeWhitespace(implode(' ', $result));
    }

    public static function removeDuplicateCode(string $name, ?string $code): string
    {
        return self::cleanPhrase($name, [self::normalizeCode($code), self::displayCode($code)]);
    }

    public static function removeDuplicatePhrase(string $name): string
    {
        $text = self::normalizeWhitespace($name);
        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $count = count($words);
        for ($length = intdiv($count, 2); $length >= 2; $length--) {
            for ($offset = 0; $offset + ($length * 2) <= $count; $offset++) {
                $first = array_slice($words, $offset, $length);
                $second = array_slice($words, $offset + $length, $length);
                if (self::normalizeTurkishText(implode(' ', $first)) !== self::normalizeTurkishText(implode(' ', $second))) {
                    continue;
                }

                array_splice($words, $offset + $length, $length);

                return self::removeDuplicatePhrase(implode(' ', $words));
            }
        }

        return self::dedupeAdjacentWords($text);
    }

    public static function removeBelirtilmediTokens(?string $name): string
    {
        $text = trim((string) $name);
        foreach (self::TECHNICAL_TOKENS as $token) {
            $text = preg_replace('/\b' . preg_quote($token, '/') . '\b/iu', ' ', $text) ?? $text;
        }

        return self::normalizeWhitespace($text);
    }

    public static function removeCategoryPathTokens(?string $name): string
    {
        $text = trim((string) $name);
        if (str_contains($text, '>')) {
            $segments = array_values(array_filter(array_map('trim', explode('>', $text))));
            $text = end($segments) ?: '';
        }

        return self::normalizeWhitespace($text);
    }

    public static function removeSupplierTokens(?string $name): string
    {
        $text = trim((string) $name);
        foreach (self::SUPPLIER_TOKENS as $token) {
            $text = preg_replace('/\b' . preg_quote($token, '/') . '\b/iu', ' ', $text) ?? $text;
        }

        return self::normalizeWhitespace($text);
    }

    public static function normalizeWhitespace(?string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
    }

    public static function titleCaseTurkish(?string $value): string
    {
        $text = self::normalizeWhitespace($value);
        if ($text === '') {
            return '';
        }

        $text = Str::title(mb_strtolower($text));
        $text = str_replace(["i̇", "İ"], ['i', 'İ'], $text);

        foreach (['USB', 'VIP', 'DTF', 'UV', 'CMYK', 'LED', 'LCD', 'GB', 'A5', 'A4', 'PVC', 'Qi'] as $token) {
            $text = preg_replace('/\b' . preg_quote(Str::title(mb_strtolower($token)), '/') . '\b/u', $token, $text) ?? $text;
        }

        $text = preg_replace('/(\d)\s*[Xx]\s*(\d)/u', '$1x$2', $text) ?? $text;
        $text = preg_replace('/\bCm\b/u', 'cm', $text) ?? $text;
        $text = preg_replace('/\bMl\b/u', 'ml', $text) ?? $text;
        $text = preg_replace('/\bMah\b/u', 'mAh', $text) ?? $text;

        return self::normalizeWhitespace($text);
    }

    public static function buildSearchText(array $input): string
    {
        $parts = [
            $input['display_name'] ?? null,
            $input['display_code'] ?? null,
            $input['sku'] ?? null,
            $input['supplier_product_code'] ?? null,
            $input['supplier_variant_code'] ?? null,
            $input['supplier_group_code'] ?? null,
            $input['group_code'] ?? null,
            $input['raw_product_name'] ?? null,
            $input['normalized_product_name'] ?? null,
            $input['variant_name'] ?? null,
            $input['color'] ?? null,
            $input['category_name'] ?? null,
        ];

        return self::normalizeWhitespace(implode(' ', array_filter(array_map(
            fn ($value) => is_scalar($value) ? (string) $value : null,
            $parts
        ))));
    }

    public static function displayCode(?string $code): string
    {
        $cleanCode = self::normalizeCode($code);
        if (!self::isAkdenizCode($cleanCode)) {
            return $cleanCode;
        }

        $parts = explode('-', $cleanCode);
        if (count($parts) <= 2) {
            return $cleanCode;
        }

        $last = end($parts);
        if (!self::normalizeColor($last ?: null)) {
            return $cleanCode;
        }

        array_pop($parts);

        return implode('-', $parts);
    }

    private static function resolveBaseName(string $parentName, string $variantName): string
    {
        if ($parentName === '') {
            return $variantName;
        }

        if ($variantName === '') {
            return $parentName;
        }

        if (self::containsWhole($variantName, $parentName)) {
            return $variantName;
        }

        if (self::containsWhole($parentName, $variantName)) {
            return $parentName;
        }

        return trim($parentName . ' ' . $variantName);
    }

    private static function cleanPhrase(?string $value, array $codes = []): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = str_replace(['_', '/', '\\'], ' ', $text);
        $text = self::removeCategoryPathTokens($text);
        $text = self::removeBelirtilmediTokens($text);
        $text = self::removeSupplierTokens($text);
        $text = preg_replace('/\s*-\s*/u', '-', $text) ?? $text;

        foreach (array_filter(array_unique($codes)) as $code) {
            $quoted = preg_quote($code, '/');
            $text = preg_replace('/(?<![A-Z0-9])' . $quoted . '(?![A-Z0-9])/iu', ' ', $text) ?? $text;
        }

        $text = preg_replace('/\b([A-ZÇĞİÖŞÜ]{2,}-[A-ZÇĞİÖŞÜ0-9-]+)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b([0-9A-ZÇĞİÖŞÜ]+-[0-9A-ZÇĞİÖŞÜ-]+)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/(^|\s)-[A-ZÇĞİÖŞÜ0-9]+(?=\s|$)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        if ($text === '') {
            return '';
        }

        return self::removeDuplicatePhrase(self::titleCaseTurkish($text));
    }

    private static function normalizeCode(?string $code): string
    {
        return trim((string) $code);
    }

    private static function containsWhole(string $haystack, ?string $needle): bool
    {
        $needle = trim((string) $needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return str_contains(self::normalizeTurkishText($haystack), self::normalizeTurkishText($needle));
    }

    private static function normalizeColor(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $key = self::normalizeTurkishText((string) $value);

        return self::COLOR_MAP[$key] ?? null;
    }

    private static function removeAllColors(string $name): string
    {
        $text = $name;
        foreach (self::COLOR_MAP as $key => $label) {
            $text = preg_replace('/(^|\s)' . preg_quote($label, '/') . '(\s|$)/iu', ' ', $text) ?? $text;
            $text = preg_replace('/(^|\s)' . preg_quote($key, '/') . '(\s|$)/iu', ' ', $text) ?? $text;
        }

        return self::normalizeWhitespace($text);
    }

    private static function dedupeAdjacentWords(string $name): string
    {
        $words = preg_split('/\s+/u', $name) ?: [];
        $deduped = [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $last = end($deduped);
            if ($last !== false && self::normalizeTurkishText((string) $last) === self::normalizeTurkishText($word)) {
                continue;
            }

            $deduped[] = $word;
        }

        return self::normalizeWhitespace(implode(' ', $deduped));
    }

    private static function isAkdenizCode(?string $code): bool
    {
        return str_starts_with(self::normalizeTurkishText($code), 'AK-');
    }

    private static function akdenizCatalogCodeToken(?string $code): ?string
    {
        if (!self::isAkdenizCode($code)) {
            return null;
        }

        $parts = explode('-', self::displayCode($code));
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^\d{3,}$/', $part)) {
                return $part;
            }
        }

        return null;
    }

    private static function looksLikeSupplier(mixed $value, string $needle): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        return str_contains(mb_strtolower((string) $value), $needle);
    }

    private static function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function detectCleanupWarnings(string $displayName, string $code, string $sku, array $input): array
    {
        $warnings = [];
        $normalizedDisplay = self::normalizeTurkishText($displayName);
        $displayCode = self::displayCode($code);

        if ($code !== '' && substr_count($normalizedDisplay, self::normalizeTurkishText($code)) > 1) {
            $warnings[] = 'repeated_code';
        }

        if ($displayCode !== '' && $displayCode !== $code && substr_count($normalizedDisplay, self::normalizeTurkishText($displayCode)) > 1) {
            $warnings[] = 'repeated_display_code';
        }

        $color = self::extractColorFromCode($code) ?: self::normalizeColor($input['color'] ?? null);
        if ($color && substr_count($normalizedDisplay, self::normalizeTurkishText($color)) > 1) {
            $warnings[] = 'repeated_color';
        }

        foreach (self::TECHNICAL_TOKENS as $token) {
            if (str_contains(mb_strtolower($displayName), $token)) {
                $warnings[] = 'technical_token';
                break;
            }
        }

        if (str_contains($displayName, '>') || str_contains($displayName, ' / ')) {
            $warnings[] = 'category_path_leak';
        }

        $groupCode = (string) ($input['supplier_group_code'] ?? $input['group_code'] ?? '');
        $displayWithoutPrimaryCode = self::normalizeTurkishText(str_replace([$code, $displayCode, $sku], ' ', $displayName));
        if ($groupCode !== '' && $groupCode !== $displayCode && str_contains($displayWithoutPrimaryCode, self::normalizeTurkishText($groupCode))) {
            $warnings[] = 'group_code_visible';
        }

        if (mb_strlen($displayName) > 110) {
            $warnings[] = 'too_long';
        }

        return array_values(array_unique($warnings));
    }
}
