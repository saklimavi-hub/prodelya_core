<?php

namespace App\Console\Commands;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Support\ProductDisplayNameFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ProductDataHubAuditProductDisplayNamesCommand extends Command
{
    protected $signature = 'product-data-hub:audit-product-display-names
        {--source= : Supplier source ID}
        {--supplier= : Supplier code or name}
        {--limit=50 : Example row limit}
        {--examples : Show example rows}
        {--only-problems : Show only critical/review rows in examples}
        {--export : Export CSV and JSON audit examples}';

    protected $description = 'Product Data Hub ürün görünen adlarını kritik/review/kabul edilebilir seviyesinde audit eder; veri değiştirmez.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $supplierFilter = trim((string) $this->option('supplier'));
        $sourceFilter = trim((string) $this->option('source'));
        $rows = collect();
        $stats = $this->emptyStats();
        $supplierStats = [];

        $this->auditStandardProducts($supplierFilter, $sourceFilter, $stats, $supplierStats, $rows);
        $this->auditStandardVariants($supplierFilter, $sourceFilter, $stats, $supplierStats, $rows);

        $this->info('Product Data Hub ürün adı audit tamamlandı; veri değiştirilmedi.');
        $this->table(['Metrik', 'Sayı'], [
            ['Toplam ürün/varyant', $stats['total']],
            ['Kritik hata', $stats['critical_count']],
            ['Review gerekli', $stats['review_count']],
            ['Kabul edilebilir aday', $stats['acceptable_candidate_count']],
            ['Temiz', $stats['clean_count']],
        ]);

        $this->line('Tedarikçi bazlı sınıflandırma:');
        $this->table(
            ['Tedarikçi', 'Toplam', 'Kritik', 'Review', 'Kabul Edilebilir', 'Temiz'],
            collect($supplierStats)->sortKeys()->map(fn (array $row, string $supplier) => [
                $supplier,
                $row['total'] ?? 0,
                $row['critical_count'] ?? 0,
                $row['review_count'] ?? 0,
                $row['acceptable_candidate_count'] ?? 0,
                $row['clean_count'] ?? 0,
            ])->values()->all()
        );

        if ($this->option('examples')) {
            $exampleRows = $this->option('only-problems')
                ? $rows->whereIn('issue_class', ['critical', 'review'])
                : $rows->whereIn('issue_class', ['critical', 'review', 'acceptable']);

            $this->line('Örnek audit satırları:');
            $this->table(
                ['Tedarikçi', 'Sınıf', 'Kod/SKU', 'Eski Ad', 'Yeni Ad', 'Neden'],
                $exampleRows
                    ->sortBy([
                        fn (array $row) => match ($row['issue_class']) {
                            'critical' => 0,
                            'review' => 1,
                            'acceptable' => 2,
                            default => 3,
                        },
                        'supplier',
                    ])
                    ->take($limit)
                    ->map(fn (array $row) => [
                        $row['supplier'],
                        $row['issue_class'],
                        $row['sku'],
                        $row['old_display_name'],
                        $row['new_display_name'],
                        $row['issue_reason'],
                    ])->values()->all()
            );
        }

        if ($this->option('export')) {
            $this->exportRows($rows, $stats, $supplierStats);
        }

