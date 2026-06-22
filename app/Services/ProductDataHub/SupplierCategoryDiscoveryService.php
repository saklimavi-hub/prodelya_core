<?php

namespace App\Services\ProductDataHub;

use App\Models\CategoryAlias;
use App\Models\ProductCategorySuggestionLog;
use App\Models\StandardCategory;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierSource;
use Illuminate\Support\Collection;

class SupplierCategoryDiscoveryService
{
    private const HIGH_CONFIDENCE_THRESHOLD = 90.0;
    private const REVIEW_THRESHOLD = 70.0;
    private const SAFE_AUTO_APPROVE_THRESHOLD = 95.0;

    public function __construct(
        private readonly SourceFetchService $fetchService,
        private readonly SourceParserService $parserService,
        private readonly PreviewParserService $previewParser,
        private readonly CategorySignalNormalizer $signalNormalizer,
        private readonly CategorySuggestionScoreService $scoreService,
        private readonly ProductImageAnalyzerInterface $imageAnalyzer
    ) {
    }

    public function scanAllActiveSources(bool $persist = true): array
    {
        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->orderBy('id')
            ->get();

        $results = $sources->map(fn (SupplierSource $source) => $this->scanSource($source, persist: $persist));

        return [
            'sources' => $results->values()->all(),
            'totals' => [
                'supplier_count' => $results->count(),
                'supplier_category_count' => (int) $results->sum('summary.category_count'),
                'mapped_count' => (int) $results->sum('summary.mapped_count'),
                'pending_count' => (int) $results->sum('summary.pending_count'),
                'high_confidence_count' => (int) $results->sum('summary.high_confidence_count'),
                'review_count' => (int) $results->sum('summary.review_count'),
                'conflict_count' => (int) $results->sum('summary.conflict_count'),
                'alias_candidate_count' => (int) $results->sum('summary.alias_candidate_count'),
                'twin_view_candidate_count' => (int) $results->sum('summary.twin_view_candidate_count'),
                'merge_candidate_count' => (int) $results->sum('summary.merge_candidate_count'),
                'filter_candidate_count' => (int) $results->sum('summary.filter_candidate_count'),
            ],
        ];
    }

    public function scanSource(SupplierSource $source, ?array $parsedRows = null, bool $persist = true): array
    {
        [$rows, $sourceMode, $errors, $warnings] = $this->resolveRows($source, $parsedRows);
        $preview = $this->previewParser->previewSource($source, $rows);
        $items = $this->resolveCategoryItems($preview);
        $standardCategories = StandardCategory::query()
            ->permanentBackbone()
            ->orderBy('path')
            ->get();
        $aliases = CategoryAlias::query()
            ->with('standardCategory')
            ->where('is_active', true)
            ->get();
        $historicalMappings = SupplierCategoryMapping::query()
            ->with('standardCategory')
            ->where('supplier_id', $source->supplier_id)
            ->whereNotNull('standard_category_id')
            ->get();
        $aliases = $aliases
            ->filter(fn (CategoryAlias $alias) => $alias->standardCategory && $alias->standardCategory->isPermanentBackbone())
            ->values();
        $historicalMappings = $historicalMappings
            ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->standardCategory && $mapping->standardCategory->isPermanentBackbone())
            ->values();

        $categories = $this->aggregateCategories(
            $source,
            $items,
            $standardCategories,
            $aliases,
            $historicalMappings
        );

        if ($persist) {
            $this->persistCategories($source, $categories);
        }

