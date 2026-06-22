<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Arr;

class SupplierSourceSyncService
{
    public function __construct(
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser,
        private readonly PreviewParserService $previewParser,
        private readonly RawProductStagingService $rawProductStaging,
        private readonly StandardProductBuilderService $standardProductBuilder,
        private readonly TenantCatalogProjectionService $tenantCatalogProjection,
    ) {
    }

    public function syncSource(SupplierSource $source, array $options = []): array
    {
        $fetchResult = $this->sourceFetch->fetch($source);
        $runType = (string) ($options['run_type'] ?? 'manual');

        if (!$fetchResult['ok']) {
            return $this->createFailedRun($source, $fetchResult['errors'] ?? ['Kaynak okunamadı.'], $runType, $options);
        }

        $parserResult = $this->sourceParser->parse($source, (string) $fetchResult['content'], 0);

        if (!$parserResult['ok']) {
            return $this->createFailedRun($source, $parserResult['errors'] ?? ['Kaynak ayrıştırılamadı.'], $runType, $options);
        }

        $preview = $this->previewParser->previewSource($source, $parserResult['rows']);

        if (($preview['source_mode'] ?? 'demo_fallback') !== 'live_source') {
            return $this->createFailedRun($source, ['Demo önizleme ile senkron yapılamaz.'], $runType, $options);
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
            'status' => 'running',
            'triggered_by' => auth()->id(),
        ]);

        $policy = $this->resolveSyncPolicy($source);
        $products = collect($previewData['products'] ?? []);
        $variants = collect($previewData['variants'] ?? []);
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
            'status' => $stats['error_count'] > 0 ? 'partial' : 'success',
            'finished_at' => now(),
            'report_payload' => $reportPayload,
        ]));

        $source->update(['last_sync_at' => now()]);

        return ['run' => $run->fresh(), 'stats' => $stats];
    }

    private function createFailedRun(SupplierSource $source, array $errors, string $runType = 'manual', array $options = []): array
    {
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => $runType,
            'started_at' => now(),
            'finished_at' => now(),
            'status' => 'failed',
            'error_count' => count($errors),
            'error_message' => implode(' | ', $errors),
            'triggered_by' => auth()->id(),
            'report_payload' => [
                'dry_run' => (bool) ($options['dry_run'] ?? false),
                'options' => Arr::only($options, ['dry_run', 'no_build', 'no_project', 'force']),
            ],
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
            'status' => 'running',
            'triggered_by' => auth()->id(),
        ]);

        $products = collect($previewData['products'] ?? []);
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
            'variants' => count($previewData['variants'] ?? []),
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
            'status' => $stats['error_count'] > 0 ? 'partial' : 'success',
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
        ];

        $tenantIds = TenantSupplierAccess::query()
            ->where('supplier_id', $source->supplier_id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('tenant_account_id')
            ->unique()
            ->values();

        foreach ($tenantIds as $tenantId) {
            $projectionTenant = TenantAccount::query()->find($tenantId);
            if (!$projectionTenant) {
                continue;
            }

            $result = $this->tenantCatalogProjection->projectForTenant($projectionTenant, [
                'supplier_ids' => [$source->supplier_id],
            ]);

            foreach ($stats as $key => $value) {
                $stats[$key] += (int) ($result[$key] ?? 0);
            }
        }

        return $stats;
    }
}