        return self::SUCCESS;
    }

    private function auditStandardProducts(
        string $supplierFilter,
        string $sourceFilter,
        array &$stats,
        array &$supplierStats,
        Collection $rows
    ): void {
        $query = StandardProduct::query()->with('supplier');
        $this->applyProductFilters($query, $supplierFilter, $sourceFilter);

        $query->orderBy('id')->chunkById(500, function ($products) use (&$stats, &$supplierStats, $rows) {
            foreach ($products as $product) {
                $sourceRow = (array) data_get($product->source_summary, '0', []);
                $supplier = $this->canonicalSupplierName($product->supplier?->name ?: 'Supplier #' . ($product->supplier_id ?: '-'));
                $input = [
                    'supplier_name' => $supplier,
                    'product_code' => $product->standard_product_code ?: $product->sku,
                    'sku' => $product->sku,
                    'supplier_product_code' => $sourceRow['supplier_product_code'] ?? null,
                    'supplier_group_code' => $sourceRow['supplier_group_code'] ?? null,
                    'supplier_source_id' => $sourceRow['supplier_source_id'] ?? null,
                    'raw_product_name' => $product->product_name ?: $product->base_product_name ?: $product->name,
                    'normalized_product_name' => data_get($product->meta, 'normalized_payload.product_name'),
                    'category_name' => $product->category_name ?: $product->category,
                ];

                $this->recordAuditRow('standard_product', $supplier, $input, $stats, $supplierStats, $rows);
            }
        });
    }

    private function auditStandardVariants(
        string $supplierFilter,
        string $sourceFilter,
        array &$stats,
        array &$supplierStats,
        Collection $rows
    ): void {
        $query = StandardProductVariant::query()->with('standardProduct.supplier');
        if ($supplierFilter !== '') {
            $query->whereHas('standardProduct.supplier', function ($supplierQuery) use ($supplierFilter) {
                $supplierQuery->where('code', 'like', '%' . $supplierFilter . '%')
                    ->orWhere('name', 'like', '%' . $supplierFilter . '%');
            });
        }
        if ($sourceFilter !== '') {
            $query->where('source_summary', 'like', '%"supplier_source_id":' . (int) $sourceFilter . '%');
        }

        $query->orderBy('id')->chunkById(500, function ($variants) use (&$stats, &$supplierStats, $rows) {
            foreach ($variants as $variant) {
                $product = $variant->standardProduct;
                $supplier = $this->canonicalSupplierName(
                    $product?->supplier?->name
                    ?: 'Supplier #' . ($product?->supplier_id ?: data_get($variant->source_summary, 'supplier_id', '-'))
                );
                $input = [
                    'supplier_name' => $supplier,
                    'product_code' => $variant->generated_variant_code ?: $variant->variant_code,
                    'sku' => $variant->generated_variant_code ?: $variant->variant_code,
                    'supplier_product_code' => data_get($variant->source_summary, 'variant_stock_code'),
                    'supplier_group_code' => data_get($variant->source_summary, 'supplier_group_code', data_get($product?->source_summary, '0.supplier_group_code')),
                    'supplier_source_id' => data_get($variant->source_summary, 'supplier_source_id', data_get($product?->source_summary, '0.supplier_source_id')),
                    'raw_product_name' => $product?->base_product_name ?: $product?->product_name ?: $product?->name,
                    'variant_name' => $variant->variant_name,
                    'color' => $variant->variant_color,
                    'size' => $variant->variant_size,
                    'measure' => data_get($variant->variant_attributes, 'measure'),
                    'capacity' => data_get($variant->variant_attributes, 'capacity'),
                    'option' => data_get($variant->variant_attributes, 'option'),
                    'extra_codes' => [
                        $variant->variant_code,
                        $product?->standard_product_code,
                        $product?->sku,
                    ],
                ];

                $this->recordAuditRow('variant', $supplier, $input, $stats, $supplierStats, $rows);
            }
        });
    }

    private function applyProductFilters($query, string $supplierFilter, string $sourceFilter): void
    {
        if ($supplierFilter !== '') {
            $query->whereHas('supplier', function ($supplierQuery) use ($supplierFilter) {
                $supplierQuery->where('code', 'like', '%' . $supplierFilter . '%')
                    ->orWhere('name', 'like', '%' . $supplierFilter . '%');
            });
        }

        if ($sourceFilter !== '') {
            $query->where('source_summary', 'like', '%"supplier_source_id":' . (int) $sourceFilter . '%');
        }
    }

    private function recordAuditRow(
        string $type,
        string $supplier,
        array $input,
        array &$stats,
        array &$supplierStats,
        Collection $rows
    ): void {
        $formatted = ProductDisplayNameFormatter::format($input);
        $current = ProductDisplayNameFormatter::normalizeWhitespace(implode(' ', array_filter([
            $input['product_code'] ?? null,
            $input['raw_product_name'] ?? null,
            $input['variant_name'] ?? null,
            $input['color'] ?? null,
        ])));
        $changed = ProductDisplayNameFormatter::normalizeTurkishText($current) !== ProductDisplayNameFormatter::normalizeTurkishText($formatted['display_name']);
        $currentIssues = $this->detectCurrentIssues($current, $input, $formatted);
        $criticalReasons = $this->detectCriticalReasons($formatted['display_name'], $input, $formatted);
        $reviewReasons = $criticalReasons === [] ? $this->detectReviewReasons($formatted['display_name'], $input, $formatted) : [];

        $issueClass = match (true) {
            $criticalReasons !== [] => 'critical',
            $reviewReasons !== [] => 'review',
            $currentIssues !== [] || $changed => 'acceptable',
            default => 'clean',
        };

        $reasons = match ($issueClass) {
            'critical' => $criticalReasons,
            'review' => $reviewReasons,
            'acceptable' => $this->detectAcceptableReasons($currentIssues, $changed),
            default => ['clean_display_name'],
        };

        $row = [
            'type' => $type,
            'supplier' => $supplier,
            'source_id' => $input['supplier_source_id'] ?? null,
            'sku' => $formatted['sku'],
            'group_code' => (string) ($input['supplier_group_code'] ?? ''),
            'raw_name' => (string) ($input['raw_product_name'] ?? ''),
            'raw_variant' => (string) ($input['variant_name'] ?? ''),
            'raw_color' => (string) ($input['color'] ?? ''),
            'old_display_name' => $current,
            'new_display_name' => $formatted['display_name'],
            'search_text' => $formatted['search_text'],
            'detected_color' => ProductDisplayNameFormatter::extractColorFromCode((string) ($input['product_code'] ?? ''))
                ?: ProductDisplayNameFormatter::extractColorFromName($formatted['display_name'])
                ?: ProductDisplayNameFormatter::extractColorFromName($current),
            'display_code' => $formatted['display_code'],
            'issue_class' => $issueClass,
            'issue_reason' => implode('; ', $reasons),
            'reason_codes' => $reasons,
            'current_issue_codes' => $currentIssues,
            'changed_by_formatter' => $changed,
        ];

        $rows->push($row);
        $this->bumpStats($stats, $issueClass);
        $supplierStats[$supplier] ??= $this->emptyStats();
        $this->bumpStats($supplierStats[$supplier], $issueClass);
    }

    private function detectCurrentIssues(string $current, array $input, array $formatted): array
    {
        $issues = [];
        $code = (string) ($input['product_code'] ?? '');
        $displayCode = (string) ($formatted['display_code'] ?? '');
        $groupCode = (string) ($input['supplier_group_code'] ?? '');
        $codeColor = ProductDisplayNameFormatter::extractColorFromCode($code);
        $allColors = $this->extractAllColors($current);

        if ($this->countWholeOccurrences($current, $code) > 1 || ($displayCode !== $code && $this->countWholeOccurrences($current, $displayCode) > 1)) {
            $issues[] = 'duplicate_code';
        }

        if ($codeColor && $this->countWholeOccurrences($current, $codeColor) > 1) {
            $issues[] = 'duplicate_color';
        }

        if ($codeColor && collect($allColors)->contains(fn (string $color) => !$this->sameText($color, $codeColor))) {
            $issues[] = 'wrong_color';
        }

        if ($this->containsGroupCodeLeak($current, $groupCode, $code, $displayCode, (string) ($formatted['sku'] ?? ''))) {
            $issues[] = 'group_code_visible';
        }

        if ($this->looksLikeCategoryPath($current)) {
            $issues[] = 'category_path_leak';
        }

        if ($this->containsSupplierToken($current)) {
            $issues[] = 'supplier_leak';
        }

        if ($this->containsTechnicalToken($current)) {
            $issues[] = 'technical_token';
        }

        return array_values(array_unique($issues));
    }

    private function detectCriticalReasons(string $displayName, array $input, array $formatted): array
    {
        $reasons = [];
        $code = (string) ($input['product_code'] ?? '');
        $displayCode = (string) ($formatted['display_code'] ?? '');
        $groupCode = (string) ($input['supplier_group_code'] ?? '');
        $sku = (string) ($formatted['sku'] ?? '');
        $codeColor = ProductDisplayNameFormatter::extractColorFromCode($code);
        $displayColors = $this->extractAllColors($displayName);
        $body = trim($this->removeKnownTokens($displayName, array_filter([$code, $displayCode, $sku, $codeColor])));

        if ($this->countWholeOccurrences($displayName, $code) > 1 || ($displayCode !== $code && $this->countWholeOccurrences($displayName, $displayCode) > 1)) {
            $reasons[] = 'duplicate_code_visible';
        }

        if ($codeColor && $this->countWholeOccurrences($displayName, $codeColor) > 1) {
            $reasons[] = 'duplicate_color_visible';
        }

        if ($codeColor && collect($displayColors)->contains(fn (string $color) => !$this->sameText($color, $codeColor))) {
            $reasons[] = 'wrong_color_visible';
        }

        if ($this->containsGroupCodeLeak($displayName, $groupCode, $code, $displayCode, $sku)) {
            $reasons[] = 'group_code_visible';
        }

        if ($this->looksLikeCategoryPath($displayName)) {
            $reasons[] = 'category_path_leak';
        }

        if ($this->containsSupplierToken($displayName)) {
            $reasons[] = 'supplier_name_leak';
        }

        if ($this->containsTechnicalToken($displayName)) {
            $reasons[] = 'technical_token_visible';
        }

        if ($body === '' || mb_strlen(preg_replace('/[^\p{L}\p{N}]+/u', '', $body) ?? '') < 3) {
            $reasons[] = 'display_name_unreadable';
        }

        return array_values(array_unique($reasons));
    }

    private function detectReviewReasons(string $displayName, array $input, array $formatted): array
    {
        $reasons = [];
        $wordCount = count(array_filter(preg_split('/\s+/u', trim($displayName)) ?: []));
        $hasDetailMarkers = str_contains($displayName, '(')
            || str_contains($displayName, ')')
            || str_contains($displayName, ' / ')
            || preg_match('/\d+\s*x\s*\d+/iu', $displayName) === 1;
        $rawVariant = trim((string) ($input['variant_name'] ?? ''));

        if (mb_strlen($displayName) >= 95 || ($wordCount >= 12 && $hasDetailMarkers)) {
            $reasons[] = 'name_needs_human_length_review';
        }

        if ($rawVariant !== '' && !$this->sameText($rawVariant, $displayName) && $wordCount >= 10) {
            $reasons[] = 'variant_detail_should_be_checked';
        }

        return array_values(array_unique($reasons));
    }

    private function detectAcceptableReasons(array $currentIssues, bool $changed): array
    {
        if ($currentIssues !== []) {
            return ['formatter_cleaned_previous_candidate'];
        }

        if ($changed) {
            return ['formatter_normalized_readable_display_name'];
        }

        return ['acceptable_candidate'];
    }

    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'critical_count' => 0,
            'review_count' => 0,
            'acceptable_candidate_count' => 0,
            'clean_count' => 0,
        ];
    }

    private function bumpStats(array &$stats, string $issueClass): void
    {
        $stats['total'] = ($stats['total'] ?? 0) + 1;

        match ($issueClass) {
            'critical' => $stats['critical_count'] = ($stats['critical_count'] ?? 0) + 1,
            'review' => $stats['review_count'] = ($stats['review_count'] ?? 0) + 1,
            'acceptable' => $stats['acceptable_candidate_count'] = ($stats['acceptable_candidate_count'] ?? 0) + 1,
            default => $stats['clean_count'] = ($stats['clean_count'] ?? 0) + 1,
        };
    }

    private function exportRows(Collection $rows, array $stats, array $supplierStats): void
    {
        $directory = storage_path('app/product-data-hub/display-name-audit');
        File::ensureDirectoryExists($directory);

        $summaryPath = $directory . DIRECTORY_SEPARATOR . 'display_name_audit_summary.csv';
        $criticalPath = $directory . DIRECTORY_SEPARATOR . 'display_name_audit_critical.csv';
        $reviewPath = $directory . DIRECTORY_SEPARATOR . 'display_name_audit_review.csv';
        $examplesPath = $directory . DIRECTORY_SEPARATOR . 'display_name_audit_examples.json';

        File::put($summaryPath, $this->buildSummaryCsv($stats, $supplierStats));
        File::put($criticalPath, $this->buildRowCsv($rows->where('issue_class', 'critical')->values()));
        File::put($reviewPath, $this->buildRowCsv($rows->where('issue_class', 'review')->values()));
        File::put($examplesPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => $stats['total'],
                'critical_count' => $stats['critical_count'],
                'review_count' => $stats['review_count'],
                'acceptable_candidate_count' => $stats['acceptable_candidate_count'],
                'clean_count' => $stats['clean_count'],
            ],
            'supplier_summary' => collect($supplierStats)->sortKeys()->map(fn (array $row, string $supplier) => [
                'supplier' => $supplier,
                'total' => $row['total'] ?? 0,
                'critical_count' => $row['critical_count'] ?? 0,
                'review_count' => $row['review_count'] ?? 0,
                'acceptable_candidate_count' => $row['acceptable_candidate_count'] ?? 0,
                'clean_count' => $row['clean_count'] ?? 0,
            ])->values()->all(),
            'supplier_examples' => $this->buildSupplierExamples($rows),
            'akdeniz_detailed_audit' => $this->buildAkdenizDetailedAudit($rows),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line('Summary export: ' . $summaryPath);
        $this->line('Critical export: ' . $criticalPath);
        $this->line('Review export: ' . $reviewPath);
        $this->line('Examples export: ' . $examplesPath);
    }

    private function buildSummaryCsv(array $stats, array $supplierStats): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['supplier', 'total', 'critical_count', 'review_count', 'acceptable_candidate_count', 'clean_count']);
        fputcsv($handle, [
            'TOTAL',
            $stats['total'],
            $stats['critical_count'],
            $stats['review_count'],
            $stats['acceptable_candidate_count'],
            $stats['clean_count'],
        ]);

        foreach (collect($supplierStats)->sortKeys() as $supplier => $row) {
            fputcsv($handle, [
                $supplier,
                $row['total'] ?? 0,
                $row['critical_count'] ?? 0,
                $row['review_count'] ?? 0,
                $row['acceptable_candidate_count'] ?? 0,
                $row['clean_count'] ?? 0,
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function buildRowCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'supplier',
            'source_id',
            'sku',
            'group_code',
            'raw_name',
            'old_display_name',
            'new_display_name',
            'issue_class',
            'issue_reason',
            'detected_color',
            'display_code',
            'search_text_sample',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['supplier'],
                $row['source_id'],
                $row['sku'],
                $row['group_code'],
                $row['raw_name'],
                $row['old_display_name'],
                $row['new_display_name'],
                $row['issue_class'],
                $row['issue_reason'],
                $row['detected_color'],
                $row['display_code'],
                $row['search_text'],
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function buildSupplierExamples(Collection $rows): array
    {
        return $rows
            ->groupBy('supplier')
            ->map(function (Collection $supplierRows, string $supplier) {
                $critical = $supplierRows->where('issue_class', 'critical')->take(20)->values();
                $review = $supplierRows->where('issue_class', 'review')->take(20)->values();

                return [
                    'supplier' => $supplier,
                    'critical_examples' => $critical->map(fn (array $row) => $this->jsonExampleRow($row))->all(),
                    'review_examples' => $review->map(fn (array $row) => $this->jsonExampleRow($row))->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    private function buildAkdenizDetailedAudit(Collection $rows): array
    {
        $akdenizRows = $rows->where('supplier', 'Akdeniz')->values();
        $picked = collect();
        $preferredMatchers = [
            fn (array $row) => str_contains((string) $row['sku'], 'AK-1020'),
            fn (array $row) => str_contains((string) $row['sku'], 'AK-3008-11'),
            fn (array $row) => str_contains((string) $row['sku'], 'AK-YMN-224'),
            fn (array $row) => str_contains((string) $row['sku'], 'PB-4007'),
            fn (array $row) => $this->containsAny((string) $row['new_display_name'], ['Çakı', 'Kalem', 'Çakmak', 'Termos', 'Mousepad', 'Saat']),
        ];

        foreach ($preferredMatchers as $matcher) {
            $matches = $akdenizRows
                ->filter($matcher)
                ->reject(fn (array $row) => $picked->contains('sku', $row['sku']))
                ->take(25);

            $picked = $picked->concat($matches)->values();
        }

        if ($picked->count() < 100) {
            $picked = $picked->concat(
                $akdenizRows
                    ->reject(fn (array $row) => $picked->contains('sku', $row['sku']))
                    ->take(100 - $picked->count())
            )->values();
        }

        return $picked
            ->take(100)
            ->map(fn (array $row) => $this->jsonExampleRow($row))
            ->all();
    }

    private function jsonExampleRow(array $row): array
    {
        return [
            'raw_sku' => $row['sku'],
            'raw_product_name' => $row['raw_name'],
            'raw_variant_color' => trim(implode(' ', array_filter([$row['raw_variant'], $row['raw_color']]))),
            'old_display_name' => $row['old_display_name'],
            'new_display_name' => $row['new_display_name'],
            'search_text' => $row['search_text'],
            'detected_color' => $row['detected_color'],
            'display_code' => $row['display_code'],
            'issue_class' => $row['issue_class'],
            'issue_reason' => $row['issue_reason'],
            'group_code' => $row['group_code'],
            'source_id' => $row['source_id'],
            'type' => $row['type'],
        ];
    }

    private function canonicalSupplierName(string $supplier): string
    {
        $normalized = ProductDisplayNameFormatter::normalizeTurkishText($supplier);

        return match (true) {
            str_contains($normalized, 'AKDENIZ') => 'Akdeniz',
            str_contains($normalized, 'ETKIN') => 'Etkin',
            str_contains($normalized, 'ILPEN') => 'İlpen',
            str_contains($normalized, 'YENI NESIL') => 'Yeni Nesil',
            default => $supplier,
        };
    }

    private function looksLikeCategoryPath(string $value): bool
    {
        return str_contains($value, '>')
            || str_contains($value, ' / ')
            || str_contains(mb_strtolower($value), 'category path')
            || str_contains(mb_strtolower($value), 'supplier category');
    }

    private function containsSupplierToken(string $value): bool
    {
        return $this->containsAny($value, ['Akdeniz Promosyon', 'Etkin Promosyon', 'İlpen', 'Yeni Nesil']);
    }

    private function containsTechnicalToken(string $value): bool
    {
        return $this->containsAny($value, ['Belirtilmedi', 'null', 'undefined']);
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->containsWholeText($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsGroupCodeLeak(string $value, string $groupCode, string $code, string $displayCode, string $sku): bool
    {
        $groupCode = trim($groupCode);
        if ($groupCode === '' || $groupCode === $displayCode || $groupCode === $code || $groupCode === $sku) {
            return false;
        }

        $body = $this->removeKnownTokens($value, array_filter([$code, $displayCode, $sku]));

        return $this->containsWholeText($body, $groupCode);
    }

    private function countWholeOccurrences(string $value, string $needle): int
    {
        $needle = trim($needle);
        if ($needle === '') {
            return 0;
        }

        preg_match_all(
            '/(^|[^[:alnum:]])' . preg_quote($needle, '/') . '([^[:alnum:]]|$)/iu',
            ' ' . $value . ' ',
            $matches
        );

        return count($matches[0] ?? []);
    }

    private function containsWholeText(string $value, string $needle): bool
    {
        return $this->countWholeOccurrences($value, $needle) > 0;
    }

    private function sameText(?string $left, ?string $right): bool
    {
        return ProductDisplayNameFormatter::normalizeTurkishText($left) === ProductDisplayNameFormatter::normalizeTurkishText($right);
    }

    private function extractAllColors(string $value): array
    {
        $colors = [];
        foreach ([
            'Siyah', 'Beyaz', 'Kırmızı', 'Lacivert', 'Mavi', 'Yeşil', 'Sarı', 'Turuncu', 'Gri',
            'Gümüş', 'Altın', 'Bordo', 'Pembe', 'Mor', 'Kahverengi', 'Taba', 'Füme', 'Şeffaf', 'Krem', 'Turkuaz',
        ] as $color) {
            if ($this->containsWholeText($value, $color)) {
                $colors[] = $color;
            }
        }

        return array_values(array_unique($colors));
    }

    private function removeKnownTokens(string $value, array $tokens): string
    {
        $result = $value;

        foreach ($tokens as $token) {
            if (!is_string($token) || trim($token) === '') {
                continue;
            }

            $result = preg_replace(
                '/(^|[^[:alnum:]])' . preg_quote($token, '/') . '([^[:alnum:]]|$)/iu',
                ' ',
                $result
            ) ?? $result;
        }

        return ProductDisplayNameFormatter::normalizeWhitespace($result);
    }
}