        return [
            'source' => $source,
            'source_mode' => $sourceMode,
            'warnings' => $warnings,
            'errors' => $errors,
            'categories' => array_values($categories),
            'summary' => $this->buildSummary($categories),
        ];
    }

    public function autoApproveHighConfidence(?int $supplierId = null): array
    {
        $query = SupplierCategoryMapping::query()
            ->with('standardCategory')
            ->whereNotNull('standard_category_id')
            ->whereNotNull('confidence_score')
            ->where('confidence_score', '>=', self::SAFE_AUTO_APPROVE_THRESHOLD)
            ->whereIn('decision_type', ['map', 'alias'])
            ->where('suggestion_meta->safe_auto_approve', true)
            ->where(function ($query) {
                $query->whereNull('suggestion_meta->review_required')
                    ->orWhere('suggestion_meta->review_required', false);
            })
            ->whereIn('mapping_status', ['pending', 'needs_review', 'auto_approved']);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $approved = 0;
        $aliasCreated = 0;

        $query->get()->each(function (SupplierCategoryMapping $mapping) use (&$approved, &$aliasCreated) {
            if (!$mapping->standardCategory?->isPermanentBackbone()) {
                return;
            }

            $oldStandardCategoryId = $mapping->standard_category_id;

            $mapping->update([
                'mapping_status' => 'approved',
                'decision_type' => $mapping->decision_type ?: 'map',
                'reviewed_at' => now(),
            ]);

            SupplierCategoryMappingLog::query()->create([
                'mapping_id' => $mapping->id,
                'old_standard_category_id' => $oldStandardCategoryId,
                'new_standard_category_id' => $mapping->standard_category_id,
                'action' => 'approved',
                'reason' => 'Safe auto approve: yeni kalıcı kategori ağacı yüksek güvenli önerisi.',
                'changed_by' => auth()->id(),
            ]);

            if (($mapping->decision_type === 'alias' || data_get($mapping->suggestion_meta, 'alias_candidate') === true)
                && $mapping->standard_category_id
                && filled($mapping->source_category)
            ) {
                CategoryAlias::query()->updateOrCreate(
                    [
                        'standard_category_id' => $mapping->standard_category_id,
                        'supplier_id' => $mapping->supplier_id,
                        'normalized_alias' => $this->normalizeText($mapping->source_category),
                    ],
                    [
                        'alias_name' => $mapping->source_category,
                        'source_type' => 'auto',
                        'confidence_score' => $mapping->confidence_score,
                        'is_active' => true,
                    ]
                );
                $aliasCreated++;
            }

            $approved++;
        });

        return [
            'approved' => $approved,
            'alias_created' => $aliasCreated,
        ];
    }

    public function applySafeCategorySuggestions(Collection $results): array
    {
        $approved = 0;
        $aliasCreated = 0;
        $skipped = 0;

        foreach ($results as $result) {
            /** @var SupplierSource $source */
            $source = $result['source'];

            foreach (($result['categories'] ?? []) as $category) {
                if (!$this->isSafeAutoApproveSuggestion($category)) {
                    $skipped++;
                    continue;
                }

                $target = StandardCategory::query()->find($category['standard_category_id']);
                if (!$target?->isPermanentBackbone()) {
                    $skipped++;
                    continue;
                }

                $mapping = SupplierCategoryMapping::query()->firstOrNew([
                    'supplier_source_id' => $source->id,
                    'source_category' => $category['source_category'],
                ]);
                $oldStandardCategoryId = $mapping->standard_category_id;
                $alreadyApproved = in_array($mapping->mapping_status, ['approved', 'mapped'], true)
                    && (int) $mapping->standard_category_id === (int) $target->id;

                $mapping->fill([
                    'supplier_id' => $source->supplier_id,
                    'supplier_category_code' => $category['supplier_category_code'],
                    'supplier_category_path' => $category['supplier_category_path'],
                    'supplier_category_level' => $category['supplier_category_level'],
                    'normalized_name' => $category['normalized_name'],
                    'product_count' => $category['product_count'],
                    'sample_product_names' => $category['sample_product_names'],
                    'sample_image_urls' => $category['sample_image_urls'],
                    'suggestion_meta' => $category['suggestion_meta'],
                    'standard_category_id' => $target->id,
                    'target_category' => $target->full_path,
                    'mapping_status' => 'approved',
                    'decision_type' => $category['decision_type'] ?: 'map',
                    'description' => $category['suggestion_reason_text'],
                    'confidence_score' => $category['confidence_score'],
                    'is_active' => true,
                    'reviewed_at' => now(),
                    'last_scanned_at' => now(),
                ]);
                $mapping->save();

                if (!$alreadyApproved) {
                    SupplierCategoryMappingLog::query()->create([
                        'mapping_id' => $mapping->id,
                        'old_standard_category_id' => $oldStandardCategoryId,
                        'new_standard_category_id' => $mapping->standard_category_id,
                        'action' => 'approved',
                        'reason' => 'Safe category mapping apply: yeni kalıcı kategori ağacı yüksek güvenli önerisi.',
                        'changed_by' => auth()->id(),
                    ]);
                }

                if (($mapping->decision_type === 'alias' || data_get($mapping->suggestion_meta, 'alias_candidate') === true)
                    && $mapping->standard_category_id
                    && filled($mapping->source_category)
                ) {
                    CategoryAlias::query()->updateOrCreate(
                        [
                            'standard_category_id' => $mapping->standard_category_id,
                            'supplier_id' => $mapping->supplier_id,
                            'normalized_alias' => $this->normalizeText($mapping->source_category),
                        ],
                        [
                            'alias_name' => $mapping->source_category,
                            'source_type' => 'auto',
                            'confidence_score' => $mapping->confidence_score,
                            'is_active' => true,
                        ]
                    );
                    $aliasCreated++;
                }

                $approved++;
            }
        }

        return [
            'approved' => $approved,
            'alias_created' => $aliasCreated,
            'skipped' => $skipped,
        ];
    }

    private function isSafeAutoApproveSuggestion(array $category): bool
    {
        return data_get($category, 'suggestion_meta.safe_auto_approve') === true
            && data_get($category, 'suggestion_meta.review_required') !== true
            && filled($category['standard_category_id'] ?? null)
            && (float) ($category['confidence_score'] ?? 0) >= self::SAFE_AUTO_APPROVE_THRESHOLD
            && in_array(($category['decision_type'] ?? 'map'), ['map', 'alias'], true);
    }

    private function resolveRows(SupplierSource $source, ?array $parsedRows): array
    {
        if (is_array($parsedRows) && $parsedRows !== []) {
            return [$parsedRows, 'provided_rows', [], []];
        }

        $parseResult = [
            'errors' => [],
            'warnings' => [],
        ];
        $fetchResult = $this->fetchService->fetch($source);
        if (($fetchResult['ok'] ?? false) === true) {
            $parseResult = $this->parserService->parse($source, (string) ($fetchResult['content'] ?? ''), 0);
            if (($parseResult['ok'] ?? false) === true && !empty($parseResult['rows'] ?? [])) {
                return [$parseResult['rows'], 'live_source', $parseResult['errors'] ?? [], $parseResult['warnings'] ?? []];
            }
        }

        $profileKey = $this->previewParser->getSupplierProfileKey($source);

        return [
            $this->previewParser->getDemoPayloadForProfile($profileKey),
            'demo_fallback',
            array_values(array_unique(array_filter(array_merge(
                $fetchResult['errors'] ?? [],
                $parseResult['errors'] ?? []
            )))),
            array_values(array_unique(array_filter(array_merge(
                $fetchResult['warnings'] ?? [],
                $parseResult['warnings'] ?? [],
                ['Gerçek kaynak okunamadığı için demo kategori verisi gösteriliyor.']
            )))),
        ];
    }

    private function resolveCategoryItems(array $preview): Collection
    {
        $variants = collect($preview['variants'] ?? []);
        $products = collect($preview['products'] ?? []);

        return $variants->isNotEmpty() ? $variants : $products;
    }

    private function aggregateCategories(
        SupplierSource $source,
        Collection $items,
        Collection $standardCategories,
        Collection $aliases,
        Collection $historicalMappings
    ): array {
        $categories = [];

        foreach ($items as $item) {
            $raw = (array) ($item['raw_payload'] ?? []);
            $normalized = (array) ($item['normalized_payload'] ?? []);
            $categoryName = $this->firstFilled([
                $item['supplier_category_name'] ?? null,
                $normalized['supplier_category_name'] ?? null,
                $raw['kategori_adi'] ?? null,
                $raw['kategori'] ?? null,
                $raw['urun_kategori'] ?? null,
                $raw['KategoriSub'] ?? null,
                $raw['KategoriMain'] ?? null,
            ]);

            if (!filled($categoryName)) {
                continue;
            }

            $normalizedName = $this->normalizeText($categoryName);
            $categoryKey = implode('|', [
                $source->supplier_id,
                $source->id,
                $normalizedName,
            ]);

            $path = $this->buildSupplierCategoryPath($raw, $normalized, $categoryName);
            $level = $this->resolveSupplierCategoryLevel($raw, $normalized, $path);
            $sampleName = $this->firstFilled([
                $item['variant_name'] ?? null,
                $item['product_name'] ?? null,
                $normalized['variant_name'] ?? null,
                $normalized['product_name'] ?? null,
                $raw['urunadi'] ?? null,
                $raw['UrunAdi'] ?? null,
            ]);
            $sampleImage = $this->firstFilled([
                $item['variant_image_url'] ?? null,
                $item['image_url'] ?? null,
                $normalized['variant_image_url'] ?? null,
                $normalized['image_url'] ?? null,
            ]);
            $description = $this->firstFilled([
                $item['description'] ?? null,
                $normalized['description'] ?? null,
                $raw['urunaciklamasi'] ?? null,
                $raw['urun_aciklama'] ?? null,
                $raw['UrunAciklama'] ?? null,
            ]);

            if (!isset($categories[$categoryKey])) {
                $categories[$categoryKey] = [
                    'supplier_id' => $source->supplier_id,
                    'supplier_source_id' => $source->id,
                    'supplier_name' => $source->supplier?->name ?? 'Tedarikçi',
                    'source_name' => $source->source_name,
                    'source_category' => $categoryName,
                    'supplier_category_code' => $this->firstFilled([
                        $normalized['supplier_category_id'] ?? null,
                        $raw['kategori_id'] ?? null,
                        $raw['kid'] ?? null,
                    ]),
                    'supplier_category_path' => $path,
                    'supplier_category_level' => $level,
                    'normalized_name' => $normalizedName,
                    'product_count' => 0,
                    'sample_product_names' => [],
                    'sample_image_urls' => [],
                    'sample_descriptions' => [],
                    'sample_keywords' => [],
                    'sample_paths' => [],
                    'sample_logs' => [],
                    'mapping_status' => 'pending',
                    'decision_type' => 'map',
                    'confidence_score' => null,
                    'standard_category_id' => null,
                    'target_category' => null,
                    'suggestion_reasons' => [],
                    'suggestion_reason_text' => null,
                    'suggestion_meta' => [],
                ];
            }

            $categories[$categoryKey]['product_count']++;
            $categories[$categoryKey]['sample_paths'][] = $path;

            if (filled($sampleName)) {
                $categories[$categoryKey]['sample_product_names'][] = $sampleName;
                $categories[$categoryKey]['sample_keywords'] = array_merge(
                    $categories[$categoryKey]['sample_keywords'],
                    $this->tokenizeText($sampleName)
                );
            }

            if (filled($sampleImage)) {
                $categories[$categoryKey]['sample_image_urls'][] = $sampleImage;
            }

            if (filled($description)) {
                $categories[$categoryKey]['sample_descriptions'][] = $description;
                $categories[$categoryKey]['sample_keywords'] = array_merge(
                    $categories[$categoryKey]['sample_keywords'],
                    $this->tokenizeText($description)
                );
            }

            if (count($categories[$categoryKey]['sample_logs']) < 5) {
                $categories[$categoryKey]['sample_logs'][] = [
                    'supplier_product_id' => $this->firstFilled([
                        $item['source_product_id'] ?? null,
                        $raw['uid'] ?? null,
                        $raw['urun_id'] ?? null,
                        $raw['UrunKartiID'] ?? null,
                        $raw['VaryasyonID'] ?? null,
                    ]),
                    'supplier_product_code' => $this->firstFilled([
                        $item['variant_stock_code'] ?? null,
                        $item['supplier_product_code'] ?? null,
                        $raw['kod'] ?? null,
                        $raw['urunkodu'] ?? null,
                        $raw['urun_kodu'] ?? null,
                        $raw['StokKodu'] ?? null,
                    ]),
                    'supplier_product_name' => $sampleName ?: ($categoryName . ' örnek ürün'),
                    'product_image_url' => $sampleImage,
                    'raw_signals' => [
                        'description' => $description,
                        'path' => $path,
                    ],
                ];
            }
        }

        foreach ($categories as $key => $category) {
            $category['sample_product_names'] = array_values(array_slice(array_unique($category['sample_product_names']), 0, 5));
            $category['sample_image_urls'] = array_values(array_slice(array_unique($category['sample_image_urls']), 0, 5));
            $category['sample_keywords'] = array_values(array_slice(array_unique($category['sample_keywords']), 0, 20));
            $category['sample_descriptions'] = array_values(array_slice(array_unique($category['sample_descriptions']), 0, 3));
            $category['sample_paths'] = array_values(array_slice(array_unique($category['sample_paths']), 0, 3));

            $suggestion = $this->buildSuggestion($category, $standardCategories, $aliases, $historicalMappings);

            $categories[$key] = array_merge($category, $suggestion, [
                'suggestion_meta' => array_merge(
                    $suggestion['suggestion_meta'] ?? [],
                    [
                        'sample_keywords' => $category['sample_keywords'],
                        'sample_paths' => $category['sample_paths'],
                    ]
                ),
            ]);
        }

        return $categories;
    }

    private function buildSuggestion(
        array $category,
        Collection $standardCategories,
        Collection $aliases,
        Collection $historicalMappings
    ): array {
        $normalizedName = $category['normalized_name'];
        $sampleText = trim(implode(' ', array_filter([
            $category['source_category'],
            $category['supplier_category_path'],
            implode(' ', $category['sample_product_names']),
            implode(' ', $category['sample_descriptions']),
        ])));
        $reasons = [];
        $candidates = [];
        $specialRuleSuggestion = $this->specialRuleSuggestion($category, $standardCategories, $sampleText);

        if ($specialRuleSuggestion) {
            return $specialRuleSuggestion;
        }

        $aliasMatch = $aliases->first(function (CategoryAlias $alias) use ($normalizedName, $category) {
            if (!$alias->is_active) {
                return false;
            }

            if ($alias->normalized_alias !== $normalizedName) {
                return false;
            }

            return $alias->supplier_id === null || $alias->supplier_id === $category['supplier_id'];
        });

        if ($aliasMatch && $aliasMatch->standardCategory) {
            $reasons[] = 'Alias eşleşmesi bulundu';
            $imageAnalysis = $this->imageAnalyzer->analyze($category['sample_image_urls'][0] ?? null, ['category' => $category]);
            $scoreBreakdown = $this->scoreService->finalize([
                'name_score' => 48,
                'category_score' => 22,
                'attribute_score' => 0,
                'code_score' => 18,
                'image_score' => (float) ($imageAnalysis['image_score'] ?? 0),
                'history_score' => 10,
            ]);

            return $this->buildSuggestionPayload(
                category: $category,
                standardCategory: $aliasMatch->standardCategory,
                confidence: (float) $scoreBreakdown['confidence_score'],
                status: 'auto_approved',
                decisionType: 'alias',
                reasons: $reasons,
                meta: [
                    'reason_codes' => ['alias'],
                    'alias_candidate' => true,
                    'twin_view_candidate' => $this->isTwinViewCandidate($category),
                    'merge_candidate' => $this->isMergeCandidate($category),
                    'filter_candidate' => $this->isFilterCandidate($category),
                    'conflict' => false,
                    'suggestion_state' => 'high_confidence',
                    'safe_auto_approve' => $this->canSafelyAutoApprove(98.0, 'alias', false, ['alias']),
                    'review_required' => false,
                    'special_rule' => null,
                    'score_breakdown' => $scoreBreakdown,
                    'image_analysis' => $imageAnalysis,
                ]
            );
        }

        $historyMatch = $historicalMappings->first(function (SupplierCategoryMapping $mapping) use ($normalizedName) {
            return $this->normalizeText((string) $mapping->source_category) === $normalizedName
                && $mapping->standardCategory;
        });

        if ($historyMatch && $historyMatch->standardCategory) {
            $reasons[] = 'Önceki manuel eşleme ile benzer';
            $imageAnalysis = $this->imageAnalyzer->analyze($category['sample_image_urls'][0] ?? null, ['category' => $category]);
            $scoreBreakdown = $this->scoreService->finalize([
                'name_score' => 40,
                'category_score' => 18,
                'attribute_score' => 0,
                'code_score' => 12,
                'image_score' => (float) ($imageAnalysis['image_score'] ?? 0),
                'history_score' => max((float) ($historyMatch->confidence_score ?? 22), 22.0),
            ]);

            return $this->buildSuggestionPayload(
                category: $category,
                standardCategory: $historyMatch->standardCategory,
                confidence: max((float) $scoreBreakdown['confidence_score'], 92.0),
                status: 'auto_approved',
                decisionType: 'map',
                reasons: $reasons,
                meta: [
                    'reason_codes' => ['historical_match'],
                    'alias_candidate' => false,
                    'twin_view_candidate' => $this->isTwinViewCandidate($category),
                    'merge_candidate' => $this->isMergeCandidate($category),
                    'filter_candidate' => $this->isFilterCandidate($category),
                    'conflict' => false,
                    'suggestion_state' => 'high_confidence',
                    'safe_auto_approve' => false,
                    'review_required' => false,
                    'special_rule' => 'historical_match',
                    'score_breakdown' => $scoreBreakdown,
                    'image_analysis' => $imageAnalysis,
                ]
            );
        }

        $wirelessSignals = $this->countKeywordMatches($sampleText, [
            'wireless', 'kablosuz', 'sarj', 'şarj', 'charger', 'qi', 'usb', 'power', 'telefon sarj', 'telefon şarj', 'led', 'elektronik',
        ]);
        $mousepadSignals = $this->countKeywordMatches($sampleText, [
            'mousepad', 'taban', 'baski', 'baski alani', 'baskı alanı', 'eva', 'kaucuk', 'kauçuk', 'sumen', 'sümen', 'bardakaltligi', 'bardakaltlığı', 'masaustu', 'masaüstü',
        ]);
        $imageAnalysis = $this->imageAnalyzer->analyze($category['sample_image_urls'][0] ?? null, [
            'category' => $category,
            'sample_text' => $sampleText,
        ]);

        foreach ($standardCategories as $standardCategory) {
            $score = 0.0;
            $componentScores = [
                'name_score' => 0.0,
                'category_score' => 0.0,
                'attribute_score' => 0.0,
                'code_score' => 0.0,
                'image_score' => (float) ($imageAnalysis['image_score'] ?? 0),
                'history_score' => 0.0,
            ];
            $reasonCodes = [];
            $standardName = $this->normalizeText($standardCategory->name);
            $standardPath = $this->normalizeText($standardCategory->full_path);
            $categoryPath = $this->normalizeText((string) ($category['supplier_category_path'] ?? $category['source_category']));

            if ($standardName === $normalizedName) {
                $score += 45;
                $componentScores['name_score'] += 45;
                $reasonCodes[] = 'exact_name';
            }

            similar_text($normalizedName, $standardName, $nameSimilarity);
            $nameBoost = round($nameSimilarity * 0.35, 2);
            $score += $nameBoost;
            $componentScores['name_score'] += $nameBoost;
            if ($nameSimilarity >= 70) {
                $reasonCodes[] = 'name_similarity';
            }

            similar_text($categoryPath, $standardPath, $pathSimilarity);
            $pathBoost = round($pathSimilarity * 0.18, 2);
            $score += $pathBoost;
            $componentScores['category_score'] += $pathBoost;
            if ($pathSimilarity >= 65) {
                $reasonCodes[] = 'path_similarity';
            }

            $keywordOverlap = count(array_intersect(
                $category['sample_keywords'],
                $this->tokenizeText($standardCategory->name . ' ' . $standardCategory->full_path)
            ));
            if ($keywordOverlap > 0) {
                $keywordBoost = min(18, $keywordOverlap * 4);
                $score += $keywordBoost;
                $componentScores['attribute_score'] += $keywordBoost;
                $reasonCodes[] = 'keyword_overlap';
            }

            if (str_contains($standardPath, 'usb') && str_contains($normalizedName, 'flash')) {
                $score += 28;
                $componentScores['code_score'] += 28;
                $reasonCodes[] = 'alias_like_usb_flash';
            }

            if (str_contains($standardPath, 'powerbank') && str_contains($normalizedName, 'power bank')) {
                $score += 20;
                $componentScores['code_score'] += 20;
                $reasonCodes[] = 'powerbank_spacing';
            }

            if (str_contains($standardPath, 'wireless') || str_contains($standardPath, 'kablosuz') || str_contains($standardPath, 'teknoloji')) {
                $wirelessBoost = $wirelessSignals * 8;
                $score += $wirelessBoost;
                $componentScores['attribute_score'] += $wirelessBoost;
                if ($wirelessSignals > 0) {
                    $reasonCodes[] = 'wireless_signal';
                }
            }

            if ($wirelessSignals > 0 && (str_contains($standardName, 'wireless') || str_contains($standardPath, 'wireless') || str_contains($standardPath, 'kablosuz'))) {
                $score += 18;
                $componentScores['category_score'] += 18;
                $reasonCodes[] = 'wireless_target_boost';
            }

            if ($wirelessSignals > 0
                && $mousepadSignals > 0
                && (str_contains($standardName, 'mousepad') || str_contains($standardPath, 'mousepad'))
                && (str_contains($standardName, 'wireless') || str_contains($standardPath, 'wireless') || str_contains($standardPath, 'kablosuz'))
            ) {
                $score += 28;
                $componentScores['category_score'] += 28;
                $reasonCodes[] = 'wireless_mousepad_combo';
            }

            if (str_contains($standardPath, 'mousepad') || str_contains($standardPath, 'masaustu') || str_contains($standardPath, 'masaüstü') || str_contains($standardPath, 'bardakalt')) {
                $mousepadBoost = $mousepadSignals * 6;
                $score += $mousepadBoost;
                $componentScores['attribute_score'] += $mousepadBoost;
                if ($mousepadSignals > 0) {
                    $reasonCodes[] = 'mousepad_signal';
                }
            }

            if ($wirelessSignals > 0
                && $mousepadSignals > 0
                && str_contains($standardPath, 'teknoloji')
                && !str_contains($standardPath, 'mousepad')
            ) {
                $score -= 12;
                $componentScores['category_score'] -= 12;
                $reasonCodes[] = 'generic_tech_penalty';
            }

            if (str_contains($normalizedName, 'takvim') && (str_contains($standardName, 'takvim') || str_contains($standardPath, 'takvim'))) {
                $score += 22;
                $componentScores['category_score'] += 22;
                $reasonCodes[] = 'calendar_signal';
            }

            if ($this->isUmbrellaName($normalizedName) && str_contains($standardPath, 'semsiye')) {
                $score += 18;
                $componentScores['category_score'] += 18;
                $reasonCodes[] = 'umbrella_signal';
            }

            $scoreBreakdown = $this->scoreService->finalize($componentScores);
            $candidates[] = [
                'category' => $standardCategory,
                'score' => min(99.0, round($scoreBreakdown['confidence_score'], 2)),
                'score_breakdown' => $scoreBreakdown,
                'reason_codes' => array_values(array_unique($reasonCodes)),
            ];
        }

        usort($candidates, fn (array $left, array $right) => $right['score'] <=> $left['score']);

        $best = $candidates[0] ?? null;
        $second = $candidates[1] ?? null;
        $conflict = $best && $second
            ? abs(($best['score'] ?? 0) - ($second['score'] ?? 0)) < 6
                && ($best['score'] ?? 0) >= self::REVIEW_THRESHOLD
                && ($second['score'] ?? 0) >= self::REVIEW_THRESHOLD
            : false;

        if ($conflict
            && $wirelessSignals === 0
            && $mousepadSignals > 0
            && $best
            && $second
            && $this->isSameNonTechMousepadFamily($best['category'], $second['category'])
        ) {
            $conflict = false;
        }

        if ($this->isMousepadConflict($wirelessSignals, $mousepadSignals)) {
            $conflict = true;
            $reasons[] = 'Ürün adlarında wireless / şarj ve mousepad baskı sinyali birlikte bulundu';
        }

        if (!$best || ($best['score'] ?? 0) < 45) {
            return [
                'standard_category_id' => null,
                'target_category' => null,
                'confidence_score' => $best['score'] ?? null,
                'mapping_status' => 'pending',
                'decision_type' => 'map',
                'suggestion_reasons' => ['Eşleme için manuel seçim gerekli'],
                'suggestion_reason_text' => 'Eşleme için manuel seçim gerekli',
                'suggestion_meta' => [
                    'reason_codes' => $best['reason_codes'] ?? [],
                    'alias_candidate' => false,
                    'twin_view_candidate' => $this->isTwinViewCandidate($category),
                    'merge_candidate' => $this->isMergeCandidate($category),
                    'filter_candidate' => $this->isFilterCandidate($category),
                    'conflict' => $conflict,
                    'suggestion_state' => 'manual',
                    'safe_auto_approve' => false,
                    'review_required' => true,
                    'special_rule' => null,
                    'score_breakdown' => $best['score_breakdown'] ?? null,
                    'image_analysis' => $imageAnalysis,
                ],
            ];
        }

        $reasons = array_merge($reasons, $this->reasonLabels($best['reason_codes'] ?? []));
        $mappingStatus = 'pending';
        $decisionType = 'map';

        if ($conflict) {
            $mappingStatus = 'conflict';
        } elseif (($best['score'] ?? 0) >= self::HIGH_CONFIDENCE_THRESHOLD) {
            $mappingStatus = 'auto_approved';
        } elseif (($best['score'] ?? 0) >= self::REVIEW_THRESHOLD) {
            $mappingStatus = 'needs_review';
        }

        if ($this->isTwinViewCandidate($category)) {
            $decisionType = 'twin_view';
        } elseif ($this->isMergeCandidate($category)) {
            $decisionType = 'merge_candidate';
        } elseif ($this->isFilterCandidate($category)) {
            $decisionType = 'filter_candidate';
        }

        if (str_contains($normalizedName, 'flash') || str_contains($normalizedName, 'power bank') || str_contains($normalizedName, 'sticker')) {
            $decisionType = 'alias';
        }

        return $this->buildSuggestionPayload(
            category: $category,
            standardCategory: $best['category'],
            confidence: (float) $best['score'],
            status: $mappingStatus,
            decisionType: $decisionType,
            reasons: array_values(array_unique(array_filter($reasons))),
            meta: [
                'reason_codes' => $best['reason_codes'] ?? [],
                'alias_candidate' => $decisionType === 'alias',
                'twin_view_candidate' => $this->isTwinViewCandidate($category),
                'merge_candidate' => $this->isMergeCandidate($category),
                'filter_candidate' => $this->isFilterCandidate($category),
                'conflict' => $conflict,
                'second_candidate' => $second ? [
                    'id' => $second['category']->id,
                    'name' => $second['category']->full_path,
                    'score' => $second['score'],
                ] : null,
                'score_breakdown' => $best['score_breakdown'] ?? null,
                'image_analysis' => $imageAnalysis,
                'wireless_signal_count' => $wirelessSignals,
                'mousepad_signal_count' => $mousepadSignals,
                'safe_auto_approve' => $this->canSafelyAutoApprove((float) $best['score'], $decisionType, $conflict, $best['reason_codes'] ?? []),
                'review_required' => $conflict || (float) $best['score'] < self::SAFE_AUTO_APPROVE_THRESHOLD || !in_array($decisionType, ['map', 'alias'], true),
                'special_rule' => null,
                'suggestion_state' => $mappingStatus === 'auto_approved'
                    ? 'high_confidence'
                    : ($mappingStatus === 'needs_review' ? 'review' : ($mappingStatus === 'conflict' ? 'conflict' : 'pending')),
            ]
        );
    }

    private function buildSuggestionPayload(
        array $category,
        StandardCategory $standardCategory,
        float $confidence,
        string $status,
        string $decisionType,
        array $reasons,
        array $meta
    ): array {
        return [
            'standard_category_id' => $standardCategory->id,
            'target_category' => $standardCategory->full_path,
            'confidence_score' => $confidence,
            'mapping_status' => $status,
            'decision_type' => $decisionType,
            'suggestion_reasons' => $reasons,
            'suggestion_reason_text' => implode(' · ', $reasons),
            'suggestion_meta' => $meta,
            'product_family' => $standardCategory->product_family,
        ];
    }

    private function specialRuleSuggestion(array $category, Collection $standardCategories, string $sampleText): ?array
    {
        $normalizedName = $category['normalized_name'] ?? '';
        $normalizedText = $this->normalizeText($sampleText);

        $rules = [
            [
                'rule' => 'gift_sets',
                'needles' => ['vip set', 'kutulu set', 'kalemli set', 'defterli set', 'termoslu set', 'teknolojik set', 'kurumsal set', 'hazir paket', 'hazir paket urun', 'hediyelik set'],
                'target_codes' => ['PROMO-HEDIYELIK-SET'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => $this->giftSetFeatureSuggestion($normalizedText),
                'reason' => 'Hediyelik set alt türleri tek Hediyelik Setler kategorisine bağlanır.',
            ],
            [
                'rule' => 'cups_material',
                'needles' => ['seramik kupa', 'porselen kupa', 'cam kupa', 'metal kupa', 'kupalar', 'kupa'],
                'target_codes' => ['PROMO-ICECEK-KUPA'],
                'confidence' => 95.0,
                'review' => false,
                'feature' => $this->materialFeatureSuggestion($normalizedText),
                'reason' => 'Kupa malzeme ayrımı kategori değil özellik olarak tutulur.',
            ],
            [
                'rule' => 'calendar_gemici',
                'needles' => ['gemici takvim'],
                'target_codes' => ['PRINT-TAKVIM-GEMICI'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Takvimler matbaa takvim ağacına bağlanır.',
            ],
            [
                'rule' => 'calendar_piramit',
                'needles' => ['piramit takvim'],
                'target_codes' => ['PRINT-TAKVIM-PIRAMIT'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Takvimler matbaa takvim ağacına bağlanır.',
            ],
            [
                'rule' => 'calendar_table',
                'needles' => ['masa takvimi'],
                'target_codes' => ['PRINT-TAKVIM-MASA'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Masa takvimi matbaa takvim ağacına bağlanır.',
            ],
            [
                'rule' => 'calendar_wall',
                'needles' => ['duvar takvimi'],
                'target_codes' => ['PRINT-TAKVIM-DUVAR'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Duvar takvimi matbaa takvim ağacına bağlanır.',
            ],
            [
                'rule' => 'calendar_general',
                'needles' => ['takvim', 'takvimler'],
                'target_codes' => ['PRINT-TAKVIM'],
                'confidence' => 95.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Takvimler promosyon ağacına değil matbaa takvim ağacına bağlanır.',
            ],
            [
                'rule' => 'desk_sumen_print',
                'needles' => ['gemici', 'masa takvimi', 'haftalik', 'haftalık', 'matbaa'],
                'must_contain' => ['sumen', 'sümen'],
                'target_codes' => ['PRINT-TAKVIM-MASA-SUMENI'],
                'confidence' => 90.0,
                'review' => true,
                'feature' => [],
                'reason' => 'Masa sümeni matbaa/haftalık sinyali taşıyor; kontrol önerilir.',
            ],
            [
                'rule' => 'desk_sumen_paper',
                'needles' => ['bloknot', 'kagit', 'kağıt', 'baskili', 'baskılı'],
                'must_contain' => ['sumen', 'sümen'],
                'target_codes' => ['PROMO-KAGIT-URETIM-BASKILI-MASA-SUMENI'],
                'confidence' => 88.0,
                'review' => true,
                'feature' => [],
                'reason' => 'Baskılı/kağıt masa sümeni sinyali bulundu; kontrol önerilir.',
            ],
            [
                'rule' => 'desk_sumen_office',
                'needles' => ['masa seti', 'deri', 'vip', 'masa setleri'],
                'must_contain' => ['sumen', 'sümen'],
                'target_codes' => ['PROMO-OFIS-MASAUSTU-SUMEN'],
                'confidence' => 88.0,
                'review' => true,
                'feature' => [],
                'reason' => 'Hazır/promosyon masa sümeni sinyali bulundu; kontrol önerilir.',
            ],
            [
                'rule' => 'wireless_mousepad_conflict',
                'needles' => ['baski alani', 'baskı alanı', 'eva taban', 'genis eva', 'geniş eva'],
                'must_contain' => ['wireless', 'mousepad'],
                'target_codes' => ['PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD'],
                'confidence' => 86.0,
                'review' => true,
                'conflict' => true,
                'feature' => ['kablosuz_sarj' => true, 'review_hint' => 'Wireless ve baskılı/klasik mousepad sinyali birlikte geldi.'],
                'reason' => 'Wireless ve baskılı/klasik mousepad sinyali birlikte bulundu; manuel kontrol gerekir.',
            ],
            [
                'rule' => 'wireless_mousepad',
                'needles' => ['wireless mousepad', 'kablosuz mousepad', 'sarj mousepad', 'şarj mousepad', 'qi mousepad'],
                'target_codes' => ['PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD'],
                'confidence' => 95.0,
                'review' => false,
                'feature' => ['kablosuz_sarj' => true],
                'reason' => 'Wireless/şarj mousepad teknoloji kategorisine bağlanır.',
            ],
            [
                'rule' => 'classic_mousepad',
                'needles' => ['klasik mousepad', 'baskili mousepad', 'baskılı mousepad', 'mousepad bardak', 'mousepad-bardak', 'mousepad'],
                'target_codes' => ['PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD'],
                'confidence' => 84.0,
                'review' => true,
                'feature' => [],
                'reason' => 'Klasik/baskılı mousepad kağıt üretim promosyonuna bağlanır; kontrol önerilir.',
            ],
            [
                'rule' => 'coaster',
                'needles' => ['bardak altlik', 'bardak altlık', 'bardakaltligi', 'bardakaltlığı'],
                'target_codes' => ['PROMO-KAGIT-URETIM-BARDAK-ALTLIK'],
                'confidence' => 88.0,
                'review' => true,
                'feature' => [],
                'reason' => 'Bardak altlığı klasik mousepad’den ayrı tutulur.',
            ],
            [
                'rule' => 'set_boxes',
                'needles' => ['set kutu', 'set kutulari', 'set kutuları', 'bos kutu', 'boş kutu', 'ambalaj'],
                'target_codes' => ['PROMO-AMBALAJ-KUTU-SET'],
                'confidence' => 82.0,
                'review' => true,
                'feature' => ['review_hint' => 'Boş kutu mu ürünlü set mi kontrol edilmeli.'],
                'reason' => 'Set kutuları riskli gruptur; otomatik kabul edilmez.',
            ],
            [
                'rule' => 'opener_magnet',
                'needles' => ['acacakli magnet', 'açacaklı magnet'],
                'target_codes' => ['PROMO-AKSESUAR-ACACAKLI-MAGNET'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Açacaklı magnet, açacak ve magnetten ayrı kategoridir.',
            ],
            [
                'rule' => 'opener',
                'needles' => ['acacak', 'açacak'],
                'target_codes' => ['PROMO-AKSESUAR-ACACAK'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Açacaklar ayrı kategori olarak korunur.',
            ],
            [
                'rule' => 'magnet',
                'needles' => ['magnet'],
                'target_codes' => ['PROMO-AKSESUAR-MAGNET'],
                'confidence' => 96.0,
                'review' => false,
                'feature' => [],
                'reason' => 'Magnetler ayrı kategori olarak korunur.',
            ],
        ];

        foreach ($rules as $rule) {
            if (!$this->ruleMatches($normalizedName, $normalizedText, $rule)) {
                continue;
            }

            $target = $this->findTargetCategory($standardCategories, $rule['target_codes']);
            if (!$target) {
                return null;
            }

            $reviewRequired = (bool) $rule['review'];
            $confidence = (float) $rule['confidence'];
            $conflict = (bool) ($rule['conflict'] ?? false);
            $wirelessSignals = $this->countKeywordMatches($normalizedText, ['wireless', 'kablosuz', 'sarj', 'şarj', 'qi', 'usb', 'power']);
            $mousepadSignals = $this->countKeywordMatches($normalizedText, ['mousepad', 'baski', 'baskı', 'eva', 'taban', 'bardakaltligi', 'bardakaltlığı']);

            return $this->buildSuggestionPayload(
                category: $category,
                standardCategory: $target,
                confidence: $confidence,
                status: $conflict ? 'conflict' : ($reviewRequired ? 'needs_review' : 'auto_approved'),
                decisionType: 'map',
                reasons: [$rule['reason']],
                meta: [
                    'reason_codes' => ['special_rule', $rule['rule']],
                    'alias_candidate' => false,
                    'twin_view_candidate' => false,
                    'merge_candidate' => false,
                    'filter_candidate' => false,
                    'conflict' => $conflict,
                    'special_rule' => $rule['rule'],
                    'special_rule_label' => 'Özel Kural',
                    'feature_suggestions' => $rule['feature'],
                    'review_required' => $reviewRequired,
                    'safe_auto_approve' => $this->canSafelyAutoApprove($confidence, 'map', $conflict, ['special_rule', $rule['rule']]) && !$reviewRequired,
                    'wireless_signal_count' => $wirelessSignals,
                    'mousepad_signal_count' => $mousepadSignals,
                    'suggestion_state' => $conflict ? 'conflict' : ($reviewRequired ? 'review' : 'high_confidence'),
                    'score_breakdown' => [
                        'confidence_score' => $confidence,
                        'category_score' => $confidence,
                    ],
                    'image_analysis' => null,
                ]
            );
        }

        return null;
    }

    private function ruleMatches(string $normalizedName, string $normalizedText, array $rule): bool
    {
        $haystack = trim($normalizedName . ' ' . $normalizedText);

        foreach ((array) ($rule['must_contain'] ?? []) as $needle) {
            if (!str_contains($haystack, $this->normalizeText($needle))) {
                return false;
            }
        }

        foreach ((array) $rule['needles'] as $needle) {
            if (str_contains($haystack, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function findTargetCategory(Collection $standardCategories, array $codes): ?StandardCategory
    {
        foreach ($codes as $code) {
            $target = $standardCategories->first(fn (StandardCategory $category) => $category->code === $code);
            if ($target) {
                return $target;
            }
        }

        return null;
    }

    private function canSafelyAutoApprove(float $confidence, string $decisionType, bool $conflict, array $reasonCodes): bool
    {
        $riskCodes = ['set_boxes', 'classic_mousepad', 'desk_sumen_print', 'desk_sumen_paper', 'desk_sumen_office'];

        return $confidence >= self::SAFE_AUTO_APPROVE_THRESHOLD
            && !$conflict
            && in_array($decisionType, ['map', 'alias'], true)
            && count(array_intersect($riskCodes, $reasonCodes)) === 0;
    }

    private function giftSetFeatureSuggestion(string $text): array
    {
        return match (true) {
            str_contains($text, 'vip') => ['set_tipi' => 'VIP'],
            str_contains($text, 'kutulu') => ['kutu_tipi' => 'Kutulu'],
            str_contains($text, 'kalemli') => ['set_icerigi' => 'Kalem'],
            str_contains($text, 'defterli') => ['set_icerigi' => 'Defter / Ajanda'],
            str_contains($text, 'termoslu') => ['set_icerigi' => 'Termos / Kupa'],
            str_contains($text, 'teknolojik') => ['set_icerigi' => 'Teknolojik Ürün'],
            str_contains($text, 'kurumsal') => ['set_tipi' => 'Kurumsal'],
            str_contains($text, 'hazir') => ['set_tipi' => 'Hazır Paket'],
            default => [],
        };
    }

    private function materialFeatureSuggestion(string $text): array
    {
        return match (true) {
            str_contains($text, 'seramik') => ['malzeme' => 'seramik'],
            str_contains($text, 'porselen') => ['malzeme' => 'porselen'],
            str_contains($text, 'cam') => ['malzeme' => 'cam'],
            str_contains($text, 'metal') => ['malzeme' => 'metal'],
            default => [],
        };
    }

    private function persistCategories(SupplierSource $source, array $categories): void
    {
        foreach ($categories as $category) {
            $existing = SupplierCategoryMapping::query()->firstOrNew([
                'supplier_source_id' => $source->id,
                'source_category' => $category['source_category'],
            ]);

            $existing->fill([
                'supplier_id' => $source->supplier_id,
                'supplier_category_code' => $category['supplier_category_code'],
                'supplier_category_path' => $category['supplier_category_path'],
                'supplier_category_level' => $category['supplier_category_level'],
                'normalized_name' => $category['normalized_name'],
                'product_count' => $category['product_count'],
                'sample_product_names' => $category['sample_product_names'],
                'sample_image_urls' => $category['sample_image_urls'],
                'suggestion_meta' => $category['suggestion_meta'],
                'standard_category_id' => $category['standard_category_id'],
                'target_category' => $category['target_category'] ?: '',
                'mapping_status' => $category['mapping_status'],
                'decision_type' => $category['decision_type'],
                'description' => $category['suggestion_reason_text'],
                'decision_note' => $existing->decision_note,
                'confidence_score' => $category['confidence_score'],
                'is_active' => true,
                'last_scanned_at' => now(),
            ]);

            if (!$existing->exists) {
                $existing->reviewed_by = null;
                $existing->reviewed_at = null;
            }

            $existing->save();
        }

        $this->persistSuggestionLogs($source, $categories);
    }

    private function buildSummary(array $categories): array
    {
        $collection = collect($categories);

        return [
            'category_count' => $collection->count(),
            'mapped_count' => $collection->whereNotNull('standard_category_id')->count(),
            'pending_count' => $collection->where('mapping_status', 'pending')->count(),
            'high_confidence_count' => $collection->where('mapping_status', 'auto_approved')->count(),
            'review_count' => $collection->filter(fn (array $row) => in_array($row['mapping_status'], ['needs_review', 'conflict'], true))->count(),
            'conflict_count' => $collection->where('mapping_status', 'conflict')->count(),
            'alias_candidate_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.alias_candidate') === true)->count(),
            'twin_view_candidate_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.twin_view_candidate') === true)->count(),
            'merge_candidate_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.merge_candidate') === true)->count(),
            'filter_candidate_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.filter_candidate') === true)->count(),
            'safe_auto_approve_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.safe_auto_approve') === true)->count(),
            'review_required_count' => $collection->filter(fn (array $row) => data_get($row, 'suggestion_meta.review_required') === true)->count(),
            'no_target_count' => $collection->whereNull('standard_category_id')->count(),
            'special_rule_count' => $collection->filter(fn (array $row) => filled(data_get($row, 'suggestion_meta.special_rule')))->count(),
        ];
    }

    private function buildSupplierCategoryPath(array $raw, array $normalized, string $categoryName): string
    {
        $main = $this->firstFilled([
            $raw['KategoriMain'] ?? null,
            $raw['ana_kategori'] ?? null,
        ]);
        $sub = $this->firstFilled([
            $raw['KategoriSub'] ?? null,
            $normalized['supplier_subcategory_name'] ?? null,
            $raw['discat_name'] ?? null,
            $raw['kategori'] ?? null,
            $raw['kategori_adi'] ?? null,
            $raw['urun_kategori'] ?? null,
            $raw['category_name'] ?? null,
        ]);

        if (filled($main) && filled($sub) && $main !== $sub) {
            return trim($main . ' > ' . $sub);
        }

        return $sub ?: $categoryName;
    }

    private function resolveSupplierCategoryLevel(array $raw, array $normalized, string $path): int
    {
        $level = $normalized['supplier_category_level'] ?? $raw['kid_seviye'] ?? null;
        if (is_numeric($level)) {
            return max(1, (int) $level);
        }

        return max(1, substr_count($path, '>') + 1);
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ];

        return $this->signalNormalizer->normalizeText($value);
    }

    private function tokenizeText(string $value): array
    {
        return $this->signalNormalizer->tokenizeText($value);
    }

    private function countKeywordMatches(string $haystack, array $needles): int
    {
        return $this->signalNormalizer->countKeywordMatches($haystack, $needles);
    }

    private function isMousepadConflict(int $wirelessSignals, int $mousepadSignals): bool
    {
        return $wirelessSignals > 0 && $mousepadSignals > 0 && abs($wirelessSignals - $mousepadSignals) <= 2;
    }

    private function isSameNonTechMousepadFamily(StandardCategory $left, StandardCategory $right): bool
    {
        $leftPath = $this->normalizeText($left->full_path ?? $left->path ?? $left->name ?? '');
        $rightPath = $this->normalizeText($right->full_path ?? $right->path ?? $right->name ?? '');

        $isMousepadFamily = static function (string $path): bool {
            return (str_contains($path, 'mousepad')
                    || str_contains($path, 'masaustu')
                    || str_contains($path, 'masaustu urunleri')
                    || str_contains($path, 'ofis')
                    || str_contains($path, 'matbaa'))
                && !str_contains($path, 'wireless')
                && !str_contains($path, 'kablosuz')
                && !str_contains($path, 'teknoloji');
        };

        return $isMousepadFamily($leftPath) && $isMousepadFamily($rightPath);
    }

    private function isTwinViewCandidate(array $category): bool
    {
        $normalized = $category['normalized_name'] ?? '';

        return $this->isUmbrellaName($normalized)
            || str_contains($normalized, 'mousepad')
            || str_contains($normalized, 'powerbank');
    }

    private function isUmbrellaName(string $normalized): bool
    {
        return str_contains($normalized, 'semsiye');
    }

    private function isMergeCandidate(array $category): bool
    {
        $normalized = $category['normalized_name'] ?? '';

        if (str_contains($normalized, 'takvim') && !in_array($normalized, ['takvim', 'takvimler'], true)) {
            return true;
        }

        return str_contains($normalized, 'set kutu')
            || str_contains($normalized, 'mousepad')
            || str_contains($normalized, 'kupalar');
    }

    private function isFilterCandidate(array $category): bool
    {
        $normalized = $category['normalized_name'] ?? '';

        return str_contains($normalized, 'metal ')
            || str_contains($normalized, 'plastik ')
            || str_contains($normalized, 'cam ')
            || str_contains($normalized, 'bombe ')
            || str_contains($normalized, 'gemici ');
    }

    private function reasonLabels(array $reasonCodes): array
    {
        $labels = [
            'exact_name' => 'Ad benzerliği',
            'name_similarity' => 'Normalize edilmiş ad benzerliği',
            'path_similarity' => 'Kategori yolu benzerliği',
            'keyword_overlap' => 'Ürün örneklerinde anahtar kelime bulundu',
            'alias_like_usb_flash' => 'USB Flash için alias benzeri eşleşme bulundu',
            'powerbank_spacing' => 'Power Bank / Powerbank adı yakın bulundu',
            'wireless_signal' => 'Ürün adlarında wireless / şarj kelimesi bulundu',
            'wireless_target_boost' => 'Kablosuz şarj sinyali teknoloji kategorisini güçlendirdi',
            'wireless_mousepad_combo' => 'Wireless ve mousepad sinyali birlikte hedef kategoriye uydu',
            'mousepad_signal' => 'Ürün adlarında mousepad / baskı sinyali bulundu',
            'umbrella_signal' => 'Kategori adı şemsiye sinyali verdi',
            'calendar_signal' => 'Takvim anahtar kelimesi benzer kategoriyi güçlendirdi',
            'generic_tech_penalty' => 'Genel teknoloji kategorisi, mousepad detayı için zayıf bulundu',
        ];

        return array_values(array_filter(array_map(fn (string $code) => $labels[$code] ?? null, $reasonCodes)));
    }

    private function persistSuggestionLogs(SupplierSource $source, array $categories): void
    {
        foreach ($categories as $category) {
            $scoreBreakdown = (array) data_get($category, 'suggestion_meta.score_breakdown', []);
            $sampleLogs = array_slice((array) ($category['sample_logs'] ?? []), 0, 5);
            foreach ($sampleLogs as $sampleLog) {
                ProductCategorySuggestionLog::query()->updateOrCreate(
                    [
                        'supplier_source_id' => $source->id,
                        'supplier_product_id' => $sampleLog['supplier_product_id'] ?? null,
                        'supplier_product_code' => $sampleLog['supplier_product_code'] ?? null,
                        'supplier_product_name' => $sampleLog['supplier_product_name'],
                    ],
                    [
                        'supplier_category_name' => $category['source_category'],
                        'product_image_url' => $sampleLog['product_image_url'] ?? null,
                        'suggested_category_id' => $category['standard_category_id'],
                        'accepted_category_id' => null,
                        'confidence_score' => $category['confidence_score'],
                        'name_score' => data_get($scoreBreakdown, 'name_score'),
                        'category_score' => data_get($scoreBreakdown, 'category_score'),
                        'attribute_score' => data_get($scoreBreakdown, 'attribute_score'),
                        'code_score' => data_get($scoreBreakdown, 'code_score'),
                        'image_score' => data_get($scoreBreakdown, 'image_score'),
                        'history_score' => data_get($scoreBreakdown, 'history_score'),
                        'decision_status' => $this->mapDecisionStatus($category['mapping_status'] ?? 'pending'),
                        'decision_reason' => $category['suggestion_reason_text'] ?? null,
                        'raw_signals' => array_merge(
                            (array) ($sampleLog['raw_signals'] ?? []),
                            [
                                'reason_codes' => data_get($category, 'suggestion_meta.reason_codes', []),
                                'sample_keywords' => $category['sample_keywords'] ?? [],
                                'image_analysis' => data_get($category, 'suggestion_meta.image_analysis'),
                            ]
                        ),
                    ]
                );
            }
        }
    }

    private function mapDecisionStatus(string $mappingStatus): string
    {
        return match ($mappingStatus) {
            'approved', 'auto_approved', 'mapped' => 'accepted',
            'rejected', 'ignored' => 'rejected',
            'needs_review', 'conflict' => 'review_required',
            default => 'pending',
        };
    }
}
