<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SupplierSourceSyncService
{
    public function __construct(
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser,
        private readonly PreviewParserService $previewParser,
        private readonly RawProductStagingService $rawProductStaging,
        private readonly StandardProductBuilderService $standardProductBuilder,
        private readonly TenantCatalogProjectionService $tenantCatalogProjection,
        private readonly DeltaSyncHashService $deltaHashService,
        private readonly DeltaChangeDetectorService $deltaChangeDetector,
    ) {
    }

    public function syncSource(SupplierSource $source, array $options = []): array
    {
        $mode = (string) ($options['mode'] ?? 'full');
        if (($options['no_project'] ?? false) === true && ($options['project_dirty'] ?? false) === true) {
            return $mode === 'delta'
                ? $this->createTransientFailedRun($source, ['--no-project ile --project-dirty birlikte kullanılamaz.'], (string) ($options['run_type'] ?? 'manual'), $options)
                : $this->createFailedRun($source, ['--no-project ile --project-dirty birlikte kullanılamaz.'], (string) ($options['run_type'] ?? 'manual'), $options);
        }
        $fetchResult = $this->sourceFetch->fetch($source);
        $runType = (string) ($options['run_type'] ?? 'manual');

        if (!$fetchResult['ok']) {
            return $mode === 'delta'
                ? $this->createTransientFailedRun($source, $fetchResult['errors'] ?? ['Kaynak okunamadı.'], $runType, $options)
                : $this->createFailedRun($source, $fetchResult['errors'] ?? ['Kaynak okunamadı.'], $runType, $options);
        }

        $parserResult = $this->sourceParser->parse($source, (string) $fetchResult['content'], 0);

        if (!$parserResult['ok']) {
            return $mode === 'delta'
                ? $this->createTransientFailedRun($source, $parserResult['errors'] ?? ['Kaynak ayrıştırılamadı.'], $runType, $options)
                : $this->createFailedRun($source, $parserResult['errors'] ?? ['Kaynak ayrıştırılamadı.'], $runType, $options);
        }

        // Sync/delta zinciri fiyat-stok tazeliği için feed parse sonucunu hızlı ve deterministik
        // kullanmalı; ürün sayfası galeri zenginleştirmesi preview UI'da kalır.
        $preview = $this->previewParser->previewSource($source, $parserResult['rows'], [
            'allow_gallery_enrichment' => false,
        ]);

        if (($preview['source_mode'] ?? 'demo_fallback') !== 'live_source') {
            return $mode === 'delta'
                ? $this->createTransientFailedRun($source, ['Demo önizleme ile senkron yapılamaz.'], $runType, $options)
                : $this->createFailedRun($source, ['Demo önizleme ile senkron yapılamaz.'], $runType, $options);
        }

        if ($mode === 'delta') {
            if (($options['apply_price_stock'] ?? false) === true) {
                return $this->applyDeltaPriceStockPreviewData($source, $preview, $runType, $options);
            }

            if (($options['dry_run'] ?? false) !== true) {
                return $this->createTransientFailedRun($source, ['Delta modu bu fazda dry-run veya apply-price-stock ile çalıştırılmalıdır.'], $runType, $options);
            }

            return $this->dryRunDeltaPreviewData($source, $preview, $runType, $options);
        }

        if (($options['dry_run'] ?? false) === true) {
            return $this->dryRunPreviewData($source, $preview, $runType, $options);
        }

        return $this->syncPreviewData($source, $preview, $runType, $options);
    }

    public function syncPreviewData(SupplierSource $source, array $previewData, string $runType = 'manual', array $options = []): array
    {
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_RUNNING,
            'triggered_by' => auth()->id(),
        ]);

        $policy = $this->resolveSyncPolicy($source);
        $products = collect($previewData['products'] ?? []);
        $variants = collect($previewData['variants'] ?? []);
        [$products, $variants] = $this->attachDeltaHashes($products, $variants);
        $existingProducts = SupplierProductRaw::query()
            ->where('supplier_source_id', $source->id)
            ->get();
        $existingVariants = SupplierProductVariantRaw::query()
            ->where('supplier_source_id', $source->id)
            ->get();

        $productLookup = $existingProducts->mapWithKeys(fn (SupplierProductRaw $product) => [$this->productMatchKeyFromRaw($product) => $product])
            ->filter(fn ($product, $key) => filled($key));
        $variantLookup = $existingVariants->mapWithKeys(fn (SupplierProductVariantRaw $variant) => [$this->variantMatchKeyFromRaw($variant) => $variant])
            ->filter(fn ($variant, $key) => filled($key));

        $stats = [
            'records_read' => (int) data_get($previewData, 'stats.records_read', $products->count()),
            'products_created' => 0,
            'products_updated' => 0,
            'products_unchanged' => 0,
            'products_missing_from_feed' => 0,
            'products_inactivated' => 0,
            'price_changed_count' => 0,
            'stock_changed_count' => 0,
            'image_changed_count' => 0,
            'category_changed_count' => 0,
            'name_changed_count' => 0,
            'description_changed_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
        ];

        $seenProductIds = [];
        $seenVariantIds = [];
        $productMap = [];

        foreach ($products as $productRow) {
            $matchKey = $this->productMatchKeyFromRow($productRow);
            $existing = $matchKey ? ($productLookup[$matchKey] ?? null) : null;
            $before = $existing ? $this->snapshotProduct($existing) : null;

            if ($existing) {
                $productRow['import_hash'] = $existing->import_hash;
                $seenProductIds[] = $existing->id;
            }

            $product = $this->rawProductStaging->stageProduct($source, $productRow, null);
            $productMap[$productRow['import_hash']] = $product;
            $after = $this->snapshotProduct($product->fresh());

            $stats['warning_count'] += count($productRow['warnings'] ?? []);
            $stats['error_count'] += count($productRow['errors'] ?? []);

            if ($product->wasRecentlyCreated) {
                $stats['products_created']++;
                $this->recordChange($run, $source, $after['match_key'], 'created', null, $after, 'Yeni ürün kaydı oluşturuldu.');
                continue;
            }

            $changes = $this->detectProductChanges($before ?? [], $after);

            if ($changes === []) {
                $stats['products_unchanged']++;
                $this->recordChange($run, $source, $after['match_key'], 'unchanged', $before, $after, 'Ürün değişmedi.');
                continue;
            }

            $stats['products_updated']++;
            foreach ($changes as $changeType => $payload) {
                $stats[$payload['counter']]++;
                $this->recordChange($run, $source, $after['match_key'], $changeType, $payload['old'], $payload['new'], $payload['message']);
            }
        }

        foreach ($variants as $variantRow) {
            $matchKey = $this->variantMatchKeyFromRow($variantRow);
            $existing = $matchKey ? ($variantLookup[$matchKey] ?? null) : null;

            if ($existing) {
                $variantRow['import_hash'] = $existing->import_hash;
                $seenVariantIds[] = $existing->id;
            }

            $this->rawProductStaging->stageVariant($source, $variantRow, null, $productMap, $products->all());
        }

        $existingProducts
            ->reject(fn (SupplierProductRaw $product) => in_array($product->id, $seenProductIds, true))
            ->each(function (SupplierProductRaw $product) use ($policy, $run, $source, &$stats) {
                $meta = (array) ($product->normalized_payload ?? []);
                $syncMeta = (array) ($meta['_sync_meta'] ?? []);
                $missingRuns = (int) ($syncMeta['missing_feed_run_count'] ?? 0) + 1;
                $policyName = $policy['missing_product_policy'] ?? 'manual_review';
                $graceRuns = (int) ($policy['missing_product_grace_runs'] ?? 1);
                $status = match ($policyName) {
                    'never' => 'processed',
                    'inactive_candidate', 'auto_inactive' => $missingRuns >= max(1, $graceRuns) ? 'inactive_candidate' : 'missing_from_feed',
                    default => 'missing_from_feed',
                };

                $product->update([
                    'sync_status' => 'skipped',
                    'normalized_payload' => array_merge($meta, [
                        '_sync_meta' => array_merge($syncMeta, [
                            'missing_feed_run_count' => $missingRuns,
                            'last_sync_status' => $status,
                        ]),
                    ]),
                ]);

                $stats['products_missing_from_feed']++;
                if ($status === 'inactive_candidate') {
                    $stats['products_inactivated']++;
                }

                $this->recordChange(
                    $run,
                    $source,
                    $this->productMatchKeyFromRaw($product),
                    'missing_from_feed',
                    ['sync_status' => $product->getOriginal('sync_status')],
                    ['sync_status' => 'skipped', 'policy_status' => $status],
                    'Ürün yeni XML beslemesinde bulunamadı; silinmeden kontrol kuyruğuna alındı.'
                );
            });

        $buildStats = $this->shouldAutoBuild($source, $options)
            ? $this->standardProductBuilder->buildManyFromSource($source)
            : [
                'processed' => 0,
                'variants' => 0,
                'created_products' => 0,
                'updated_products' => 0,
                'created_variants' => 0,
                'updated_variants' => 0,
                'warnings' => 0,
                'errors' => 0,
                'skipped' => 0,
            ];

        $projectionStats = $this->shouldAutoProject($source, $options)
            ? $this->projectSourceProductsToTenants($source)
            : [
                'products' => 0,
                'variants' => 0,
                'warnings' => 0,
                'created_products' => 0,
                'updated_products' => 0,
                'inactive_candidates' => 0,
                'blocked_missing_category' => 0,
                'blocked_missing_price' => 0,
                'blocked_conflict_category' => 0,
                'blocked_projection_errors' => 0,
                'projected_with_warnings' => 0,
            ];

        $reportPayload = [
            'source_name' => $source->source_name,
            'supplier_name' => $source->supplier?->name,
            'policy' => $policy,
            'products' => $products->count(),
            'variants' => $variants->count(),
            'build' => $buildStats,
            'projection' => $projectionStats,
        ];

        $run->update(array_merge($stats, [
            'status' => $stats['error_count'] > 0
                ? ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS
                : ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => $reportPayload,
        ]));

        $source->update(['last_sync_at' => now()]);

        return ['run' => $run->fresh(), 'stats' => $stats];
    }

    private function createFailedRun(
        SupplierSource $source,
        array $errors,
        string $runType = 'manual',
        array $options = [],
        array $reportPayload = []
    ): array
    {
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'finished_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_FAILED,
            'error_count' => count($errors),
            'error_message' => implode(' | ', $errors),
            'triggered_by' => auth()->id(),
            'report_payload' => array_merge([
                'mode' => (string) ($options['mode'] ?? 'full'),
                'dry_run' => (bool) ($options['dry_run'] ?? false),
                'options' => Arr::only($options, ['mode', 'dry_run', 'apply_price_stock', 'no_build', 'no_project', 'force']),
            ], $reportPayload),
        ]);

        return ['run' => $run, 'stats' => ['error_count' => count($errors)]];
    }

    private function detectProductChanges(array $before, array $after): array
    {
        $changes = [];
        $comparisons = [
            'price_changed' => ['field' => 'list_price', 'counter' => 'price_changed_count', 'message' => 'Liste fiyatı güncellendi.'],
            'stock_changed' => ['field' => 'stock_quantity', 'counter' => 'stock_changed_count', 'message' => 'Stok bilgisi güncellendi.'],
            'image_changed' => ['field' => 'image_url', 'counter' => 'image_changed_count', 'message' => 'Ana görsel güncellendi.'],
            'category_changed' => ['field' => 'supplier_category_name', 'counter' => 'category_changed_count', 'message' => 'Kategori bilgisi güncellendi.'],
            'name_changed' => ['field' => 'product_name', 'counter' => 'name_changed_count', 'message' => 'Ürün adı güncellendi.'],
            'description_changed' => ['field' => 'description', 'counter' => 'description_changed_count', 'message' => 'Açıklama güncellendi.'],
        ];

        foreach ($comparisons as $changeType => $config) {
            $field = $config['field'];
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$changeType] = [
                    'counter' => $config['counter'],
                    'message' => $config['message'],
                    'old' => [$field => $before[$field] ?? null],
                    'new' => [$field => $after[$field] ?? null],
                ];
            }
        }

        return $changes;
    }

    private function recordChange(ProductDataHubSyncRun $run, SupplierSource $source, ?string $supplierProductKey, string $changeType, ?array $oldValue, ?array $newValue, string $message): void
    {
        ProductDataHubSyncChange::query()->create([
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'supplier_product_key' => $supplierProductKey,
            'change_type' => $changeType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'message' => $message,
        ]);
    }

    private function recordReviewChange(
        ProductDataHubSyncRun $run,
        SupplierSource $source,
        string $changeType,
        ?string $supplierProductKey,
        ?array $oldValue,
        ?array $newValue,
        string $message,
        array $attributes = []
    ): ProductDataHubSyncChange {
        $record = ProductDataHubSyncChange::query()
            ->openReview()
            ->where('supplier_source_id', $source->id)
            ->where('change_type', $changeType)
            ->where('supplier_product_key', $supplierProductKey)
            ->first();

        if (!$record) {
            $record = new ProductDataHubSyncChange([
                'sync_run_id' => $run->id,
                'supplier_source_id' => $source->id,
                'supplier_product_key' => $supplierProductKey,
                'change_type' => $changeType,
            ]);
        }

        $record->fill(array_merge([
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'supplier_product_key' => $supplierProductKey,
            'change_type' => $changeType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'message' => $message,
        ], $attributes));
        $record->save();

        return $record;
    }

    private function snapshotProduct(SupplierProductRaw $product): array
    {
        return [
            'match_key' => $this->productMatchKeyFromRaw($product),
            'product_name' => $product->product_name,
            'supplier_category_name' => $product->supplier_category_name,
            'stock_quantity' => $product->stock_quantity,
            'list_price' => data_get($product->normalized_payload, 'list_price'),
            'image_url' => $product->image_url,
            'description' => $product->description,
        ];
    }

    private function productMatchKeyFromRow(array $row): ?string
    {
        return $row['supplier_product_id']
            ?? $row['supplier_product_code']
            ?? $row['supplier_group_code']
            ?? null;
    }

    private function productMatchKeyFromRaw(SupplierProductRaw $product): ?string
    {
        return $product->supplier_product_id
            ?: $product->supplier_product_code
            ?: $product->supplier_group_code;
    }

    private function variantMatchKeyFromRow(array $row): ?string
    {
        if (!empty($row['variant_id'])) {
            return 'variant:' . $row['variant_id'];
        }

        if (!empty($row['supplier_group_code']) && !empty($row['variant_stock_code'])) {
            return 'group-stock:' . $row['supplier_group_code'] . ':' . $row['variant_stock_code'];
        }

        if (!empty($row['variant_stock_code'])) {
            return 'stock:' . $row['variant_stock_code'];
        }

        return null;
    }

    private function variantMatchKeyFromRaw(SupplierProductVariantRaw $variant): ?string
    {
        if (!empty($variant->variant_id)) {
            return 'variant:' . $variant->variant_id;
        }

        if (!empty($variant->supplier_group_code) && !empty($variant->variant_stock_code)) {
            return 'group-stock:' . $variant->supplier_group_code . ':' . $variant->variant_stock_code;
        }

        if (!empty($variant->variant_stock_code)) {
            return 'stock:' . $variant->variant_stock_code;
        }

        return null;
    }

    private function resolveSyncPolicy(SupplierSource $source): array
    {
        return array_merge([
            'sync_frequency' => data_get($source->config, 'sync_frequency', 'manual'),
            'update_stock' => true,
            'update_price' => true,
            'update_images' => true,
            'update_categories' => true,
            'missing_product_policy' => 'manual_review',
            'missing_product_grace_runs' => 1,
            'report_enabled' => true,
            'report_channel' => 'screen',
            'sync_auto_build' => (bool) data_get($source->config, 'sync_auto_build', true),
            'sync_auto_project_to_tenant_catalog' => (bool) data_get($source->config, 'sync_auto_project_to_tenant_catalog', true),
            'sync_block_on_missing_category' => false,
            'missing_category_policy' => data_get($source->config, 'missing_category_policy', 'warn_and_project'),
            'sync_block_on_missing_price' => (bool) data_get($source->config, 'sync_block_on_missing_price', false),
            'sync_block_on_conflict_category' => (bool) data_get($source->config, 'sync_block_on_conflict_category', true),
            'sync_allow_warning_products_to_catalog' => (bool) data_get($source->config, 'sync_allow_warning_products_to_catalog', true),
        ], (array) data_get($source->config, 'sync_policy', []));
    }

    private function shouldAutoBuild(SupplierSource $source, array $options = []): bool
    {
        if (($options['no_build'] ?? false) === true) {
            return false;
        }

        return (bool) data_get($source->config, 'sync_auto_build', true);
    }

    private function shouldAutoProject(SupplierSource $source, array $options = []): bool
    {
        if (($options['no_project'] ?? false) === true) {
            return false;
        }

        return (bool) data_get($source->config, 'sync_auto_project_to_tenant_catalog', true);
    }

    private function dryRunPreviewData(SupplierSource $source, array $previewData, string $runType = 'scheduled', array $options = []): array
    {
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_RUNNING,
            'triggered_by' => auth()->id(),
        ]);

        $products = collect($previewData['products'] ?? []);
        $variants = collect($previewData['variants'] ?? []);
        [$products, $variants] = $this->attachDeltaHashes($products, $variants);
        $existingProducts = SupplierProductRaw::query()
            ->where('supplier_source_id', $source->id)
            ->get();

        $productLookup = $existingProducts->mapWithKeys(fn (SupplierProductRaw $product) => [$this->productMatchKeyFromRaw($product) => $product])
            ->filter(fn ($product, $key) => filled($key));

        $stats = [
            'records_read' => (int) data_get($previewData, 'stats.records_read', $products->count()),
            'products_created' => 0,
            'products_updated' => 0,
            'products_unchanged' => 0,
            'products_missing_from_feed' => 0,
            'products_inactivated' => 0,
            'price_changed_count' => 0,
            'stock_changed_count' => 0,
            'image_changed_count' => 0,
            'category_changed_count' => 0,
            'name_changed_count' => 0,
            'description_changed_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
        ];

        $seenKeys = [];

        foreach ($products as $productRow) {
            $matchKey = $this->productMatchKeyFromRow($productRow);
            if ($matchKey) {
                $seenKeys[] = $matchKey;
            }

            $existing = $matchKey ? ($productLookup[$matchKey] ?? null) : null;
            $after = $this->snapshotPreviewProduct($productRow);
            $stats['warning_count'] += count($productRow['warnings'] ?? []);
            $stats['error_count'] += count($productRow['errors'] ?? []);

            if (!$existing) {
                $stats['products_created']++;
                continue;
            }

            $before = $this->snapshotProduct($existing);
            $changes = $this->detectProductChanges($before, $after);

            if ($changes === []) {
                $stats['products_unchanged']++;
                continue;
            }

            $stats['products_updated']++;
            foreach ($changes as $payload) {
                $stats[$payload['counter']]++;
            }
        }

        $stats['products_missing_from_feed'] = $existingProducts
            ->filter(fn (SupplierProductRaw $product) => !in_array($this->productMatchKeyFromRaw($product), $seenKeys, true))
            ->count();

        $reportPayload = [
            'source_name' => $source->source_name,
            'supplier_name' => $source->supplier?->name,
            'dry_run' => true,
            'message' => 'Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.',
            'products' => $products->count(),
            'variants' => $variants->count(),
            'build' => [
                'processed' => 0,
                'variants' => 0,
                'created_products' => 0,
                'updated_products' => 0,
                'created_variants' => 0,
                'updated_variants' => 0,
                'warnings' => 0,
                'errors' => 0,
                'skipped' => 0,
            ],
            'projection' => [
                'products' => 0,
                'variants' => 0,
                'warnings' => 0,
                'created_products' => 0,
                'updated_products' => 0,
                'inactive_candidates' => 0,
                'blocked_missing_category' => 0,
                'blocked_missing_price' => 0,
                'blocked_conflict_category' => 0,
                'blocked_projection_errors' => 0,
                'projected_with_warnings' => 0,
            ],
            'options' => Arr::only($options, ['no_build', 'no_project', 'force']),
        ];

        $run->update(array_merge($stats, [
            'status' => $stats['error_count'] > 0
                ? ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS
                : ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => $reportPayload,
        ]));

        return ['run' => $run->fresh(), 'stats' => $stats];
    }

    private function snapshotPreviewProduct(array $product): array
    {
        return [
            'match_key' => $this->productMatchKeyFromRow($product),
            'product_name' => $product['product_name'] ?? null,
            'supplier_category_name' => $product['supplier_category_name'] ?? null,
            'stock_quantity' => $product['stock_quantity']
                ?? $product['total_variant_stock_quantity']
                ?? $product['variant_stock_quantity']
                ?? null,
            'list_price' => $product['list_price'] ?? null,
            'image_url' => $product['image_url'] ?? null,
            'description' => $product['description'] ?? null,
        ];
    }

    private function projectSourceProductsToTenants(SupplierSource $source): array
    {
        return $this->projectDirtyStandardProducts($source, []);
    }

    private function projectDirtyStandardProducts(SupplierSource $source, array $standardProductIds): array
    {
        $stats = [
            'products' => 0,
            'variants' => 0,
            'warnings' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'inactive_candidates' => 0,
            'blocked_missing_category' => 0,
            'blocked_missing_price' => 0,
            'blocked_conflict_category' => 0,
            'blocked_projection_errors' => 0,
            'projected_with_warnings' => 0,
            'dirty_standard_products_detected' => count(array_unique(array_filter($standardProductIds))),
            'dirty_standard_products_projected' => 0,
            'dirty_standard_products_skipped' => 0,
            'affected_tenants_count' => 0,
            'tenant_catalog_products_updated' => 0,
            'tenant_catalog_variants_updated' => 0,
            'projection_skipped_no_tenant_access' => 0,
            'projection_skipped_standard_product_missing' => 0,
            'projection_skipped_review_only_change' => 0,
            'projection_mode' => empty($standardProductIds) ? 'full' : 'dirty',
            'projection_reason' => empty($standardProductIds) ? 'source_sync_full' : 'price_stock_delta',
        ];

        $standardProductIds = array_values(array_unique(array_filter(array_map('intval', $standardProductIds))));
        if ($standardProductIds !== []) {
            $existingIds = StandardProduct::query()
                ->whereIn('id', $standardProductIds)
                ->pluck('id')
                ->all();
            $stats['projection_skipped_standard_product_missing'] = count(array_diff($standardProductIds, $existingIds));
            $standardProductIds = array_values($existingIds);
        }

        $tenantIds = TenantSupplierAccess::query()
            ->where('supplier_id', $source->supplier_id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('tenant_account_id')
            ->unique()
            ->values();

        if ($tenantIds->isEmpty()) {
            $stats['projection_skipped_no_tenant_access'] = $stats['dirty_standard_products_detected'] > 0
                ? $stats['dirty_standard_products_detected']
                : 0;

            return $stats;
        }

        if ($stats['projection_mode'] === 'dirty' && $standardProductIds === []) {
            $stats['dirty_standard_products_skipped'] = $stats['projection_skipped_standard_product_missing'];

            return $stats;
        }

        foreach ($tenantIds as $tenantId) {
            $projectionTenant = TenantAccount::query()->find($tenantId);
            if (!$projectionTenant) {
                continue;
            }

            $beforeProducts = $standardProductIds === []
                ? 0
                : \App\Models\TenantCatalogProduct::query()
                    ->where('tenant_account_id', $tenantId)
                    ->whereIn('standard_product_id', $standardProductIds)
                    ->count();
            $beforeVariants = $standardProductIds === []
                ? 0
                : \App\Models\TenantCatalogProductVariant::query()
                    ->where('tenant_account_id', $tenantId)
                    ->whereHas('catalogProduct', fn ($query) => $query->whereIn('standard_product_id', $standardProductIds))
                    ->count();

            $result = $this->tenantCatalogProjection->projectForTenant($projectionTenant, [
                'supplier_ids' => [$source->supplier_id],
                'standard_product_ids' => $standardProductIds,
            ]);

            foreach ($stats as $key => $value) {
                if (array_key_exists($key, $result)) {
                    $stats[$key] += (int) ($result[$key] ?? 0);
                }
            }

            if (($result['products'] ?? 0) > 0 || ($result['variants'] ?? 0) > 0) {
                $stats['affected_tenants_count']++;
            }

            if ($standardProductIds !== []) {
                $afterProducts = \App\Models\TenantCatalogProduct::query()
                    ->where('tenant_account_id', $tenantId)
                    ->whereIn('standard_product_id', $standardProductIds)
                    ->count();
                $afterVariants = \App\Models\TenantCatalogProductVariant::query()
                    ->where('tenant_account_id', $tenantId)
                    ->whereHas('catalogProduct', fn ($query) => $query->whereIn('standard_product_id', $standardProductIds))
                    ->count();

                $stats['tenant_catalog_products_updated'] += max($beforeProducts, $afterProducts);
                $stats['tenant_catalog_variants_updated'] += max($beforeVariants, $afterVariants);
            }
        }

        if ($standardProductIds !== []) {
            $stats['dirty_standard_products_projected'] = count($standardProductIds);
            $stats['dirty_standard_products_skipped'] = max(
                0,
                $stats['dirty_standard_products_detected'] - $stats['dirty_standard_products_projected']
            );
        }

        return $stats;
    }

    private function dryRunDeltaPreviewData(SupplierSource $source, array $previewData, string $runType = 'manual', array $options = []): array
    {
        $context = $this->buildDeltaContext($source, $previewData);
        $reviewSummary = $this->buildReviewSummary($context);
        $onlyCleanStockSummary = $this->shouldOnlyCleanStock($options)
            ? $this->buildOnlyCleanStockSummary($source, $context)
            : $this->emptyOnlyCleanStockSummary($context);
        $dirtyProjectionSummary = ($options['project_dirty'] ?? false) === true
            ? $this->analyzeDirtyProjection(
                $source,
                $context,
                $this->shouldOnlyCleanStock($options) ? $onlyCleanStockSummary['affected_standard_product_ids'] : null
            )
            : $this->emptyDirtyProjectionSummary('none', 'projection_disabled');

        $status = ($context['delta']['flags']['feed_degraded'] ?? false)
            || ($context['delta']['flags']['suspicious_feed_drop'] ?? false)
            || ($context['delta']['flags']['suspicious_price_jump'] ?? false)
            || !($context['delta']['identity_summary']['reliable'] ?? false)
            ? ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS
            : ProductDataHubSyncRun::STATUS_COMPLETED;

        $run = $this->makeTransientRun(array_merge($context['stats'], [
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'finished_at' => now(),
            'status' => $status,
            'report_payload' => [
                'mode' => 'delta',
                'dry_run' => true,
                'source_name' => $source->source_name,
                'supplier_name' => $source->supplier?->name,
                'message' => 'Bu işlem delta dry-run olarak çalıştı, veri değiştirilmedi.',
                'products' => $context['products']->count(),
                'variants' => $context['variants']->count(),
                'build' => ['processed' => 0, 'variants' => 0],
                'projection' => ['products' => 0, 'variants' => 0],
                'options' => Arr::only(array_merge($options, ['no_build' => true, 'no_project' => true]), ['mode', 'dry_run', 'only_clean_stock', 'no_build', 'no_project', 'force']),
                'delta_summary' => [
                    'identity' => $context['delta']['identity_summary'],
                    'counts' => $context['counts'],
                    'flags' => $context['delta']['flags'],
                    'apply_candidate' => (bool) ($context['delta']['apply_candidate'] ?? false),
                    'review_summary' => $reviewSummary,
                    'total_price_stock_candidates' => $onlyCleanStockSummary['total_price_stock_candidates'],
                    'clean_stock_candidates' => $onlyCleanStockSummary['clean_stock_candidates'],
                    'skipped_variant_structure_changed' => $onlyCleanStockSummary['skipped_variant_structure_changed'],
                    'skipped_required_field_missing' => $onlyCleanStockSummary['skipped_required_field_missing'],
                    'skipped_new_or_missing_variant' => $onlyCleanStockSummary['skipped_new_or_missing_variant'],
                    'skipped_category_content_image_changed' => $onlyCleanStockSummary['skipped_category_content_image_changed'],
                    'skipped_identity_risk' => $onlyCleanStockSummary['skipped_identity_risk'],
                    'skipped_suspicious_or_feed_risk' => $onlyCleanStockSummary['skipped_suspicious_or_feed_risk'],
                    'skipped_currency_or_pricing_policy_change' => $onlyCleanStockSummary['skipped_currency_or_pricing_policy_change'],
                    'would_apply_clean_stock' => $onlyCleanStockSummary['would_apply_clean_stock'],
                    'would_project_dirty_products' => $onlyCleanStockSummary['would_project_dirty_products'],
                    'affected_standard_products_count' => $onlyCleanStockSummary['affected_standard_products_count'],
                    'affected_tenant_catalog_variants_count' => $onlyCleanStockSummary['affected_tenant_catalog_variants_count'],
                    'projection_mode' => $dirtyProjectionSummary['projection_mode'],
                    'projection_reason' => $dirtyProjectionSummary['projection_reason'],
                    'dirty_standard_products_detected' => $dirtyProjectionSummary['dirty_standard_products_detected'],
                    'dirty_standard_products_projected' => $dirtyProjectionSummary['dirty_standard_products_projected'],
                    'dirty_standard_products_skipped' => $dirtyProjectionSummary['dirty_standard_products_skipped'],
                    'affected_tenants_count' => $dirtyProjectionSummary['affected_tenants_count'],
                    'tenant_catalog_products_updated' => $dirtyProjectionSummary['tenant_catalog_products_updated'],
                    'tenant_catalog_variants_updated' => $dirtyProjectionSummary['tenant_catalog_variants_updated'],
                    'projection_skipped_no_tenant_access' => $dirtyProjectionSummary['projection_skipped_no_tenant_access'],
                    'projection_skipped_standard_product_missing' => $dirtyProjectionSummary['projection_skipped_standard_product_missing'],
                    'projection_skipped_review_only_change' => $dirtyProjectionSummary['projection_skipped_review_only_change'],
                    'sample_changes' => array_slice($context['delta']['changes'], 0, 25),
                ],
            ],
        ]));

        return ['run' => $run, 'stats' => $context['stats']];
    }

    private function attachDeltaHashes(Collection $products, Collection $variants): array
    {
        $variantsByParent = $variants
            ->groupBy(fn (array $variant) => $this->resolveVariantParentIdentityKey($variant))
            ->all();

        $products = $products->map(function (array $product) use ($variantsByParent) {
            $productHashes = $this->deltaHashService->buildProductHashes(
                $product,
                (array) ($variantsByParent[$this->deltaHashService->productIdentityKey($product)] ?? [])
            );

            return array_merge($product, $productHashes);
        })->values();

        $variants = $variants->map(function (array $variant) {
            return array_merge($variant, $this->deltaHashService->buildVariantHashes($variant));
        })->values();

        return [$products, $variants];
    }

    private function resolveVariantParentIdentityKey(array $variant): ?string
    {
        $parent = $variant['parent_supplier_product_id'] ?? null;
        if (filled($parent)) {
            return 'product:' . trim((string) $parent);
        }

        $groupCode = $variant['supplier_group_code'] ?? null;
        if (filled($groupCode)) {
            return 'group:' . trim((string) $groupCode);
        }

        return null;
    }

    private function createTransientFailedRun(SupplierSource $source, array $errors, string $runType = 'manual', array $options = []): array
    {
        $run = $this->makeTransientRun([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'finished_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_FAILED,
            'error_count' => count($errors),
            'error_message' => implode(' | ', $errors),
            'triggered_by' => auth()->id(),
            'report_payload' => [
                'mode' => (string) ($options['mode'] ?? 'delta'),
                'dry_run' => (bool) ($options['dry_run'] ?? false),
                'options' => Arr::only($options, ['mode', 'dry_run', 'no_build', 'no_project', 'force']),
            ],
        ]);

        return ['run' => $run, 'stats' => ['error_count' => count($errors)]];
    }

    private function applyDeltaPriceStockPreviewData(SupplierSource $source, array $previewData, string $runType = 'manual', array $options = []): array
    {
        $context = $this->buildDeltaContext($source, $previewData);
        $guardFailures = $this->resolveDeltaApplyGuards($context);

        if ($guardFailures !== []) {
            $failedSummary = $this->baseDeltaApplySummary($context);
            $failedSummary['blocked_identity_risk'] = max(
                (int) data_get($context, 'counts.blocked_identity_missing', 0),
                (int) (data_get($context, 'delta.identity_summary.reliable', false) ? 0 : 1)
            );
            $failedSummary['skipped_identity_risk'] = $failedSummary['blocked_identity_risk'];
            $failedSummary['blocked_suspicious_price_jump'] = (int) data_get($context, 'counts.suspicious_price_jump', 0);
            $failedSummary['skipped_suspicious_price_jump'] = $failedSummary['blocked_suspicious_price_jump'];
            $failedSummary['blocked_global_feed_security'] = (int) data_get($context, 'counts.feed_degraded', 0)
                + (int) data_get($context, 'counts.suspicious_feed_drop', 0);
            $failedSummary['skipped_feed_degraded'] = $failedSummary['blocked_global_feed_security'];
            $failedSummary['skipped_review_only_changes'] = $this->countDeltaReviewOnlyChanges($context['counts']);
            $failedSummary['skipped_required_field_missing'] = count($this->deltaBlockedIdentityKeys($context, 'blocked_required_field_missing'));

            return $this->createFailedRun(
                $source,
                $guardFailures,
                $runType,
                array_merge($options, ['mode' => 'delta', 'no_build' => true, 'no_project' => true]),
                [
                    'source_name' => $source->source_name,
                    'supplier_name' => $source->supplier?->name,
                    'message' => 'Delta apply-price-stock güvenlik kapıları nedeniyle uygulanmadı.',
                    'delta_apply_summary' => $failedSummary,
                ]
            );
        }

        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_RUNNING,
            'triggered_by' => auth()->id(),
        ]);

        $currentProductsByKey = $context['products']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->productIdentityKey($row) => $row]));
        $currentVariantsByKey = $context['variants']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->variantIdentityKey($row) => $row]));
        $existingProductsByKey = $context['existing_products']
            ->mapWithKeys(fn (SupplierProductRaw $row) => array_filter([$this->deltaHashService->productIdentityKey([
                'supplier_product_id' => $row->supplier_product_id,
                'supplier_product_code' => $row->supplier_product_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));
        $existingVariantsByKey = $context['existing_variants']
            ->mapWithKeys(fn (SupplierProductVariantRaw $row) => array_filter([$this->deltaHashService->variantIdentityKey([
                'variant_id' => $row->variant_id,
                'variant_stock_code' => $row->variant_stock_code,
                'variant_code' => $row->variant_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));

        $applySummary = $this->baseDeltaApplySummary($context);
        $onlyCleanStockSummary = $this->shouldOnlyCleanStock($options)
            ? $this->buildOnlyCleanStockSummary($source, $context)
            : $this->emptyOnlyCleanStockSummary($context);
        $blockedRequiredFieldKeys = array_flip($this->deltaBlockedIdentityKeys($context, 'blocked_required_field_missing'));
        $cleanStockKeys = array_flip($onlyCleanStockSummary['clean_stock_candidate_keys']);

        $touchedStandardProductIds = [];
        $appliedIdentityKeys = [];

        foreach ($context['delta']['changes'] as $change) {
            $identityKey = (string) ($change['identity_key'] ?? '');
            $type = (string) ($change['type'] ?? '');

            if (!in_array($type, ['price_changed', 'stock_changed', 'price_and_stock_changed'], true)) {
                if ($identityKey !== '') {
                    $applySummary['skipped_non_price_stock_change']++;
                }
                continue;
            }

            if ($this->shouldOnlyCleanStock($options)) {
                if ($type !== 'stock_changed') {
                    $applySummary['skipped_non_price_stock_change']++;
                    continue;
                }

                if ($identityKey === '' || !isset($cleanStockKeys[$identityKey])) {
                    continue;
                }
            }

            if ($identityKey === '' || in_array($identityKey, $appliedIdentityKeys, true)) {
                continue;
            }

            if (isset($blockedRequiredFieldKeys[$identityKey])) {
                $applySummary['skipped_required_field_missing']++;
                continue;
            }

            if (($change['scope'] ?? '') === 'product') {
                $existingProduct = $existingProductsByKey[$identityKey] ?? null;
                $currentProduct = $currentProductsByKey[$identityKey] ?? null;

                if ($existingProduct && $currentProduct) {
                    $this->applyDeltaToRawProduct($existingProduct, $currentProduct);
                    $this->applyDeltaToStandardProduct($existingProduct, $currentProduct);
                    if ($existingProduct->standard_product_id) {
                        $touchedStandardProductIds[] = $existingProduct->standard_product_id;
                    }
                }
            }

            if (($change['scope'] ?? '') === 'variant') {
                $existingVariant = $existingVariantsByKey[$identityKey] ?? null;
                $currentVariant = $currentVariantsByKey[$identityKey] ?? null;

                if ($existingVariant && $currentVariant) {
                    $this->applyDeltaToRawVariant($existingVariant, $currentVariant);
                    $standardProductId = $this->applyDeltaToStandardVariant($existingVariant, $currentVariant);
                    if ($standardProductId) {
                        $touchedStandardProductIds[] = $standardProductId;
                    }
                }
            }

            $appliedIdentityKeys[] = $identityKey;
            $applySummary['price_stock_applied']++;
            $this->recordChange(
                $run,
                $source,
                $identityKey,
                $type . '_applied',
                $change['old_value'] ?? null,
                $change['new_value'] ?? null,
                'Delta apply kapsamında fiyat/stok güncellendi.'
            );

            if ($type === 'price_changed') {
                $applySummary['price_changed_applied']++;
            } elseif ($type === 'stock_changed') {
                $applySummary['stock_changed_applied']++;
            } else {
                $applySummary['price_and_stock_changed_applied']++;
            }
        }

        $applySummary['review_summary'] = $this->synchronizeDeltaReviewChanges($run, $source, $context);
        $dirtyStandardProductIds = array_values(array_unique(array_filter($touchedStandardProductIds)));
        $projectionSummary = $this->emptyDirtyProjectionSummary('none', 'projection_disabled');
        if (($options['project_dirty'] ?? false) === true) {
            $dirtyStandardProductIds = $this->mergeStaleProjectionStandardProductIds($source, $dirtyStandardProductIds);
            if ($dirtyStandardProductIds === []) {
                $projectionSummary = $this->emptyDirtyProjectionSummary('dirty', 'no_dirty_products');
                $projectionSummary['projection_skipped_review_only_change'] = $this->countDeltaReviewOnlyChanges($context['counts']);
            } else {
                $projectionSummary = $this->projectDirtyStandardProducts($source, $dirtyStandardProductIds);
            }
        }
        $applySummary = array_merge($applySummary, $projectionSummary);
        $applySummary['total_price_stock_candidates'] = $onlyCleanStockSummary['total_price_stock_candidates'];
        $applySummary['clean_stock_candidates'] = $onlyCleanStockSummary['clean_stock_candidates'];
        $applySummary['skipped_variant_structure_changed'] = $onlyCleanStockSummary['skipped_variant_structure_changed'];
        $applySummary['skipped_required_field_missing'] = max(
            $applySummary['skipped_required_field_missing'],
            $onlyCleanStockSummary['skipped_required_field_missing']
        );
        $applySummary['skipped_new_or_missing_variant'] = $onlyCleanStockSummary['skipped_new_or_missing_variant'];
        $applySummary['skipped_category_content_image_changed'] = $onlyCleanStockSummary['skipped_category_content_image_changed'];
        $applySummary['skipped_identity_risk'] = max(
            $applySummary['skipped_identity_risk'],
            $onlyCleanStockSummary['skipped_identity_risk']
        );
        $applySummary['skipped_suspicious_or_feed_risk'] = $onlyCleanStockSummary['skipped_suspicious_or_feed_risk'];
        $applySummary['skipped_currency_or_pricing_policy_change'] = $onlyCleanStockSummary['skipped_currency_or_pricing_policy_change'];
        $applySummary['would_apply_clean_stock'] = $onlyCleanStockSummary['would_apply_clean_stock'];
        $applySummary['would_project_dirty_products'] = count($dirtyStandardProductIds);
        $applySummary['affected_standard_products_count'] = count($dirtyStandardProductIds);
        $applySummary['affected_tenant_catalog_variants_count'] = $onlyCleanStockSummary['affected_tenant_catalog_variants_count'];

        foreach ($dirtyStandardProductIds as $standardProductId) {
            $product = StandardProduct::query()->find($standardProductId);
            if ($product) {
                $product->updateAggregateStats();
            }
        }

        $stats = array_merge($context['stats'], [
            'warning_count' => 0,
            'error_count' => 0,
        ]);

        $run->update(array_merge($stats, [
            'status' => ProductDataHubSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'report_payload' => [
                'mode' => 'delta',
                'dry_run' => false,
                'source_name' => $source->source_name,
                'supplier_name' => $source->supplier?->name,
                'message' => 'Bu işlem delta apply-price-stock olarak çalıştı.',
                'products' => $context['products']->count(),
                'variants' => $context['variants']->count(),
                'build' => ['processed' => 0, 'variants' => 0],
                'projection' => $projectionSummary,
                'options' => Arr::only(array_merge($options, ['no_build' => true]), ['mode', 'apply_price_stock', 'only_clean_stock', 'project_dirty', 'dry_run', 'no_build', 'no_project', 'force']),
                'delta_apply_summary' => $applySummary,
            ],
        ]));

        $source->update(['last_sync_at' => now()]);

        return ['run' => $run->fresh(), 'stats' => $stats];
    }

    private function buildDeltaContext(SupplierSource $source, array $previewData): array
    {
        $products = collect($previewData['products'] ?? []);
        $variants = collect($previewData['variants'] ?? []);
        [$products, $variants] = $this->attachDeltaHashes($products, $variants);

        $existingProducts = SupplierProductRaw::query()
            ->where('supplier_source_id', $source->id)
            ->get();
        $existingVariants = SupplierProductVariantRaw::query()
            ->where('supplier_source_id', $source->id)
            ->get();

        $delta = $this->deltaChangeDetector->detectForSource($source, [
            'products' => $products->all(),
            'variants' => $variants->all(),
            'stats' => $previewData['stats'] ?? [],
        ], $existingProducts, $existingVariants);

        $counts = array_merge([
            'price_changed' => 0,
            'stock_changed' => 0,
            'price_and_stock_changed' => 0,
            'new_product' => 0,
            'new_variant' => 0,
            'missing_product' => 0,
            'missing_variant' => 0,
            'category_changed' => 0,
            'image_changed' => 0,
            'content_changed' => 0,
            'variant_structure_changed' => 0,
            'blocked_identity_missing' => 0,
            'blocked_required_field_missing' => 0,
            'feed_degraded' => 0,
            'suspicious_price_jump' => 0,
            'suspicious_feed_drop' => 0,
        ], $delta['counts']);

        $changedIdentityCount = collect($delta['changes'])
            ->filter(fn (array $change) => in_array($change['type'], [
                'price_changed',
                'stock_changed',
                'price_and_stock_changed',
                'image_changed',
                'category_changed',
                'content_changed',
                'variant_structure_changed',
            ], true))
            ->pluck('identity_key')
            ->filter()
            ->unique()
            ->count();

        $stats = [
            'records_read' => (int) data_get($previewData, 'stats.records_read', $products->count()),
            'products_created' => (int) ($counts['new_product'] ?? 0),
            'products_updated' => $changedIdentityCount,
            'products_unchanged' => max(0, $products->count() - (int) ($counts['new_product'] ?? 0) - $changedIdentityCount),
            'products_missing_from_feed' => (int) ($counts['missing_product'] ?? 0),
            'products_inactivated' => 0,
            'price_changed_count' => (int) (($counts['price_changed'] ?? 0) + ($counts['price_and_stock_changed'] ?? 0)),
            'stock_changed_count' => (int) (($counts['stock_changed'] ?? 0) + ($counts['price_and_stock_changed'] ?? 0)),
            'image_changed_count' => (int) ($counts['image_changed'] ?? 0),
            'category_changed_count' => (int) ($counts['category_changed'] ?? 0),
            'name_changed_count' => 0,
            'description_changed_count' => 0,
            'warning_count' => (int) (($counts['suspicious_price_jump'] ?? 0) + ($counts['suspicious_feed_drop'] ?? 0) + ($counts['feed_degraded'] ?? 0) + ($counts['blocked_identity_missing'] ?? 0) + ($counts['blocked_required_field_missing'] ?? 0)),
            'error_count' => 0,
        ];

        return [
            'products' => $products,
            'variants' => $variants,
            'existing_products' => $existingProducts,
            'existing_variants' => $existingVariants,
            'delta' => $delta,
            'counts' => $counts,
            'stats' => $stats,
        ];
    }

    private function resolveDeltaApplyGuards(array $context): array
    {
        $errors = [];

        if (!(bool) data_get($context, 'delta.identity_summary.reliable', false)) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: identity durumu güvenilir değil.';
        }

        if ((int) data_get($context, 'counts.blocked_identity_missing', 0) > 0) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: blocked_identity_missing kayıtları bulundu.';
        }

        if ((bool) data_get($context, 'delta.flags.feed_degraded', false)) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: feed_degraded tespit edildi.';
        }

        if ((bool) data_get($context, 'delta.flags.suspicious_feed_drop', false)) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: suspicious_feed_drop tespit edildi.';
        }

        if ((bool) data_get($context, 'delta.flags.suspicious_price_jump', false)) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: suspicious_price_jump tespit edildi.';
        }

        if ($this->hasPricingContextMismatch($context)) {
            $errors[] = 'Delta apply güvenlik nedeniyle reddedildi: currency veya pricing_policy_type beklenmedik şekilde değişti.';
        }

        return array_values(array_unique($errors));
    }

    private function deltaPriceStockCandidateKeys(array $context): array
    {
        return collect((array) ($context['delta']['changes'] ?? []))
            ->filter(fn (array $change) => in_array((string) ($change['type'] ?? ''), [
                'price_changed',
                'stock_changed',
                'price_and_stock_changed',
            ], true))
            ->pluck('identity_key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function deltaBlockedIdentityKeys(array $context, string $type): array
    {
        return collect((array) ($context['delta']['changes'] ?? []))
            ->filter(fn (array $change) => (string) ($change['type'] ?? '') === $type)
            ->pluck('identity_key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function shouldOnlyCleanStock(array $options): bool
    {
        return (bool) ($options['only_clean_stock'] ?? false);
    }

    private function emptyOnlyCleanStockSummary(array $context): array
    {
        return [
            'total_price_stock_candidates' => count($this->deltaPriceStockCandidateKeys($context)),
            'clean_stock_candidates' => 0,
            'skipped_variant_structure_changed' => 0,
            'skipped_required_field_missing' => 0,
            'skipped_new_or_missing_variant' => 0,
            'skipped_category_content_image_changed' => 0,
            'skipped_identity_risk' => 0,
            'skipped_suspicious_or_feed_risk' => 0,
            'skipped_currency_or_pricing_policy_change' => 0,
            'would_apply_clean_stock' => 0,
            'would_project_dirty_products' => 0,
            'affected_standard_products_count' => 0,
            'affected_tenant_catalog_variants_count' => 0,
            'clean_stock_candidate_keys' => [],
            'affected_standard_product_ids' => [],
        ];
    }

    private function buildOnlyCleanStockSummary(SupplierSource $source, array $context): array
    {
        $summary = $this->emptyOnlyCleanStockSummary($context);
        $summary['total_price_stock_candidates'] = count($this->deltaPriceStockCandidateKeys($context));

        $stockCandidateKeys = $this->deltaIdentityKeysByTypes($context, ['stock_changed']);
        if ($stockCandidateKeys === []) {
            return $summary;
        }

        if (!(bool) data_get($context, 'delta.identity_summary.reliable', false)
            || (int) data_get($context, 'counts.blocked_identity_missing', 0) > 0) {
            $summary['skipped_identity_risk'] = count($stockCandidateKeys);

            return $summary;
        }

        if ((bool) data_get($context, 'delta.flags.feed_degraded', false)
            || (bool) data_get($context, 'delta.flags.suspicious_feed_drop', false)
            || (bool) data_get($context, 'delta.flags.suspicious_price_jump', false)) {
            $summary['skipped_suspicious_or_feed_risk'] = count($stockCandidateKeys);

            return $summary;
        }

        $changesByIdentity = $this->deltaChangeTypesByIdentity($context);
        $cleanKeys = [];

        foreach ($stockCandidateKeys as $identityKey) {
            $types = $changesByIdentity[$identityKey] ?? [];

            if ($this->identityHasPricingContextMismatch($context, $identityKey)) {
                $summary['skipped_currency_or_pricing_policy_change']++;
                continue;
            }

            if (in_array('price_changed', $types, true) || in_array('price_and_stock_changed', $types, true)) {
                continue;
            }

            if (in_array('variant_structure_changed', $types, true)) {
                $summary['skipped_variant_structure_changed']++;
                continue;
            }

            if (in_array('blocked_required_field_missing', $types, true)) {
                $summary['skipped_required_field_missing']++;
                continue;
            }

            if (in_array('new_variant', $types, true) || in_array('missing_variant', $types, true)) {
                $summary['skipped_new_or_missing_variant']++;
                continue;
            }

            if (in_array('category_changed', $types, true)
                || in_array('content_changed', $types, true)
                || in_array('image_changed', $types, true)) {
                $summary['skipped_category_content_image_changed']++;
                continue;
            }

            $cleanKeys[] = $identityKey;
        }

        $affectedStandardProductIds = $this->collectDirtyStandardProductIdsFromContext($context, $cleanKeys);

        $summary['clean_stock_candidate_keys'] = array_values($cleanKeys);
        $summary['clean_stock_candidates'] = count($cleanKeys);
        $summary['would_apply_clean_stock'] = count($cleanKeys);
        $summary['affected_standard_product_ids'] = $affectedStandardProductIds;
        $summary['would_project_dirty_products'] = count($affectedStandardProductIds);
        $summary['affected_standard_products_count'] = count($affectedStandardProductIds);
        $summary['affected_tenant_catalog_variants_count'] = $this->countAffectedTenantCatalogVariantsForStandardProducts($source, $affectedStandardProductIds);

        return $summary;
    }

    private function deltaIdentityKeysByTypes(array $context, array $types): array
    {
        return collect((array) ($context['delta']['changes'] ?? []))
            ->filter(fn (array $change) => in_array((string) ($change['type'] ?? ''), $types, true))
            ->pluck('identity_key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function deltaChangeTypesByIdentity(array $context): array
    {
        return collect((array) ($context['delta']['changes'] ?? []))
            ->filter(fn (array $change) => is_string($change['identity_key'] ?? null) && ($change['identity_key'] ?? '') !== '')
            ->groupBy('identity_key')
            ->map(fn (Collection $changes) => $changes
                ->pluck('type')
                ->filter(fn ($type) => is_string($type) && $type !== '')
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    private function identityHasPricingContextMismatch(array $context, string $identityKey): bool
    {
        $currentProductsByKey = $context['products']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->productIdentityKey($row) => $row]));
        $currentVariantsByKey = $context['variants']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->variantIdentityKey($row) => $row]));
        $existingProductsByKey = $context['existing_products']
            ->mapWithKeys(fn (SupplierProductRaw $row) => array_filter([$this->deltaHashService->productIdentityKey([
                'supplier_product_id' => $row->supplier_product_id,
                'supplier_product_code' => $row->supplier_product_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));
        $existingVariantsByKey = $context['existing_variants']
            ->mapWithKeys(fn (SupplierProductVariantRaw $row) => array_filter([$this->deltaHashService->variantIdentityKey([
                'variant_id' => $row->variant_id,
                'variant_stock_code' => $row->variant_stock_code,
                'variant_code' => $row->variant_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));

        foreach ((array) ($context['delta']['changes'] ?? []) as $change) {
            if ((string) ($change['identity_key'] ?? '') !== $identityKey) {
                continue;
            }

            if (($change['scope'] ?? '') === 'product') {
                $existing = $existingProductsByKey[$identityKey] ?? null;
                $current = $currentProductsByKey[$identityKey] ?? null;
                if ($existing && $current && $this->pricingContextMismatch($existing->normalized_payload ?? [], $current)) {
                    return true;
                }
            }

            if (($change['scope'] ?? '') === 'variant') {
                $existing = $existingVariantsByKey[$identityKey] ?? null;
                $current = $currentVariantsByKey[$identityKey] ?? null;
                if ($existing && $current && $this->pricingContextMismatch($existing->normalized_payload ?? [], $current)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function countAffectedTenantCatalogVariantsForStandardProducts(SupplierSource $source, array $standardProductIds): int
    {
        if ($standardProductIds === []) {
            return 0;
        }

        $tenantIds = TenantSupplierAccess::query()
            ->where('supplier_id', $source->supplier_id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('tenant_account_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tenantIds === []) {
            return 0;
        }

        $standardVariantIds = StandardProductVariant::query()
            ->whereIn('standard_product_id', $standardProductIds)
            ->pluck('id')
            ->all();

        if ($standardVariantIds === []) {
            return 0;
        }

        return \App\Models\TenantCatalogProductVariant::query()
            ->whereIn('tenant_account_id', $tenantIds)
            ->whereIn('standard_product_variant_id', $standardVariantIds)
            ->count();
    }

    private function hasPricingContextMismatch(array $context): bool
    {
        $currentProductsByKey = $context['products']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->productIdentityKey($row) => $row]));
        $currentVariantsByKey = $context['variants']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->variantIdentityKey($row) => $row]));
        $existingProductsByKey = $context['existing_products']
            ->mapWithKeys(fn (SupplierProductRaw $row) => array_filter([$this->deltaHashService->productIdentityKey([
                'supplier_product_id' => $row->supplier_product_id,
                'supplier_product_code' => $row->supplier_product_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));
        $existingVariantsByKey = $context['existing_variants']
            ->mapWithKeys(fn (SupplierProductVariantRaw $row) => array_filter([$this->deltaHashService->variantIdentityKey([
                'variant_id' => $row->variant_id,
                'variant_stock_code' => $row->variant_stock_code,
                'variant_code' => $row->variant_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));

        foreach ($context['delta']['changes'] as $change) {
            $type = (string) ($change['type'] ?? '');
            if (!in_array($type, ['price_changed', 'price_and_stock_changed'], true)) {
                continue;
            }

            $identityKey = (string) ($change['identity_key'] ?? '');
            if (($change['scope'] ?? '') === 'product') {
                $existing = $existingProductsByKey[$identityKey] ?? null;
                $current = $currentProductsByKey[$identityKey] ?? null;
                if ($existing && $current && $this->pricingContextMismatch($existing->normalized_payload ?? [], $current)) {
                    return true;
                }
            }

            if (($change['scope'] ?? '') === 'variant') {
                $existing = $existingVariantsByKey[$identityKey] ?? null;
                $current = $currentVariantsByKey[$identityKey] ?? null;
                if ($existing && $current && $this->pricingContextMismatch($existing->normalized_payload ?? [], $current)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pricingContextMismatch(array $before, array $after): bool
    {
        $beforeCurrency = (string) data_get($before, 'currency', '');
        $afterCurrency = (string) ($after['currency'] ?? data_get($after, 'normalized_payload.currency', ''));
        $beforePolicy = (string) data_get($before, 'pricing_policy_type', '');
        $afterPolicy = (string) ($after['pricing_policy_type'] ?? data_get($after, 'normalized_payload.pricing_policy_type', ''));

        return ($beforeCurrency !== '' && $afterCurrency !== '' && $beforeCurrency !== $afterCurrency)
            || ($beforePolicy !== '' && $afterPolicy !== '' && $beforePolicy !== $afterPolicy);
    }

    private function applyDeltaToRawProduct(SupplierProductRaw $product, array $current): void
    {
        $normalized = (array) ($product->normalized_payload ?? []);
        $normalized = array_merge($normalized, [
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price'),
            'purchase_price' => $current['purchase_price'] ?? data_get($current, 'normalized_payload.purchase_price'),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price'),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate'),
            'currency' => $current['currency'] ?? data_get($current, 'normalized_payload.currency'),
            'vat_rate' => $current['vat_rate'] ?? data_get($current, 'normalized_payload.vat_rate'),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type'),
            'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price'),
            'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price'),
            'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price'),
            'stock_quantity' => $current['stock_quantity'] ?? data_get($current, 'normalized_payload.stock_quantity'),
            'stock_status' => $current['stock_status'] ?? data_get($current, 'normalized_payload.stock_status'),
            'stock_unit' => $current['stock_unit'] ?? data_get($current, 'normalized_payload.stock_unit'),
            'supplier_stock_code' => $current['supplier_stock_code'] ?? data_get($current, 'normalized_payload.supplier_stock_code'),
        ]);

        $payload = [
            'purchase_price' => $current['purchase_price'] ?? $product->purchase_price,
            'currency' => $current['currency'] ?? $product->currency,
            'vat_rate' => $current['vat_rate'] ?? $product->vat_rate,
            'stock_quantity' => $current['stock_quantity']
                ?? data_get($current, 'normalized_payload.stock_quantity')
                ?? data_get($current, 'normalized_payload.total_variant_stock_quantity')
                ?? $product->stock_quantity,
            'normalized_payload' => $normalized,
            'source_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price') ?? $product->source_price,
            'source_currency' => $current['currency'] ?? data_get($current, 'normalized_payload.currency') ?? $product->source_currency,
            'source_stock' => (int) round((float) ($current['stock_quantity'] ?? data_get($current, 'normalized_payload.stock_quantity') ?? data_get($current, 'normalized_payload.total_variant_stock_quantity') ?? $product->source_stock ?? 0)),
            'sync_status' => 'processed',
            'price_hash' => $current['price_hash'] ?? $product->price_hash,
            'stock_hash' => $current['stock_hash'] ?? $product->stock_hash,
            'content_hash' => $current['content_hash'] ?? $product->content_hash,
            'image_hash' => $current['image_hash'] ?? $product->image_hash,
            'category_hash' => $current['category_hash'] ?? $product->category_hash,
            'variant_structure_hash' => $current['variant_structure_hash'] ?? $product->variant_structure_hash,
            'identity_hash' => $current['identity_hash'] ?? $product->identity_hash,
            'synced_at' => now(),
        ];

        $this->schemaAwareForceFill($product, $payload)->save();
    }

    private function applyDeltaToRawVariant(SupplierProductVariantRaw $variant, array $current): void
    {
        $normalized = (array) ($variant->normalized_payload ?? []);
        $normalized = array_merge($normalized, [
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price'),
            'purchase_price' => $current['purchase_price'] ?? data_get($current, 'normalized_payload.purchase_price'),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price'),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate'),
            'currency' => $current['currency'] ?? data_get($current, 'normalized_payload.currency'),
            'vat_rate' => $current['vat_rate'] ?? data_get($current, 'normalized_payload.vat_rate'),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type'),
            'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price'),
            'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price'),
            'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price'),
            'variant_stock_quantity' => $current['variant_stock_quantity'] ?? data_get($current, 'normalized_payload.variant_stock_quantity'),
            'stock_status' => $current['stock_status'] ?? data_get($current, 'normalized_payload.stock_status'),
            'stock_unit' => $current['stock_unit'] ?? data_get($current, 'normalized_payload.stock_unit'),
        ]);

        $payload = [
            'variant_stock_quantity' => $current['variant_stock_quantity']
                ?? data_get($current, 'normalized_payload.variant_stock_quantity')
                ?? $variant->variant_stock_quantity,
            'normalized_payload' => $normalized,
            'price_hash' => $current['price_hash'] ?? $variant->price_hash,
            'stock_hash' => $current['stock_hash'] ?? $variant->stock_hash,
            'content_hash' => $current['content_hash'] ?? $variant->content_hash,
            'image_hash' => $current['image_hash'] ?? $variant->image_hash,
            'category_hash' => $current['category_hash'] ?? $variant->category_hash,
            'identity_hash' => $current['identity_hash'] ?? $variant->identity_hash,
            'sync_status' => 'processed',
        ];

        $this->schemaAwareForceFill($variant, $payload)->save();
    }

    private function schemaAwareForceFill(object $model, array $attributes): object
    {
        if (!method_exists($model, 'getTable')) {
            return $model->forceFill($attributes);
        }

        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);
        $filtered = array_intersect_key($attributes, array_flip($columns));

        return $model->forceFill($filtered);
    }

    private function applyDeltaToStandardProduct(SupplierProductRaw $rawProduct, array $current): void
    {
        if (!$rawProduct->standard_product_id) {
            return;
        }

        $product = StandardProduct::query()->find($rawProduct->standard_product_id);
        if (!$product) {
            return;
        }

        $sourceSummary = collect($product->source_summary ?? [])
            ->map(function (array $summary) use ($rawProduct, $current) {
                if ((int) ($summary['raw_product_id'] ?? 0) !== (int) $rawProduct->id) {
                    return $summary;
                }

                return array_merge($summary, [
                    'stock_quantity' => $current['stock_quantity'] ?? data_get($current, 'normalized_payload.stock_quantity') ?? $summary['stock_quantity'] ?? null,
                    'total_variant_stock_quantity' => data_get($current, 'normalized_payload.total_variant_stock_quantity', $summary['total_variant_stock_quantity'] ?? null),
                    'purchase_price' => $current['purchase_price'] ?? $summary['purchase_price'] ?? null,
                    'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price', $summary['list_price'] ?? null),
                    'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price', $summary['closed_list_price'] ?? null),
                    'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price', $summary['net_price'] ?? null),
                    'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate', $summary['discount_rate'] ?? null),
                    'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price', $summary['alternative_price'] ?? null),
                    'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price', $summary['usd_price'] ?? null),
                    'vat_rate' => $current['vat_rate'] ?? data_get($current, 'normalized_payload.vat_rate', $summary['vat_rate'] ?? null),
                    'currency' => $current['currency'] ?? data_get($current, 'normalized_payload.currency', $summary['currency'] ?? null),
                    'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type', $summary['pricing_policy_type'] ?? null),
                    'price_policy_warning' => (bool) ($current['price_policy_warning'] ?? data_get($current, 'normalized_payload.price_policy_warning', $summary['price_policy_warning'] ?? false)),
                    'net_price_warning' => (bool) ($current['net_price_warning'] ?? data_get($current, 'normalized_payload.net_price_warning', $summary['net_price_warning'] ?? false)),
                    'supplier_warning_flag' => (bool) ($current['supplier_warning_flag'] ?? data_get($current, 'normalized_payload.supplier_warning_flag', $summary['supplier_warning_flag'] ?? false)),
                    'supplier_warning_type' => $current['supplier_warning_type'] ?? data_get($current, 'normalized_payload.supplier_warning_type', $summary['supplier_warning_type'] ?? null),
                ]);
            })
            ->values()
            ->all();

        $meta = is_array($product->meta) ? $product->meta : [];
        $priceSnapshot = array_merge((array) ($meta['price_snapshot'] ?? []), [
            'purchase_price' => $current['purchase_price'] ?? data_get($current, 'normalized_payload.purchase_price', $meta['price_snapshot']['purchase_price'] ?? null),
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price', $meta['price_snapshot']['list_price'] ?? null),
            'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price', $meta['price_snapshot']['closed_list_price'] ?? null),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price', $meta['price_snapshot']['net_price'] ?? null),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate', $meta['price_snapshot']['discount_rate'] ?? null),
            'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price', $meta['price_snapshot']['alternative_price'] ?? null),
            'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price', $meta['price_snapshot']['usd_price'] ?? null),
            'net_price_warning' => (bool) ($current['net_price_warning'] ?? data_get($current, 'normalized_payload.net_price_warning', $meta['price_snapshot']['net_price_warning'] ?? false)),
            'price_policy_warning' => (bool) ($current['price_policy_warning'] ?? data_get($current, 'normalized_payload.price_policy_warning', $meta['price_snapshot']['price_policy_warning'] ?? false)),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type', $meta['price_snapshot']['pricing_policy_type'] ?? null),
            'supplier_warning_flag' => (bool) ($current['supplier_warning_flag'] ?? data_get($current, 'normalized_payload.supplier_warning_flag', $meta['price_snapshot']['supplier_warning_flag'] ?? false)),
            'supplier_warning_type' => $current['supplier_warning_type'] ?? data_get($current, 'normalized_payload.supplier_warning_type', $meta['price_snapshot']['supplier_warning_type'] ?? null),
        ]);
        $stockSnapshot = array_merge((array) ($meta['stock_snapshot'] ?? []), [
            'stock_quantity' => $current['stock_quantity'] ?? data_get($current, 'normalized_payload.stock_quantity', $meta['stock_snapshot']['stock_quantity'] ?? null),
            'total_variant_stock_quantity' => data_get($current, 'normalized_payload.total_variant_stock_quantity', $meta['stock_snapshot']['total_variant_stock_quantity'] ?? null),
            'source_stock' => $current['stock_quantity'] ?? data_get($current, 'normalized_payload.stock_quantity', $meta['stock_snapshot']['source_stock'] ?? null),
        ]);
        $meta['price_snapshot'] = $priceSnapshot;
        $meta['stock_snapshot'] = $stockSnapshot;
        $meta['normalized_payload'] = array_merge((array) ($meta['normalized_payload'] ?? []), [
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price'),
            'purchase_price' => $current['purchase_price'] ?? data_get($current, 'normalized_payload.purchase_price'),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price'),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate'),
            'currency' => $current['currency'] ?? data_get($current, 'normalized_payload.currency'),
            'vat_rate' => $current['vat_rate'] ?? data_get($current, 'normalized_payload.vat_rate'),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type'),
        ]);

        $payload = [
            'currency' => $current['currency'] ?? $product->currency,
            'vat_rate' => $current['vat_rate'] ?? $product->vat_rate,
            'min_purchase_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price') ?? $product->min_purchase_price,
            'max_purchase_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price') ?? $product->max_purchase_price,
            'total_stock_quantity' => $current['stock_quantity']
                ?? data_get($current, 'normalized_payload.stock_quantity')
                ?? data_get($current, 'normalized_payload.total_variant_stock_quantity')
                ?? $product->total_stock_quantity,
            'source_summary' => $sourceSummary,
            'meta' => $meta,
        ];

        if (Schema::hasColumn($product->getTable(), 'sync_hash')) {
            $payload['sync_hash'] = $current['price_hash'] ?? $current['stock_hash'] ?? $product->sync_hash;
        }

        $product->forceFill($payload)->save();
    }

    private function applyDeltaToStandardVariant(SupplierProductVariantRaw $rawVariant, array $current): ?int
    {
        if (!$rawVariant->standard_product_variant_id) {
            return null;
        }

        $variant = StandardProductVariant::query()->find($rawVariant->standard_product_variant_id);
        if (!$variant) {
            return null;
        }

        $sourceSummary = array_merge((array) ($variant->source_summary ?? []), [
            'stock_quantity' => $current['variant_stock_quantity'] ?? data_get($current, 'normalized_payload.variant_stock_quantity', $variant->source_summary['stock_quantity'] ?? null),
            'total_variant_stock_quantity' => data_get($current, 'normalized_payload.total_variant_stock_quantity', $variant->source_summary['total_variant_stock_quantity'] ?? null),
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price', $variant->source_summary['list_price'] ?? null),
            'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price', $variant->source_summary['closed_list_price'] ?? null),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price', $variant->source_summary['net_price'] ?? null),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate', $variant->source_summary['discount_rate'] ?? null),
            'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price', $variant->source_summary['alternative_price'] ?? null),
            'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price', $variant->source_summary['usd_price'] ?? null),
            'net_price_warning' => (bool) ($current['net_price_warning'] ?? data_get($current, 'normalized_payload.net_price_warning', $variant->source_summary['net_price_warning'] ?? false)),
            'price_policy_warning' => (bool) ($current['price_policy_warning'] ?? data_get($current, 'normalized_payload.price_policy_warning', $variant->source_summary['price_policy_warning'] ?? false)),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type', $variant->source_summary['pricing_policy_type'] ?? null),
            'supplier_warning_flag' => (bool) ($current['supplier_warning_flag'] ?? data_get($current, 'normalized_payload.supplier_warning_flag', $variant->source_summary['supplier_warning_flag'] ?? false)),
            'supplier_warning_type' => $current['supplier_warning_type'] ?? data_get($current, 'normalized_payload.supplier_warning_type', $variant->source_summary['supplier_warning_type'] ?? null),
        ]);

        $meta = is_array($variant->meta) ? $variant->meta : [];
        $meta['price_snapshot'] = array_merge((array) ($meta['price_snapshot'] ?? []), [
            'purchase_price' => $current['purchase_price'] ?? data_get($current, 'normalized_payload.purchase_price', $meta['price_snapshot']['purchase_price'] ?? null),
            'list_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price', $meta['price_snapshot']['list_price'] ?? null),
            'closed_list_price' => $current['closed_list_price'] ?? data_get($current, 'normalized_payload.closed_list_price', $meta['price_snapshot']['closed_list_price'] ?? null),
            'net_price' => $current['net_price'] ?? data_get($current, 'normalized_payload.net_price', $meta['price_snapshot']['net_price'] ?? null),
            'discount_rate' => $current['discount_rate'] ?? data_get($current, 'normalized_payload.discount_rate', $meta['price_snapshot']['discount_rate'] ?? null),
            'alternative_price' => $current['alternative_price'] ?? data_get($current, 'normalized_payload.alternative_price', $meta['price_snapshot']['alternative_price'] ?? null),
            'usd_price' => $current['usd_price'] ?? data_get($current, 'normalized_payload.usd_price', $meta['price_snapshot']['usd_price'] ?? null),
            'net_price_warning' => (bool) ($current['net_price_warning'] ?? data_get($current, 'normalized_payload.net_price_warning', $meta['price_snapshot']['net_price_warning'] ?? false)),
            'price_policy_warning' => (bool) ($current['price_policy_warning'] ?? data_get($current, 'normalized_payload.price_policy_warning', $meta['price_snapshot']['price_policy_warning'] ?? false)),
            'pricing_policy_type' => $current['pricing_policy_type'] ?? data_get($current, 'normalized_payload.pricing_policy_type', $meta['price_snapshot']['pricing_policy_type'] ?? null),
            'supplier_warning_flag' => (bool) ($current['supplier_warning_flag'] ?? data_get($current, 'normalized_payload.supplier_warning_flag', $meta['price_snapshot']['supplier_warning_flag'] ?? false)),
            'supplier_warning_type' => $current['supplier_warning_type'] ?? data_get($current, 'normalized_payload.supplier_warning_type', $meta['price_snapshot']['supplier_warning_type'] ?? null),
        ]);
        $meta['stock_snapshot'] = array_merge((array) ($meta['stock_snapshot'] ?? []), [
            'stock_quantity' => $current['variant_stock_quantity'] ?? data_get($current, 'normalized_payload.variant_stock_quantity', $meta['stock_snapshot']['stock_quantity'] ?? null),
            'total_variant_stock_quantity' => data_get($current, 'normalized_payload.total_variant_stock_quantity', $meta['stock_snapshot']['total_variant_stock_quantity'] ?? null),
        ]);

        $variant->forceFill([
            'stock_quantity' => $current['variant_stock_quantity'] ?? data_get($current, 'normalized_payload.variant_stock_quantity') ?? $variant->stock_quantity,
            'min_purchase_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price') ?? $variant->min_purchase_price,
            'max_purchase_price' => $current['list_price'] ?? data_get($current, 'normalized_payload.list_price') ?? $variant->max_purchase_price,
            'source_summary' => $sourceSummary,
            'meta' => $meta,
        ])->save();

        return $variant->standard_product_id;
    }

    private function mergeStaleProjectionStandardProductIds(SupplierSource $source, array $standardProductIds): array
    {
        $seedIds = array_values(array_unique(array_filter(array_map('intval', $standardProductIds))));
        $tenantIds = TenantSupplierAccess::query()
            ->where('supplier_id', $source->supplier_id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('tenant_account_id')
            ->filter()
            ->unique()
            ->values();

        if ($tenantIds->isEmpty()) {
            return $seedIds;
        }

        $products = StandardProduct::query()
            ->with(['variants:id,standard_product_id,updated_at'])
            ->whereHas('rawProducts', fn ($query) => $query->where('supplier_source_id', $source->id))
            ->when($seedIds !== [], fn ($query) => $query->whereIn('id', $seedIds))
            ->active()
            ->visibleInCatalog()
            ->get(['id', 'updated_at']);

        if ($products->isEmpty()) {
            return $seedIds;
        }

        $productIds = $products->pluck('id')->all();
        $variantIds = $products->flatMap(fn (StandardProduct $product) => $product->variants->pluck('id'))->filter()->all();

        $catalogProducts = \App\Models\TenantCatalogProduct::query()
            ->whereIn('tenant_account_id', $tenantIds->all())
            ->whereIn('standard_product_id', $productIds)
            ->get(['tenant_account_id', 'standard_product_id', 'updated_at'])
            ->groupBy(fn ($row) => (int) $row->tenant_account_id . ':' . (int) $row->standard_product_id);

        $catalogVariants = \App\Models\TenantCatalogProductVariant::query()
            ->whereIn('tenant_account_id', $tenantIds->all())
            ->whereIn('standard_product_variant_id', $variantIds)
            ->get(['tenant_account_id', 'standard_product_variant_id', 'updated_at'])
            ->groupBy(fn ($row) => (int) $row->tenant_account_id . ':' . (int) $row->standard_product_variant_id);

        $staleIds = collect();

        foreach ($products as $product) {
            $productFreshAt = $product->updated_at;
            foreach ($product->variants as $variant) {
                if ($variant->updated_at && (!$productFreshAt || $variant->updated_at->gt($productFreshAt))) {
                    $productFreshAt = $variant->updated_at;
                }
            }

            foreach ($tenantIds as $tenantId) {
                $catalogProduct = $catalogProducts->get((int) $tenantId . ':' . (int) $product->id)?->first();
                if (!$catalogProduct || ($productFreshAt && $catalogProduct->updated_at?->lt($productFreshAt))) {
                    $staleIds->push((int) $product->id);
                    continue 2;
                }

                foreach ($product->variants as $variant) {
                    $catalogVariant = $catalogVariants->get((int) $tenantId . ':' . (int) $variant->id)?->first();
                    if (!$catalogVariant || ($variant->updated_at && $catalogVariant->updated_at?->lt($variant->updated_at))) {
                        $staleIds->push((int) $product->id);
                        continue 3;
                    }
                }
            }
        }

        return collect($seedIds)
            ->merge($staleIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function makeTransientRun(array $attributes): ProductDataHubSyncRun
    {
        $run = new ProductDataHubSyncRun();
        $run->forceFill($attributes);

        return $run;
    }

    private function baseDeltaApplySummary(array $context): array
    {
        return [
            'counts' => $context['counts'],
            'flags' => $context['delta']['flags'],
            'identity' => $context['delta']['identity_summary'],
            'apply_candidate' => (bool) ($context['delta']['apply_candidate'] ?? false),
            'review_summary' => $this->buildReviewSummary($context),
            'price_stock_candidates_detected' => count($this->deltaPriceStockCandidateKeys($context)),
            'total_price_stock_candidates' => count($this->deltaPriceStockCandidateKeys($context)),
            'clean_stock_candidates' => 0,
            'price_stock_applied' => 0,
            'review_only_changes_detected' => $this->countDeltaReviewOnlyChanges($context['counts']),
            'skipped_review_only_changes' => $this->countDeltaReviewOnlyChanges($context['counts']),
            'skipped_variant_structure_changed' => 0,
            'skipped_required_field_missing' => 0,
            'skipped_new_or_missing_variant' => 0,
            'skipped_category_content_image_changed' => 0,
            'skipped_suspicious_or_feed_risk' => 0,
            'skipped_currency_or_pricing_policy_change' => 0,
            'price_changed_applied' => 0,
            'stock_changed_applied' => 0,
            'price_and_stock_changed_applied' => 0,
            'skipped_identity_risk' => 0,
            'skipped_suspicious_price_jump' => 0,
            'skipped_feed_degraded' => 0,
            'skipped_non_price_stock_change' => 0,
            'would_apply_clean_stock' => 0,
            'would_project_dirty_products' => 0,
            'affected_standard_products_count' => 0,
            'affected_tenant_catalog_variants_count' => 0,
            'blocked_global_feed_security' => 0,
            'blocked_suspicious_price_jump' => 0,
            'blocked_identity_risk' => 0,
            'projection_mode' => 'none',
            'projection_reason' => 'projection_disabled',
            'projection_skipped' => true,
            'projection_skip_reason' => 'Delta apply fazında tenant projection kapalı tutuldu.',
            'dirty_standard_products_detected' => 0,
            'dirty_standard_products_projected' => 0,
            'dirty_standard_products_skipped' => 0,
            'affected_tenants_count' => 0,
            'tenant_catalog_products_updated' => 0,
            'tenant_catalog_variants_updated' => 0,
            'projection_skipped_no_tenant_access' => 0,
            'projection_skipped_standard_product_missing' => 0,
            'projection_skipped_review_only_change' => 0,
        ];
    }

    private function countDeltaReviewOnlyChanges(array $counts): int
    {
        return (int) (
            ($counts['new_product'] ?? 0)
            + ($counts['new_variant'] ?? 0)
            + ($counts['missing_product'] ?? 0)
            + ($counts['missing_variant'] ?? 0)
            + ($counts['category_changed'] ?? 0)
            + ($counts['image_changed'] ?? 0)
            + ($counts['content_changed'] ?? 0)
            + ($counts['variant_structure_changed'] ?? 0)
        );
    }

    private function buildReviewSummary(array $context): array
    {
        $flags = (array) ($context['delta']['flags'] ?? []);
        $counts = (array) ($context['counts'] ?? []);
        $blockingMissingReview = (bool) ($flags['feed_degraded'] ?? false) || (bool) ($flags['suspicious_feed_drop'] ?? false);

        return [
            'new_product' => (int) ($counts['new_product'] ?? 0),
            'new_variant' => (int) ($counts['new_variant'] ?? 0),
            'missing_product' => (int) ($counts['missing_product'] ?? 0),
            'missing_variant' => (int) ($counts['missing_variant'] ?? 0),
            'passive_candidate' => 0,
            'review_total' => $this->countDeltaReviewOnlyChanges($counts),
            'missing_review_blocked' => $blockingMissingReview,
            'missing_review_block_reason' => $blockingMissingReview
                ? 'Kaynak verisi eksik görünüyor; kaybolan ürün işlemi uygulanamaz.'
                : null,
        ];
    }

    private function emptyDirtyProjectionSummary(string $mode, string $reason): array
    {
        return [
            'projection_mode' => $mode,
            'projection_reason' => $reason,
            'projection_skipped' => true,
            'projection_skip_reason' => match ($reason) {
                'projection_disabled' => 'Delta apply fazında tenant projection kapalı tutuldu.',
                'no_dirty_products' => 'Apply edilen dirty standard product bulunmadı.',
                default => 'Dirty projection çalıştırılmadı.',
            },
            'dirty_standard_products_detected' => 0,
            'dirty_standard_products_projected' => 0,
            'dirty_standard_products_skipped' => 0,
            'affected_tenants_count' => 0,
            'tenant_catalog_products_updated' => 0,
            'tenant_catalog_variants_updated' => 0,
            'projection_skipped_no_tenant_access' => 0,
            'projection_skipped_standard_product_missing' => 0,
            'projection_skipped_review_only_change' => 0,
        ];
    }

    private function analyzeDirtyProjection(SupplierSource $source, array $context, ?array $dirtyStandardProductIds = null): array
    {
        $dirtyStandardProductIds ??= $this->collectDirtyStandardProductIdsFromContext($context);
        $summary = $this->emptyDirtyProjectionSummary('dirty', $dirtyStandardProductIds === [] ? 'no_dirty_products' : 'price_stock_delta');
        $summary['dirty_standard_products_detected'] = count($dirtyStandardProductIds);
        $summary['projection_reason'] = 'price_stock_delta';
        $summary['projection_skip_reason'] = $dirtyStandardProductIds === []
            ? 'Apply edilebilir dirty standard product bulunmadı.'
            : null;
        $summary['projection_skipped_review_only_change'] = $this->countDeltaReviewOnlyChanges($context['counts']);

        if ($dirtyStandardProductIds === []) {
            return $summary;
        }

        $tenantIds = TenantSupplierAccess::query()
            ->where('supplier_id', $source->supplier_id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('tenant_account_id')
            ->unique();

        if ($tenantIds->isEmpty()) {
            $summary['projection_skipped_no_tenant_access'] = count($dirtyStandardProductIds);
            $summary['dirty_standard_products_skipped'] = count($dirtyStandardProductIds);

            return $summary;
        }

        $summary['affected_tenants_count'] = $tenantIds->count();
        $summary['dirty_standard_products_projected'] = count($dirtyStandardProductIds);

        foreach ($tenantIds as $tenantId) {
            $tenant = TenantAccount::query()->find($tenantId);
            if (!$tenant) {
                continue;
            }

            $analysis = $this->tenantCatalogProjection->analyzeForTenant($tenant, [
                'supplier_ids' => [$source->supplier_id],
                'standard_product_ids' => $dirtyStandardProductIds,
            ]);

            $summary['tenant_catalog_products_updated'] += (int) (($analysis['would_create_products'] ?? 0) + ($analysis['would_update_products'] ?? 0));
            $summary['tenant_catalog_variants_updated'] += (int) (($analysis['would_create_variants'] ?? 0) + ($analysis['would_update_variants'] ?? 0));
        }

        return $summary;
    }

    private function collectDirtyStandardProductIdsFromContext(array $context, ?array $allowedIdentityKeys = null): array
    {
        $allowedIdentityLookup = $allowedIdentityKeys !== null
            ? array_flip(array_values(array_unique(array_filter($allowedIdentityKeys))))
            : null;
        $currentProductsByKey = $context['products']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->productIdentityKey($row) => $row]));
        $currentVariantsByKey = $context['variants']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->variantIdentityKey($row) => $row]));
        $existingProductsByKey = $context['existing_products']
            ->mapWithKeys(fn (SupplierProductRaw $row) => array_filter([$this->deltaHashService->productIdentityKey([
                'supplier_product_id' => $row->supplier_product_id,
                'supplier_product_code' => $row->supplier_product_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));
        $existingVariantsByKey = $context['existing_variants']
            ->mapWithKeys(fn (SupplierProductVariantRaw $row) => array_filter([$this->deltaHashService->variantIdentityKey([
                'variant_id' => $row->variant_id,
                'variant_stock_code' => $row->variant_stock_code,
                'variant_code' => $row->variant_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));

        $ids = [];
        foreach ((array) ($context['delta']['changes'] ?? []) as $change) {
            $type = (string) ($change['type'] ?? '');
            if (!in_array($type, ['price_changed', 'stock_changed', 'price_and_stock_changed'], true)) {
                continue;
            }

            $identityKey = (string) ($change['identity_key'] ?? '');
            if ($identityKey === '') {
                continue;
            }

            if ($allowedIdentityLookup !== null && !isset($allowedIdentityLookup[$identityKey])) {
                continue;
            }

            if (($change['scope'] ?? '') === 'product') {
                $existingProduct = $existingProductsByKey[$identityKey] ?? null;
                $currentProduct = $currentProductsByKey[$identityKey] ?? null;
                if ($existingProduct && $currentProduct && $existingProduct->standard_product_id) {
                    $ids[] = (int) $existingProduct->standard_product_id;
                }
            }

            if (($change['scope'] ?? '') === 'variant') {
                $existingVariant = $existingVariantsByKey[$identityKey] ?? null;
                $currentVariant = $currentVariantsByKey[$identityKey] ?? null;
                if ($existingVariant && $currentVariant && $existingVariant->standard_product_variant_id) {
                    $variant = StandardProductVariant::query()->find($existingVariant->standard_product_variant_id);
                    if ($variant?->standard_product_id) {
                        $ids[] = (int) $variant->standard_product_id;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function synchronizeDeltaReviewChanges(ProductDataHubSyncRun $run, SupplierSource $source, array $context): array
    {
        $policy = $this->resolveSyncPolicy($source);
        $graceRuns = max(2, (int) ($policy['missing_product_grace_runs'] ?? 2));
        $flags = (array) ($context['delta']['flags'] ?? []);
        $blockingMissingReview = (bool) ($flags['feed_degraded'] ?? false) || (bool) ($flags['suspicious_feed_drop'] ?? false);

        $currentProductsByKey = $context['products']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->productIdentityKey($row) => $row]));
        $currentVariantsByKey = $context['variants']
            ->mapWithKeys(fn (array $row) => array_filter([$this->deltaHashService->variantIdentityKey($row) => $row]));
        $existingProductsByKey = $context['existing_products']
            ->mapWithKeys(fn (SupplierProductRaw $row) => array_filter([$this->deltaHashService->productIdentityKey([
                'supplier_product_id' => $row->supplier_product_id,
                'supplier_product_code' => $row->supplier_product_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));
        $existingVariantsByKey = $context['existing_variants']
            ->mapWithKeys(fn (SupplierProductVariantRaw $row) => array_filter([$this->deltaHashService->variantIdentityKey([
                'variant_id' => $row->variant_id,
                'variant_stock_code' => $row->variant_stock_code,
                'variant_code' => $row->variant_code,
                'supplier_group_code' => $row->supplier_group_code,
            ]) => $row]));

        $seenProductKeys = [];
        $seenVariantKeys = [];
        $summary = $this->buildReviewSummary($context);

        foreach ((array) ($context['delta']['changes'] ?? []) as $change) {
            $type = (string) ($change['type'] ?? '');
            $identityKey = (string) ($change['identity_key'] ?? '');
            $scope = (string) ($change['scope'] ?? '');

            if (!in_array($type, ProductDataHubSyncChange::REVIEWABLE_CHANGE_TYPES, true) || $identityKey === '') {
                continue;
            }

            if ($scope === 'product' && !str_starts_with($type, 'missing_')) {
                $seenProductKeys[] = $identityKey;
            } elseif ($scope === 'variant' && !str_starts_with($type, 'missing_')) {
                $seenVariantKeys[] = $identityKey;
            }

            if (str_starts_with($type, 'new_')) {
                $payload = $scope === 'product'
                    ? $this->buildNewProductReviewPayload($source, $currentProductsByKey[$identityKey] ?? [], $context)
                    : $this->buildNewVariantReviewPayload($source, $currentVariantsByKey[$identityKey] ?? [], $context);

                $this->recordReviewChange(
                    $run,
                    $source,
                    $type,
                    $identityKey,
                    $change['old_value'] ?? null,
                    $change['new_value'] ?? null,
                    $change['message'] ?? 'İnceleme bekleyen yeni kayıt tespit edildi.',
                    [
                        'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
                        'review_payload' => $payload,
                        'reviewed_at' => null,
                        'resolved_at' => null,
                        'missing_feed_run_count' => 0,
                        'is_passive_candidate' => false,
                    ]
                );

                continue;
            }

            if (!str_starts_with($type, 'missing_')) {
                continue;
            }

            $openReview = ProductDataHubSyncChange::query()
                ->openReview()
                ->where('supplier_source_id', $source->id)
                ->where('change_type', $type)
                ->where('supplier_product_key', $identityKey)
                ->first();

            $missingRuns = $blockingMissingReview
                ? (int) ($openReview?->missing_feed_run_count ?? 0)
                : ((int) ($openReview?->missing_feed_run_count ?? 0) + 1);
            $isPassiveCandidate = !$blockingMissingReview && $missingRuns >= $graceRuns;
            $payload = $scope === 'product'
                ? $this->buildMissingProductReviewPayload($source, $existingProductsByKey[$identityKey] ?? null, $missingRuns, $isPassiveCandidate)
                : $this->buildMissingVariantReviewPayload($source, $existingVariantsByKey[$identityKey] ?? null, $missingRuns, $isPassiveCandidate);

            $this->recordReviewChange(
                $run,
                $source,
                $type,
                $identityKey,
                $change['old_value'] ?? null,
                $change['new_value'] ?? null,
                $blockingMissingReview
                    ? 'Kaynak verisi eksik görünüyor; kaybolan ürün işlemi review kuyruğunda tutuldu.'
                    : ($change['message'] ?? 'Kaynakta görünmeyen kayıt review kuyruğuna alındı.'),
                [
                    'review_status' => $isPassiveCandidate
                        ? ProductDataHubSyncChange::REVIEW_STATUS_PASSIVE_CANDIDATE
                        : ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
                    'review_payload' => $payload,
                    'reviewed_at' => null,
                    'resolved_at' => null,
                    'missing_feed_run_count' => $missingRuns,
                    'is_passive_candidate' => $isPassiveCandidate,
                ]
            );

            if ($isPassiveCandidate) {
                $summary['passive_candidate']++;
            }
        }

        $this->resolveRecoveredMissingReviews($run, $source, array_unique($seenProductKeys), 'missing_product');
        $this->resolveRecoveredMissingReviews($run, $source, array_unique($seenVariantKeys), 'missing_variant');

        return $summary;
    }

    private function resolveRecoveredMissingReviews(ProductDataHubSyncRun $run, SupplierSource $source, array $visibleIdentityKeys, string $changeType): void
    {
        if ($visibleIdentityKeys === []) {
            return;
        }

        ProductDataHubSyncChange::query()
            ->openReview()
            ->where('supplier_source_id', $source->id)
            ->where('change_type', $changeType)
            ->whereIn('supplier_product_key', $visibleIdentityKeys)
            ->get()
            ->each(function (ProductDataHubSyncChange $review) use ($run) {
                $review->forceFill([
                    'sync_run_id' => $run->id,
                    'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_RESOLVED,
                    'resolved_at' => now(),
                    'is_passive_candidate' => false,
                    'message' => 'Kayıt sonraki delta taramasında yeniden görüldü; review çözüldü.',
                ])->save();
            });
    }

    private function buildNewProductReviewPayload(SupplierSource $source, array $product, array $context): array
    {
        return [
            'supplier_name' => $source->supplier?->name,
            'source_name' => $source->source_name,
            'product_code' => $product['supplier_product_code'] ?? $product['generated_product_code'] ?? null,
            'group_code' => $product['supplier_group_code'] ?? null,
            'product_name' => $product['product_name'] ?? null,
            'original_category' => $product['supplier_category_name'] ?? null,
            'list_price' => $product['list_price'] ?? data_get($product, 'normalized_payload.list_price'),
            'stock_quantity' => $product['stock_quantity'] ?? data_get($product, 'normalized_payload.stock_quantity'),
            'currency' => $product['currency'] ?? data_get($product, 'normalized_payload.currency'),
            'has_image' => filled($product['image_url'] ?? null),
            'identity_status' => data_get($context, 'delta.identity_summary.label', 'Riskli'),
            'category_mapping_status' => blank($product['mapped_standard_category_id'] ?? null) ? 'pending' : 'mapped',
            'last_scanned_at' => now()->toISOString(),
        ];
    }

    private function buildNewVariantReviewPayload(SupplierSource $source, array $variant, array $context): array
    {
        return [
            'supplier_name' => $source->supplier?->name,
            'source_name' => $source->source_name,
            'parent_product_code' => $variant['parent_supplier_product_id'] ?? $variant['supplier_group_code'] ?? null,
            'group_code' => $variant['supplier_group_code'] ?? null,
            'variant_code' => $variant['variant_stock_code'] ?? $variant['variant_code'] ?? $variant['generated_variant_code'] ?? null,
            'variant_name' => $variant['variant_name'] ?? null,
            'variant_color' => $variant['variant_color'] ?? null,
            'variant_size' => $variant['variant_size'] ?? null,
            'list_price' => $variant['list_price'] ?? data_get($variant, 'normalized_payload.list_price'),
            'stock_quantity' => $variant['variant_stock_quantity'] ?? data_get($variant, 'normalized_payload.variant_stock_quantity'),
            'has_image' => filled($variant['variant_image_url'] ?? null),
            'identity_status' => data_get($context, 'delta.identity_summary.label', 'Riskli'),
            'last_scanned_at' => now()->toISOString(),
        ];
    }

    private function buildMissingProductReviewPayload(SupplierSource $source, ?SupplierProductRaw $product, int $missingRuns, bool $isPassiveCandidate): array
    {
        return [
            'supplier_name' => $source->supplier?->name,
            'source_name' => $source->source_name,
            'product_code' => $product?->supplier_product_code ?: $product?->generated_product_code,
            'product_name' => $product?->product_name,
            'last_seen_at' => optional($product?->updated_at)->toISOString(),
            'missing_feed_run_count' => $missingRuns,
            'exists_in_tenant_catalog' => (bool) $product?->standard_product_id,
            'snapshot_impact_note' => 'Aktif teklif/sipariş snapshotları etkilenmez.',
            'suggested_action' => $isPassiveCandidate ? 'Pasife alma adayı' : 'İncele',
        ];
    }

    private function buildMissingVariantReviewPayload(SupplierSource $source, ?SupplierProductVariantRaw $variant, int $missingRuns, bool $isPassiveCandidate): array
    {
        return [
            'supplier_name' => $source->supplier?->name,
            'source_name' => $source->source_name,
            'parent_product_code' => $variant?->parent_supplier_product_id ?: $variant?->supplier_group_code,
            'variant_code' => $variant?->variant_stock_code ?: $variant?->variant_code,
            'last_list_price' => data_get($variant?->normalized_payload ?? [], 'list_price'),
            'last_stock_quantity' => $variant?->variant_stock_quantity,
            'last_seen_at' => optional($variant?->updated_at)->toISOString(),
            'missing_feed_run_count' => $missingRuns,
            'suggested_action' => $isPassiveCandidate ? 'Pasife alma adayı' : 'İncele',
        ];
    }
}
