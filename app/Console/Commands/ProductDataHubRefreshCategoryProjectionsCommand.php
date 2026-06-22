<?php

namespace App\Console\Commands;

use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductDataHubRefreshCategoryProjectionsCommand extends Command
{
    protected $signature = 'product-data-hub:refresh-category-projections
        {--dry-run : Sadece etki raporu üretir, veri değiştirmez}
        {--apply : Kategori snapshot güncellemesini uygular}
        {--source= : Yalnız belirli supplier source id için raporlar}
        {--supplier= : Yalnız belirli supplier code/id için raporlar}
        {--only-approved : Yalnız approved mappingleri dikkate alır}
        {--only-safe : Yalnız safe_refresh eşleşmelerini apply listesine alır}
        {--category= : Yalnız belirli standard category id için raporlar}
        {--confirm= : Apply için güvenli onay anahtarı}';

    protected $description = 'Onaylı tedarikçi kategori eşlemelerinin ürün/projection kategori snapshot etkisini dry-run olarak raporlar.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlySafe = (bool) $this->option('only-safe');

        if ($apply) {
            if (!$onlySafe) {
                $this->error('Apply durduruldu. Bu fazda --only-safe zorunludur.');

                return self::FAILURE;
            }

            if ($this->option('confirm') !== 'SAFE-CATEGORY-REFRESH') {
                $this->error('Apply durduruldu. Safe category refresh için --confirm=SAFE-CATEGORY-REFRESH zorunludur.');

                return self::FAILURE;
            }
        }

        $mappings = $this->approvedMappings();
        $analysis = $this->analyzeStandardProducts($mappings);
        $tenantAnalysis = $this->analyzeTenantProducts($analysis);
        $applyResult = null;

        if ($apply) {
            $applyResult = $this->applySafeRefresh($analysis, $tenantAnalysis);
        }

        $this->info($apply
            ? 'Apply: yalnız safe_refresh kategori projection güncellemesi tamamlandı.'
            : 'Dry-run: kategori projection refresh eşleşme audit raporu üretildi, veri değiştirilmedi.');
        $this->line('Approved mapping sayısı: ' . $mappings->count());
        $this->line('Uygulanabilir mapping sayısı: ' . $mappings->filter(fn (SupplierCategoryMapping $mapping) => $mapping->standardCategory?->isPermanentBackbone())->count());
        $this->line('Kategori bekleyen standard product: ' . $analysis['waiting_standard']);
        $this->line('Mapping’e bağlanabilen standard product: ' . $analysis['matched_standard']);
        $this->line('safe_refresh standard product: ' . $analysis['safe_refresh']);
        $this->line('review_refresh standard product: ' . $analysis['review_refresh']);
        $this->line('no_match standard product: ' . $analysis['no_match']);
        $this->line('blocked standard product: ' . $analysis['blocked']);
        $this->line('Kategori bekleyen tenant catalog product: ' . $tenantAnalysis['waiting_tenant']);
        $this->line('Mapping’e bağlanabilen tenant catalog product: ' . $tenantAnalysis['matched_tenant']);
        $this->line('safe_refresh tenant catalog product: ' . $tenantAnalysis['safe_refresh']);
        $this->line('review_refresh tenant catalog product: ' . $tenantAnalysis['review_refresh']);
        $this->line('no_match tenant catalog product: ' . $tenantAnalysis['no_match']);
        $this->line('blocked tenant catalog product: ' . $tenantAnalysis['blocked']);
        $this->line('Eşleşme anahtarı dağılımı: ' . $this->formatDistribution($analysis['match_keys']));

        if ($applyResult) {
            $this->line('run_id: ' . $applyResult['run_id']);
            $this->line('updated_standard_product_count: ' . $applyResult['updated_standard_product_count']);
            $this->line('updated_tenant_catalog_count: ' . $applyResult['updated_tenant_catalog_count']);
            $this->line('skipped_no_match_count: ' . $analysis['no_match']);
            $this->line('skipped_review_count: ' . $analysis['review_refresh']);
            $this->line('skipped_blocked_count: ' . $analysis['blocked']);
            $this->line('Apply kategori dağılımı: ' . $this->formatDistribution($applyResult['category_distribution']));
            $this->line('Apply source dağılımı: ' . $this->formatDistribution($applyResult['source_distribution']));
        }

        $this->table(
            ['Tedarikçi', 'Approved mapping', 'Bekleyen ürün', 'Eşleşen ürün', 'Code', 'Path', 'Name', 'Meta/Raw', 'Eşleşmeyen'],
            collect($analysis['source_rows'])->values()->all()
        );

        $this->line('En çok görülen eşleşmeyen kategori değerleri: ' . $this->formatDistribution($analysis['unmatched_categories']));

        if ($analysis['examples'] !== []) {
            $this->line('Eşleşemeyen ürün örnekleri:');
            foreach ($analysis['examples'] as $example) {
                $this->line(sprintf(
                    '- [%s] %s | source:%s | category:%s | raw/meta:%s | neden:%s',
                    $example['product_code'],
                    $example['product_name'],
                    $example['source_id'] ?: '-',
                    $example['category'] ?: '-',
                    $example['signals'] ?: '-',
                    $example['reason']
                ));
            }
        }

        $this->line($apply ? 'Apply yapıldı; yalnız safe_refresh kayıtları güncellendi.' : 'Apply yapılmadı; ürün/projection verisi değiştirilmedi.');
        $this->line($apply
            ? 'Güncellenen alanlar: standard_category_id, kategori snapshot/meta ve tenant category snapshot.'
            : 'Değişecek alanlar sonraki apply fazında standard_category_id, kategori snapshot/meta ve tenant category snapshot olacaktır.');

        return self::SUCCESS;
    }

    private function approvedMappings(): Collection
    {
        return SupplierCategoryMapping::query()
            ->with(['supplier', 'source', 'standardCategory'])
            ->approved()
            ->whereNotNull('standard_category_id')
            ->when((bool) $this->option('only-approved'), fn (Builder $query) => $query->where('mapping_status', 'approved'))
            ->when($this->option('source'), fn (Builder $query, $source) => $query->where('supplier_source_id', (int) $source))
            ->when($this->option('supplier'), function (Builder $query, $supplier) {
                $query->whereHas('supplier', fn (Builder $supplierQuery) => $supplierQuery
                    ->whereKey(is_numeric($supplier) ? (int) $supplier : 0)
                    ->orWhere('code', $supplier));
            })
            ->when($this->option('category'), fn (Builder $query, $category) => $query->where('standard_category_id', (int) $category))
            ->get()
            ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->standardCategory?->isPermanentBackbone())
            ->values();
    }

    private function analyzeStandardProducts(Collection $mappings): array
    {
        $mappingIndex = $this->buildMappingIndex($mappings);
        $waitingQuery = StandardProduct::query()
            ->whereNull('standard_category_id')
            ->with(['rawProduct', 'rawProducts']);

        if ($this->option('source')) {
            $sourceId = (int) $this->option('source');
            $waitingQuery->where(function (Builder $query) use ($sourceId) {
                $query->whereHas('rawProduct', fn (Builder $raw) => $raw->where('supplier_source_id', $sourceId))
                    ->orWhereHas('rawProducts', fn (Builder $raw) => $raw->where('supplier_source_id', $sourceId));
            });
        }

        $products = $waitingQuery->get();
        $sourceRows = [];
        $matchKeys = [];
        $unmatchedCategories = [];
        $examples = [];
        $matchedProductIds = [];
        $matchedByProduct = [];
        $safe = 0;
        $review = 0;
        $blocked = 0;
        $noMatch = 0;

        foreach ($products as $product) {
            $signals = $this->productCategorySignals($product);
            $sourceId = $signals['source_id'];
            $supplierId = $product->supplier_id ?: $signals['supplier_id'];
            $supplierName = $this->supplierNameForSource($mappings, $sourceId, $supplierId);
            $rowKey = $supplierName . '|' . ($sourceId ?: 'no-source');
            $sourceRows[$rowKey] ??= [
                'supplier' => $supplierName,
                'approved_mapping' => $mappings->filter(fn (SupplierCategoryMapping $mapping) => $this->mappingBelongsTo($mapping, $sourceId, $supplierId))->count(),
                'waiting' => 0,
                'matched' => 0,
                'code' => 0,
                'path' => 0,
                'name' => 0,
                'meta' => 0,
                'unmatched' => 0,
            ];
            $sourceRows[$rowKey]['waiting']++;

            $match = $this->matchProductToMapping($signals, $mappingIndex, $sourceId, $supplierId);
            if ($match['status'] === 'safe_refresh') {
                $safe++;
                $sourceRows[$rowKey]['matched']++;
                $sourceRows[$rowKey][$this->sourceRowMatchKey($match['match_key'])]++;
                $matchKeys[$match['match_key']] = ($matchKeys[$match['match_key']] ?? 0) + 1;
                $matchedProductIds[] = $product->id;
                $matchedByProduct[$product->id] = array_merge($match, ['product_id' => $product->id, 'source_id' => $sourceId]);
                continue;
            }

            if ($match['status'] === 'review_refresh') {
                $review++;
                $sourceRows[$rowKey]['matched']++;
                $sourceRows[$rowKey][$this->sourceRowMatchKey($match['match_key'])]++;
                $matchKeys[$match['match_key']] = ($matchKeys[$match['match_key']] ?? 0) + 1;
                $matchedProductIds[] = $product->id;
                $matchedByProduct[$product->id] = array_merge($match, ['product_id' => $product->id, 'source_id' => $sourceId]);
                continue;
            }

            if ($match['status'] === 'blocked') {
                $blocked++;
            } else {
                $noMatch++;
                $sourceRows[$rowKey]['unmatched']++;
            }

            $categoryValue = $signals['first_category'] ?: 'kategori_yok';
            $unmatchedCategories[$categoryValue] = ($unmatchedCategories[$categoryValue] ?? 0) + 1;

            if (count($examples) < 40) {
                $examples[] = [
                    'product_code' => $product->standard_product_code ?: $product->sku ?: ('STD-' . $product->id),
                    'product_name' => $product->product_name ?: $product->name ?: '-',
                    'source_id' => $sourceId,
                    'category' => $signals['first_category'],
                    'signals' => implode(' | ', array_slice(array_unique(array_merge($signals['codes'], $signals['paths'], $signals['names'], $signals['meta_names'])), 0, 5)),
                    'reason' => $match['reason'],
                ];
            }
        }

        return [
            'waiting_standard' => $products->count(),
            'matched_standard' => $safe + $review,
            'safe_refresh' => $safe,
            'review_refresh' => $review,
            'no_match' => $noMatch,
            'blocked' => $blocked,
            'match_keys' => $matchKeys,
            'source_rows' => collect($sourceRows)->map(fn (array $row) => [
                $row['supplier'],
                $row['approved_mapping'],
                $row['waiting'],
                $row['matched'],
                $row['code'],
                $row['path'],
                $row['name'],
                $row['meta'],
                $row['unmatched'],
            ])->all(),
            'unmatched_categories' => collect($unmatchedCategories)->sortDesc()->take(12)->all(),
            'examples' => $examples,
            'matched_product_ids' => array_values(array_unique($matchedProductIds)),
            'matched_by_product' => $matchedByProduct,
        ];
    }

    private function analyzeTenantProducts(array $standardAnalysis): array
    {
        $waitingQuery = TenantCatalogProduct::query()->whereNull('standard_category_id');
        $waiting = $waitingQuery->count();
        $matchedIds = collect($standardAnalysis['matched_product_ids'] ?? []);
        $matchedTenant = $matchedIds->isEmpty()
            ? collect()
            : TenantCatalogProduct::query()
                ->whereNull('standard_category_id')
                ->whereIn('standard_product_id', $matchedIds)
                ->get(['id', 'standard_product_id']);

        $safe = 0;
        $review = 0;
        foreach ($matchedTenant as $tenantProduct) {
            $standardMatch = $standardAnalysis['matched_by_product'][$tenantProduct->standard_product_id] ?? null;
            if (($standardMatch['status'] ?? null) === 'safe_refresh') {
                $safe++;
            } elseif (($standardMatch['status'] ?? null) === 'review_refresh') {
                $review++;
            }
        }

        return [
            'waiting_tenant' => $waiting,
            'matched_tenant' => $matchedTenant->count(),
            'safe_refresh' => $safe,
            'review_refresh' => $review,
            'no_match' => max(0, $waiting - $matchedTenant->count()),
            'blocked' => 0,
        ];
    }

    private function applySafeRefresh(array $analysis, array $tenantAnalysis): array
    {
        $safeMatches = collect($analysis['matched_by_product'] ?? [])
            ->filter(fn (array $match) => ($match['status'] ?? null) === 'safe_refresh' && isset($match['mapping']));

        $runSource = $this->resolveRunSource($safeMatches);
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $runSource?->id,
            'supplier_id' => $runSource?->supplier_id,
            'run_type' => 'category_refresh',
            'started_at' => now(),
            'status' => 'running',
            'records_read' => $analysis['waiting_standard'],
            'category_changed_count' => 0,
            'triggered_by' => auth()->id(),
            'report_payload' => [
                'mode' => 'safe_category_refresh',
                'dry_run_safe_standard' => $analysis['safe_refresh'],
                'dry_run_safe_tenant' => $tenantAnalysis['safe_refresh'],
                'skipped_no_match' => $analysis['no_match'],
                'skipped_review' => $analysis['review_refresh'],
                'skipped_blocked' => $analysis['blocked'],
            ],
        ]);

        $updatedStandard = 0;
        $updatedTenant = 0;
        $sourceDistribution = [];
        $categoryDistribution = [];

        foreach ($safeMatches as $productId => $match) {
            /** @var SupplierCategoryMapping $mapping */
            $mapping = $match['mapping'];
            $category = $mapping->standardCategory;
            if (!$category?->isPermanentBackbone()) {
                continue;
            }

            $product = StandardProduct::query()->find($productId);
            if (!$product || filled($product->standard_category_id)) {
                continue;
            }

            $oldPayload = [
                'standard_category_id' => $product->standard_category_id,
                'category' => $product->category ?? null,
                'meta_category_snapshot' => data_get($product->meta, 'category_snapshot'),
            ];

            $this->applyStandardProductCategory($product, $mapping, $category, $match);
            $updatedStandard++;
            $sourceKey = (string) ($mapping->source?->supplier?->name ?? $mapping->supplier?->name ?? 'Tedarikçi');
            $categoryKey = $category->full_path;
            $sourceDistribution[$sourceKey] = ($sourceDistribution[$sourceKey] ?? 0) + 1;
            $categoryDistribution[$categoryKey] = ($categoryDistribution[$categoryKey] ?? 0) + 1;

            ProductDataHubSyncChange::query()->create([
                'sync_run_id' => $run->id,
                'supplier_source_id' => $mapping->supplier_source_id,
                'supplier_product_key' => $product->standard_product_code ?: $product->sku,
                'standard_product_id' => $product->id,
                'change_type' => 'category_refresh',
                'old_value' => $oldPayload,
                'new_value' => [
                    'standard_category_id' => $category->id,
                    'category_path' => $category->full_path,
                    'mapping_id' => $mapping->id,
                    'match_key' => $match['match_key'] ?? null,
                ],
                'message' => 'Safe category refresh standard product güncellendi.',
            ]);
        }

        $safeProductIds = $safeMatches->keys()->map(fn ($id) => (int) $id)->values();
        if ($safeProductIds->isNotEmpty()) {
            TenantCatalogProduct::query()
                ->whereNull('standard_category_id')
                ->whereIn('standard_product_id', $safeProductIds)
                ->get()
                ->each(function (TenantCatalogProduct $catalogProduct) use ($safeMatches, $run, &$updatedTenant) {
                    $match = $safeMatches->get($catalogProduct->standard_product_id);
                    if (!$match || ($match['status'] ?? null) !== 'safe_refresh') {
                        return;
                    }

                    /** @var SupplierCategoryMapping $mapping */
                    $mapping = $match['mapping'];
                    $category = $mapping->standardCategory;
                    if (!$category?->isPermanentBackbone()) {
                        return;
                    }

                    $oldPayload = [
                        'standard_category_id' => $catalogProduct->standard_category_id,
                        'meta_category_snapshot' => data_get($catalogProduct->meta, 'category_snapshot'),
                        'warning_snapshot' => data_get($catalogProduct->meta, 'warning_snapshot'),
                    ];

                    $this->applyTenantCatalogCategory($catalogProduct, $mapping, $category, $match);
                    $updatedTenant++;

                    ProductDataHubSyncChange::query()->create([
                        'sync_run_id' => $run->id,
                        'supplier_source_id' => $mapping->supplier_source_id,
                        'supplier_product_key' => $catalogProduct->product_code ?: $catalogProduct->tenant_sku,
                        'standard_product_id' => $catalogProduct->standard_product_id,
                        'change_type' => 'tenant_category_refresh',
                        'old_value' => $oldPayload,
                        'new_value' => [
                            'standard_category_id' => $category->id,
                            'category_path' => $category->full_path,
                            'mapping_id' => $mapping->id,
                            'match_key' => $match['match_key'] ?? null,
                        ],
                        'message' => 'Safe category refresh tenant catalog güncellendi.',
                    ]);
                });
        }

        $run->update([
            'finished_at' => now(),
            'status' => 'success',
            'products_updated' => $updatedStandard,
            'category_changed_count' => $updatedStandard + $updatedTenant,
            'report_payload' => array_merge((array) $run->report_payload, [
                'updated_standard_product_count' => $updatedStandard,
                'updated_tenant_catalog_count' => $updatedTenant,
                'source_distribution' => $sourceDistribution,
                'category_distribution' => $categoryDistribution,
                'applied_at' => now()->toDateTimeString(),
            ]),
        ]);

        return [
            'run_id' => $run->id,
            'updated_standard_product_count' => $updatedStandard,
            'updated_tenant_catalog_count' => $updatedTenant,
            'source_distribution' => $sourceDistribution,
            'category_distribution' => $categoryDistribution,
        ];
    }

    private function resolveRunSource(Collection $safeMatches): ?SupplierSource
    {
        $mapping = $safeMatches
            ->pluck('mapping')
            ->filter()
            ->first();

        if ($mapping?->supplier_source_id) {
            return SupplierSource::query()->find($mapping->supplier_source_id);
        }

        return SupplierSource::query()->orderBy('id')->first();
    }

    private function applyStandardProductCategory(
        StandardProduct $product,
        SupplierCategoryMapping $mapping,
        object $category,
        array $match
    ): void {
        $meta = (array) ($product->meta ?? []);
        $sourceSummary = (array) ($product->source_summary ?? []);
        $snapshot = $this->categorySnapshot($mapping, $category, $match);

        $meta['previous_category_snapshot'] = [
            'standard_category_id' => $product->standard_category_id,
            'category' => $product->category ?? null,
            'category_snapshot' => $meta['category_snapshot'] ?? null,
        ];
        $meta['category_snapshot'] = $snapshot;
        $meta['category_status'] = 'mapped';
        $meta['category_mapped_at'] = now()->toDateTimeString();
        $meta['category_mapping_id'] = $mapping->id;
        $meta['category_refresh_source'] = 'safe_mapping_refresh';

        $sourceSummary['category_snapshot'] = $snapshot;

        $payload = [
            'standard_category_id' => $category->id,
            'category' => $category->full_path,
            'product_family' => $category->product_family ?? $product->product_family,
            'meta' => $meta,
            'source_summary' => $sourceSummary,
        ];

        foreach ([
            'category_id' => $category->id,
            'category_name' => $category->name,
            'category_path' => $category->full_path,
            'category_status' => 'mapped',
            'category_mapped_at' => now(),
            'category_mapping_id' => $mapping->id,
            'category_refresh_source' => 'safe_mapping_refresh',
        ] as $column => $value) {
            if (Schema::hasColumn('standard_products', $column)) {
                $payload[$column] = $value;
            }
        }

        $product->forceFill($payload)->save();
    }

    private function applyTenantCatalogCategory(
        TenantCatalogProduct $catalogProduct,
        SupplierCategoryMapping $mapping,
        object $category,
        array $match
    ): void {
        $meta = (array) ($catalogProduct->meta ?? []);
        $sourceSummary = (array) ($catalogProduct->source_summary ?? []);
        $snapshot = $this->categorySnapshot($mapping, $category, $match);

        $meta['previous_category_snapshot'] = [
            'standard_category_id' => $catalogProduct->standard_category_id,
            'category_snapshot' => $meta['category_snapshot'] ?? null,
        ];
        $meta['category_snapshot'] = $snapshot;
        $meta['category_status'] = 'mapped';
        $meta['category_mapped_at'] = now()->toDateTimeString();
        $meta['category_mapping_id'] = $mapping->id;
        $meta['category_refresh_source'] = 'safe_mapping_refresh';
        $meta['warnings'] = $this->removeCategoryMissingWarning($meta['warnings'] ?? []);
        $meta['warning_snapshot'] = $this->removeCategoryMissingWarning($meta['warning_snapshot'] ?? []);

        $sourceSummary['category_snapshot'] = $snapshot;

        $payload = [
            'standard_category_id' => $category->id,
            'product_family' => $category->product_family ?? $catalogProduct->product_family,
            'meta' => $meta,
            'source_summary' => $sourceSummary,
        ];

        foreach ([
            'category_id' => $category->id,
            'category_name' => $category->name,
            'category_path' => $category->full_path,
            'category_status' => 'mapped',
            'category_mapped_at' => now(),
            'category_mapping_id' => $mapping->id,
        ] as $column => $value) {
            if (Schema::hasColumn('tenant_catalog_products', $column)) {
                $payload[$column] = $value;
            }
        }

        $catalogProduct->forceFill($payload)->save();
    }

    private function categorySnapshot(SupplierCategoryMapping $mapping, object $category, array $match): array
    {
        return [
            'standard_category_id' => $category->id,
            'category_code' => $category->code,
            'category_name' => $category->name,
            'category_path' => $category->full_path,
            'product_family' => $category->product_family,
            'mapping_id' => $mapping->id,
            'supplier_source_id' => $mapping->supplier_source_id,
            'supplier_category' => $mapping->source_category,
            'match_key' => $match['match_key'] ?? null,
            'refreshed_at' => now()->toDateTimeString(),
        ];
    }

    private function removeCategoryMissingWarning(mixed $warnings): mixed
    {
        if (!is_array($warnings)) {
            return $warnings === 'category_missing' ? null : $warnings;
        }

        return collect($warnings)
            ->reject(function ($warning, $key) {
                $text = is_scalar($warning) ? (string) $warning : json_encode($warning, JSON_UNESCAPED_UNICODE);
                return $key === 'category_missing'
                    || str_contains((string) $key, 'category_missing')
                    || str_contains((string) $text, 'category_missing')
                    || str_contains(Str::lower((string) $text), 'kategori eksik');
            })
            ->values()
            ->all();
    }

    private function buildMappingIndex(Collection $mappings): array
    {
        $index = [];

        foreach ($mappings as $mapping) {
            $keys = [$this->sourceIndexKey($mapping->supplier_source_id, $mapping->supplier_id)];
            if (blank($mapping->supplier_source_id)) {
                $keys[] = $this->sourceIndexKey(null, $mapping->supplier_id);
            }

            foreach (array_unique($keys) as $key) {
                $index[$key] ??= [
                    'code' => [],
                    'path' => [],
                    'name' => [],
                    'normalized' => [],
                ];

                foreach ($this->mappingCodes($mapping) as $code) {
                    $index[$key]['code'][$this->normalizeKey($code)][] = $mapping;
                }

                foreach ($this->mappingPaths($mapping) as $path) {
                    $index[$key]['path'][$this->normalizeKey($path)][] = $mapping;
                }

                foreach ($this->mappingNames($mapping) as $name) {
                    $index[$key]['name'][$this->normalizeKey($name)][] = $mapping;
                    $index[$key]['normalized'][$this->normalizeText($name)][] = $mapping;
                }

                if (filled($mapping->normalized_name)) {
                    $index[$key]['normalized'][$this->normalizeText((string) $mapping->normalized_name)][] = $mapping;
                }
            }
        }

        return $index;
    }

    private function matchProductToMapping(array $signals, array $mappingIndex, ?int $sourceId, ?int $supplierId): array
    {
        if (!$sourceId && !$supplierId) {
            return ['status' => 'no_match', 'match_key' => 'none', 'reason' => 'Üründe supplier source/supplier bilgisi yok.'];
        }

        $keys = array_unique(array_filter([
            $this->sourceIndexKey($sourceId, $supplierId),
            $this->sourceIndexKey(null, $supplierId),
        ]));

        $checks = [
            ['bucket' => 'code', 'values' => $signals['codes'], 'match_key' => 'code_exact', 'status' => 'safe_refresh'],
            ['bucket' => 'path', 'values' => $signals['paths'], 'match_key' => 'path_exact', 'status' => 'safe_refresh'],
            ['bucket' => 'name', 'values' => $signals['names'], 'match_key' => 'name_exact', 'status' => 'safe_refresh'],
            ['bucket' => 'normalized', 'values' => array_merge($signals['names'], $signals['meta_names']), 'match_key' => 'normalized_name', 'status' => 'safe_refresh'],
            ['bucket' => 'name', 'values' => $signals['meta_names'], 'match_key' => 'meta_name', 'status' => 'review_refresh'],
        ];

        foreach ($checks as $check) {
            foreach ($keys as $key) {
                foreach ($check['values'] as $value) {
                    $lookup = $check['bucket'] === 'normalized' ? $this->normalizeText($value) : $this->normalizeKey($value);
                    $candidates = $mappingIndex[$key][$check['bucket']][$lookup] ?? [];
                    $candidates = collect($candidates)
                        ->unique('id')
                        ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->standardCategory?->isPermanentBackbone())
                        ->values();

                    if ($candidates->count() === 1) {
                        return [
                            'status' => $check['status'],
                            'match_key' => $check['match_key'],
                            'mapping' => $candidates->first(),
                            'reason' => $check['match_key'] . ' ile eşleşti.',
                        ];
                    }

                    if ($candidates->count() > 1) {
                        return [
                            'status' => 'review_refresh',
                            'match_key' => $check['match_key'] . '_multiple',
                            'mapping' => $candidates->first(),
                            'reason' => 'Birden fazla mapping adayı bulundu.',
                        ];
                    }
                }
            }
        }

        return ['status' => 'no_match', 'match_key' => 'none', 'reason' => 'Ürün kategori sinyali approved mapping ile eşleşmedi.'];
    }

    private function productCategorySignals(StandardProduct $product): array
    {
        $raws = collect();
        if ($product->relationLoaded('rawProduct') && $product->rawProduct) {
            $raws->push($product->rawProduct);
        } elseif ($product->supplier_product_raw_id) {
            $raw = SupplierProductRaw::query()->find($product->supplier_product_raw_id);
            if ($raw) {
                $raws->push($raw);
            }
        }

        if ($product->relationLoaded('rawProducts')) {
            $raws = $raws->merge($product->rawProducts);
        }

        $raws = $raws->unique('id')->values();
        $sourceId = $raws->pluck('supplier_source_id')->filter()->first()
            ?: data_get($product->source_summary, '0.supplier_source_id')
            ?: data_get($product->meta, 'source_supplier_source_id');
        $supplierId = $raws->pluck('supplier_id')->filter()->first()
            ?: $product->supplier_id
            ?: data_get($product->source_summary, '0.supplier_id');

        $codes = [];
        $paths = [];
        $names = [];
        $metaNames = [];

        foreach ($raws as $raw) {
            $codes = array_merge($codes, $this->rawCategoryCodes($raw));
            $paths = array_merge($paths, $this->rawCategoryPaths($raw));
            $names = array_merge($names, $this->rawCategoryNames($raw));
        }

        $names = array_merge($names, $this->valuesFrom([
            $product->category ?? null,
            data_get($product->source_summary, '0.supplier_category_name'),
            data_get($product->source_summary, '0.category_name'),
        ]));
        $paths = array_merge($paths, $this->valuesFrom([
            data_get($product->source_summary, '0.supplier_category_path'),
            data_get($product->source_summary, '0.category_path'),
        ]));
        $metaNames = array_merge($metaNames, $this->valuesFrom([
            data_get($product->meta, 'supplier_category_name'),
            data_get($product->meta, 'category_name'),
            data_get($product->meta, 'normalized_payload.supplier_category_name'),
            data_get($product->meta, 'normalized_payload.category_name'),
            data_get($product->meta, 'normalized_payload.supplier_category_path'),
        ]));

        $all = array_values(array_filter(array_unique(array_merge($codes, $paths, $names, $metaNames))));

        return [
            'source_id' => $sourceId ? (int) $sourceId : null,
            'supplier_id' => $supplierId ? (int) $supplierId : null,
            'codes' => array_values(array_filter(array_unique($codes))),
            'paths' => array_values(array_filter(array_unique($paths))),
            'names' => array_values(array_filter(array_unique($names))),
            'meta_names' => array_values(array_filter(array_unique($metaNames))),
            'first_category' => $all[0] ?? null,
        ];
    }

    private function rawCategoryCodes(SupplierProductRaw $raw): array
    {
        return $this->valuesFrom([
            data_get($raw->normalized_payload, 'supplier_category_id'),
            data_get($raw->normalized_payload, 'category_id'),
            data_get($raw->raw_payload, 'kategori_id'),
            data_get($raw->raw_payload, 'kid'),
            data_get($raw->source_attributes, 'category_id'),
            data_get($raw->source_attributes, 'kategori_id'),
        ]);
    }

    private function rawCategoryPaths(SupplierProductRaw $raw): array
    {
        $main = data_get($raw->raw_payload, 'KategoriMain') ?: data_get($raw->normalized_payload, 'supplier_main_category_name');
        $sub = data_get($raw->raw_payload, 'KategoriSub')
            ?: data_get($raw->normalized_payload, 'supplier_subcategory_name')
            ?: $raw->supplier_category_name
            ?: $raw->source_category;

        return $this->valuesFrom([
            data_get($raw->normalized_payload, 'supplier_category_path'),
            data_get($raw->normalized_payload, 'category_path'),
            data_get($raw->raw_payload, 'supplier_category_path'),
            filled($main) && filled($sub) && $main !== $sub ? $main . ' > ' . $sub : null,
        ]);
    }

    private function rawCategoryNames(SupplierProductRaw $raw): array
    {
        return $this->valuesFrom([
            $raw->supplier_category_name,
            $raw->source_category,
            data_get($raw->normalized_payload, 'supplier_category_name'),
            data_get($raw->normalized_payload, 'category_name'),
            data_get($raw->raw_payload, 'kategori_adi'),
            data_get($raw->raw_payload, 'kategori'),
            data_get($raw->raw_payload, 'urun_kategori'),
            data_get($raw->raw_payload, 'KategoriSub'),
            data_get($raw->raw_payload, 'KategoriMain'),
            data_get($raw->source_attributes, 'category'),
            data_get($raw->source_attributes, 'kategori'),
        ]);
    }

    private function mappingCodes(SupplierCategoryMapping $mapping): array
    {
        return $this->valuesFrom([$mapping->supplier_category_code]);
    }

    private function mappingPaths(SupplierCategoryMapping $mapping): array
    {
        return $this->valuesFrom([$mapping->supplier_category_path]);
    }

    private function mappingNames(SupplierCategoryMapping $mapping): array
    {
        return $this->valuesFrom([$mapping->source_category, $mapping->target_category]);
    }

    private function valuesFrom(array $values): array
    {
        return collect($values)
            ->flatten()
            ->map(fn ($value) => is_scalar($value) ? trim((string) $value) : null)
            ->filter(fn (?string $value) => filled($value))
            ->unique()
            ->values()
            ->all();
    }

    private function sourceIndexKey(?int $sourceId, ?int $supplierId): string
    {
        return ($sourceId ?: 'any') . ':' . ($supplierId ?: 'any');
    }

    private function normalizeKey(string $value): string
    {
        return $this->normalizeText($value);
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($value))) ?: '');
    }

    private function sourceRowMatchKey(string $matchKey): string
    {
        return match (true) {
            str_starts_with($matchKey, 'code') => 'code',
            str_starts_with($matchKey, 'path') => 'path',
            str_starts_with($matchKey, 'name'),
            str_starts_with($matchKey, 'normalized') => 'name',
            default => 'meta',
        };
    }

    private function mappingBelongsTo(SupplierCategoryMapping $mapping, ?int $sourceId, ?int $supplierId): bool
    {
        if ($sourceId && (int) $mapping->supplier_source_id === $sourceId) {
            return true;
        }

        return $supplierId && (int) $mapping->supplier_id === $supplierId;
    }

    private function supplierNameForSource(Collection $mappings, ?int $sourceId, ?int $supplierId): string
    {
        $mapping = $mappings->first(fn (SupplierCategoryMapping $item) => $this->mappingBelongsTo($item, $sourceId, $supplierId));

        return $mapping?->supplier?->name ?? ($supplierId ? 'Supplier #' . $supplierId : 'Kaynak bilinmiyor');
    }

    private function formatDistribution(array $distribution): string
    {
        if ($distribution === []) {
            return '-';
        }

        return collect($distribution)
            ->sortDesc()
            ->take(12)
            ->map(fn ($count, $key) => "{$key}: {$count}")
            ->implode(', ');
    }
}
