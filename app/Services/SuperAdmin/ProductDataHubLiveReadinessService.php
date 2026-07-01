<?php

namespace App\Services\SuperAdmin;

use App\Models\FeedSyncLog;
use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\ProductHubOperationFlowService;
use App\Services\ProductDataHub\ProductHubSellableTruthService;
use App\Services\ProductDataHub\SensitiveDataMasker;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductDataHubLiveReadinessService
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary,
        private readonly SourceFetchService $sourceFetchService,
        private readonly SourceParserService $sourceParserService,
        private readonly PreviewParserService $previewParserService,
        private readonly ProductHubOperationFlowService $productHubOperationFlowService,
        private readonly ProductHubSellableTruthService $sellableTruthService,
        private readonly SensitiveDataMasker $sensitiveDataMasker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReadinessContext(?array $overviewCounts = null): array
    {
        $sources = SupplierSource::query()
            ->with(['supplier', 'fieldMappings', 'categoryMappings', 'syncRuns'])
            ->visibleInProductDataHub()
            ->orderBy('supplier_id')
            ->orderBy('source_name')
            ->get();

        $rows = $sources->map(fn (SupplierSource $source): array => $this->buildSourceRow($source))->values();
        $warnings = [];

        if ($overviewCounts === null) {
            try {
                $overviewCounts = (array) data_get($this->productHubOperationFlowService->buildOverview(), 'counts', []);
            } catch (\Throwable $exception) {
                $warnings[] = 'Product Data Hub operasyon özeti tam okunamadı: ' . $this->safeMessage($exception->getMessage());
                $overviewCounts = [];
            }
        }

        return [
            'checked_at' => $this->checkedAt(),
            'sources' => $rows->all(),
            'counts' => [
                'active_sources' => $rows->count(),
                'preview_ready' => $rows->where('preview.status', 'healthy')->count(),
                'preview_manual' => $rows->filter(fn (array $row): bool => (bool) ($row['preview']['is_manual_check'] ?? false))->count(),
                'mapping_ready' => $rows->where('mapping_readiness.status', 'ready')->count(),
                'mapping_missing' => $rows->filter(fn (array $row): bool => in_array($row['mapping_readiness']['status'], ['missing', 'critical_missing'], true))->count(),
                'supplier_access_followup' => $rows->where('supplier_access_followup', true)->count(),
            ],
            'truth_smoke' => $this->buildTruthSmoke(),
            'risk_queue' => [
                'review_pending' => max(
                    $rows->sum(fn (array $row): int => (int) ($row['category_mapping']['pending_count'] ?? 0)),
                    (int) ($overviewCounts['review_required'] ?? 0)
                ),
                'category_waiting' => $rows->sum(fn (array $row): int => (int) ($row['category_mapping']['pending_count'] ?? 0)),
                'price_control' => (int) ($overviewCounts['price_changed'] ?? 0),
                'stock_control' => (int) ($overviewCounts['stock_changed'] ?? 0),
                'projection_stale' => (int) ($overviewCounts['projection_issues'] ?? 0),
                'sync_errors' => $rows->filter(fn (array $row): bool => ($row['preview']['status'] ?? '') === 'critical'
                    || ($row['status_key'] ?? '') === 'error'
                    || filled($row['last_error'] ?? null))->count(),
                'projection_pending' => (int) ($overviewCounts['tenant_output_blocks'] ?? 0),
                'tenant_impact_risk' => $rows->where('supplier_access_followup', true)->count(),
                'source_error' => $rows->where('status_key', 'error')->count(),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSourceRow(SupplierSource $source): array
    {
        $latestRun = $source->syncRuns->sortByDesc('id')->first();
        $latestPreview = FeedSyncLog::query()
            ->where('supplier_source_id', $source->id)
            ->latest('id')
            ->first();
        $preview = $this->buildPreviewStatus($source);
        $mappingReadiness = $this->buildMappingReadiness($source, $preview);
        $categoryPendingCount = $source->categoryMappings
            ->filter(fn (SupplierCategoryMapping $mapping): bool => $this->mappingNeedsAttention($mapping))
            ->count();
        $tenantCatalogCount = $this->tenantCatalogCount($source);
        $quoteVisibleCount = $this->quoteVisibleCount($source);
        $tenantImpactCount = TenantSupplierAccess::query()
            ->active()
            ->where('supplier_id', $source->supplier_id)
            ->count();
        $schedulerFrequency = $this->resolveSyncFrequency($source);
        $supplierAccessFollowup = $tenantImpactCount > 0
            && ($tenantCatalogCount === 0 || in_array($schedulerFrequency, ['manual', 'unknown'], true));

        return [
            'source_id' => $source->id,
            'supplier_name' => $source->supplier?->name ?? ('Tedarikçi #' . $source->supplier_id),
            'source_key' => $this->sourceKey($source),
            'source_type' => $this->sourceTypeLabel($source),
            'status_key' => (string) $source->status,
            'status_label' => $source->getStatusDisplayName(),
            'last_preview_at' => $this->formatDateTime($latestPreview?->completed_at ?? $latestPreview?->started_at),
            'last_sync_at' => $this->formatDateTime($latestRun?->finished_at ?? $source->last_sync_at),
            'last_error' => $this->safeMessage((string) ($latestRun?->error_message ?: $latestPreview?->error_summary ?: $source->last_error ?: '')),
            'mapping_readiness' => $mappingReadiness,
            'category_mapping' => [
                'status' => $categoryPendingCount > 0 ? 'warning' : 'ready',
                'status_label' => $categoryPendingCount > 0 ? 'Kontrol Gerekir' : 'Hazır',
                'pending_count' => $categoryPendingCount,
            ],
            'tenant_catalog_effect' => $tenantCatalogCount > 0
                ? $tenantCatalogCount . ' katalog kaydı etkileniyor'
                : ($tenantImpactCount > 0 ? 'Erişim açık, katalog yansıması manuel kontrol ister' : 'Henüz katalog etkisi görünmüyor'),
            'quote_visibility_effect' => $quoteVisibleCount > 0
                ? $quoteVisibleCount . ' teklif görünürlüğü kaydı etkileniyor'
                : ($tenantImpactCount > 0 ? 'Teklif görünürlüğü scheduler/projection sonrası doğrulanmalı' : 'Henüz teklif görünürlüğü etkisi görünmüyor'),
            'scheduler_frequency' => $this->syncFrequencyLabel($schedulerFrequency),
            'heartbeat_key' => $this->heartbeatKeyFor($schedulerFrequency),
            'preview' => $preview,
            'tenant_impact_count' => $tenantImpactCount,
            'supplier_access_followup' => $supplierAccessFollowup,
            'action_route' => $preview['route']
                ?? route('admin.super.product-data-hub.field-mappings.source', $source),
            'action_label' => $preview['action_label']
                ?? 'Detaya Git',
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    protected function buildMappingReadiness(SupplierSource $source, array $preview): array
    {
        $mappedTargets = $source->fieldMappings
            ->pluck('target_field')
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
        $required = $this->fieldDictionary->buildRequiredMappingSummary(
            $source->fieldMappings
                ->map(fn (SupplierFieldMapping $mapping) => ['target_field' => $mapping->target_field])
                ->all()
        );
        $fieldStates = [
            'product_code' => $this->mappingFieldState($mappedTargets, ['supplier_product_code', 'variant_stock_code'], true),
            'product_name' => $this->mappingFieldState($mappedTargets, ['product_name', 'base_product_name', 'display_product_name'], true),
            'variant_code' => $this->mappingFieldState($mappedTargets, ['variant_code', 'variant_stock_code']),
            'price' => $this->mappingFieldState($mappedTargets, ['list_price', 'purchase_price'], true),
            'stock' => $this->mappingFieldState($mappedTargets, ['stock_quantity', 'variant_stock_quantity']),
            'category' => $this->mappingFieldState($mappedTargets, ['supplier_category_name', 'supplier_subcategory_name', 'supplier_category_id']),
            'image' => $this->mappingFieldState($mappedTargets, ['image_url', 'parent_image_url', 'variant_image_url']),
            'update_key' => $this->mappingFieldState($mappedTargets, ['supplier_product_id', 'parent_supplier_product_id', 'supplier_product_code', 'variant_stock_code'], true),
        ];

        $status = 'ready';
        if ($required['count'] > 0) {
            $status = 'critical_missing';
        } elseif (collect($fieldStates)->contains(fn (array $field) => $field['status'] !== 'ready')) {
            $status = 'warning';
        }

        if ($preview['status'] === 'warning' && $status === 'ready') {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Hazır',
                'critical_missing' => 'Kritik Eksik',
                'missing' => 'Eksik',
                default => 'Kontrol Gerekir',
            },
            'missing_required' => array_map(fn (array $item): string => $item['label'], $required['missing']),
            'fields' => $fieldStates,
            'custom_xml_node_path' => $this->xmlNodePathStatus($source),
            'suggested_mapping_note' => $source->fieldMappings->isEmpty() && ($preview['sample_count'] ?? 0) > 0
                ? 'Kaynak okunuyor; alan eşlemeleri kaydedilmeden önce önizleme ekranında manuel kontrol gerekir.'
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPreviewStatus(SupplierSource $source): array
    {
        $checkedAt = $this->checkedAt();

        if (!$this->canRunReadOnlyPreview($source)) {
            return [
                'status' => 'warning',
                'status_label' => 'Kontrol Gerekir',
                'description' => 'Bu kaynak için canlı önizleme manuel kontrol gerektirir.',
                'checked_at' => $checkedAt,
                'sample_count' => 0,
                'sample' => [],
                'warnings' => [],
                'is_manual_check' => true,
                'route' => route('admin.super.product-data-hub.sources.preview', $source),
                'action_label' => 'Önizlemeyi Aç',
            ];
        }

        try {
            $content = $this->sourceFetchService->fetch($source, ['max_bytes' => 262144]);
            $parsed = $this->sourceParserService->parse($source, $content, 3);
            $rows = (array) ($parsed['rows'] ?? []);
            $preview = $this->previewParserService->previewSource($source, $rows);
            $sampleProducts = collect((array) ($preview['products'] ?? []))->take(1);
            $sampleVariants = collect((array) ($preview['variants'] ?? []))->take(1);
            $sample = $sampleVariants->isNotEmpty() ? $sampleVariants->first() : $sampleProducts->first();

            $warnings = collect((array) ($preview['mapping_warnings'] ?? []))
                ->merge($sample['warnings'] ?? [])
                ->filter()
                ->map(fn ($warning) => $this->safeMessage((string) $warning))
                ->unique()
                ->values()
                ->take(3)
                ->all();

            $hasCode = filled(data_get($sample, 'supplier_product_code')) || filled(data_get($sample, 'variant_stock_code'));
            $hasPrice = filled(data_get($sample, 'list_price')) || filled(data_get($sample, 'purchase_price'));
            $hasStock = filled(data_get($sample, 'stock_quantity')) || filled(data_get($sample, 'variant_stock_quantity'));
            $status = ($hasCode && $hasPrice) ? 'healthy' : 'warning';
            if (empty($rows)) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'status_label' => $status === 'healthy' ? 'Hazır' : 'Kontrol Gerekir',
                'description' => $status === 'healthy'
                    ? 'Kaynak read-only önizleme ile okunabildi.'
                    : 'Kaynak okunuyor ancak alanların manuel gözle kontrolü gerekir.',
                'checked_at' => $checkedAt,
                'sample_count' => count($rows),
                'sample' => [
                    'product_code' => Str::limit((string) (data_get($sample, 'supplier_product_code') ?: data_get($sample, 'variant_stock_code') ?: 'Belirtilmedi'), 80),
                    'product_name' => Str::limit((string) (data_get($sample, 'product_name') ?: 'Belirtilmedi'), 80),
                    'price' => data_get($sample, 'list_price') ?: data_get($sample, 'purchase_price') ?: 'Belirtilmedi',
                    'stock' => data_get($sample, 'stock_quantity') ?: data_get($sample, 'variant_stock_quantity') ?: 'Belirtilmedi',
                    'image' => filled(data_get($sample, 'image_url') ?: data_get($sample, 'variant_image_url')) ? 'Okunuyor' : 'Yok',
                    'category' => filled(data_get($sample, 'supplier_category_name')) ? 'Okunuyor' : 'Yok',
                ],
                'warnings' => $warnings,
                'is_manual_check' => false,
                'route' => route('admin.super.product-data-hub.sources.preview', $source),
                'action_label' => 'Önizlemeyi Aç',
                'read_only_confirmed' => $hasCode || $hasPrice || $hasStock,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'warning',
                'status_label' => 'Kontrol Gerekir',
                'description' => 'Read-only önizleme tamamlanamadı; kaynak manuel kontrol gerektirir.',
                'checked_at' => $checkedAt,
                'sample_count' => 0,
                'sample' => [],
                'warnings' => [$this->safePreviewFailureMessage($source, $exception)],
                'is_manual_check' => true,
                'route' => route('admin.super.product-data-hub.sources.preview', $source),
                'action_label' => 'Önizlemeyi Aç',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTruthSmoke(): array
    {
        $rows = collect();
        $warnings = [];
        $candidates = $this->truthSmokeCandidates();

        foreach ($candidates as $candidate) {
            $catalogProduct = $candidate['product'];
            $variant = $candidate['variant'];
            $truth = $this->sellableTruthService->resolve($catalogProduct, $variant);
            $catalogPrice = (float) ($variant?->display_price ?? $catalogProduct->display_price ?? 0);

            $rows->push([
                'sample_label' => $candidate['label'],
                'product_code' => $variant?->variant_code ?: $catalogProduct->product_code,
                'effective_price' => $truth['effective_price'],
                'catalog_price' => $catalogPrice,
                'effective_stock' => $truth['effective_stock'],
                'category_status' => $truth['category_status'],
                'quote_visibility_status' => $truth['quote_visibility_status'],
                'tenant_catalog_status' => $truth['tenant_catalog_status'],
                'price_match' => abs(((float) $truth['effective_price']) - $catalogPrice) < 0.0001,
            ]);
        }

        if ($rows->isEmpty()) {
            $warnings[] = 'Canlı truth zinciri için örnek katalog ürünü bulunamadı; manuel kontrol gerekir.';
        }

        return [
            'status' => $rows->isEmpty() ? 'unknown' : ($rows->every(fn (array $row): bool => $row['price_match']) ? 'healthy' : 'warning'),
            'status_label' => $rows->isEmpty()
                ? 'Bilinmiyor'
                : ($rows->every(fn (array $row): bool => $row['price_match']) ? 'Hazır' : 'Kontrol Gerekir'),
            'rows' => $rows->take(3)->values()->all(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return Collection<int, array{label: string, product: \App\Models\TenantCatalogProduct, variant: \App\Models\TenantCatalogProductVariant|null}>
     */
    protected function truthSmokeCandidates(): Collection
    {
        $labels = [
            'etkin' => ['Etkin', '0506', 'ET-0506'],
            'ilpen' => ['İlpen', 'ILPEN', 'İLPEN'],
            'akdeniz' => ['Akdeniz', 'AKDENIZ', 'AKDENİZ'],
        ];
        $candidates = collect();

        foreach ($labels as $label => $needles) {
            $product = TenantCatalogProduct::query()
                ->with(['variants', 'standardProduct.rawProducts'])
                ->where(function ($query) use ($needles) {
                    foreach ($needles as $needle) {
                        $query->orWhere('product_name', 'like', '%' . $needle . '%')
                            ->orWhere('product_code', 'like', '%' . $needle . '%')
                            ->orWhere('name', 'like', '%' . $needle . '%');
                    }
                })
                ->latest('id')
                ->first();

            if (!$product) {
                continue;
            }

            $variant = $product->variants->first();
            $candidates->push([
                'label' => ucfirst($label),
                'product' => $product,
                'variant' => $variant,
            ]);
        }

        if ($candidates->count() < 3) {
            $fallbackProducts = TenantCatalogProduct::query()
                ->with('variants')
                ->latest('id')
                ->take(3)
                ->get();

            foreach ($fallbackProducts as $product) {
                if ($candidates->contains(fn (array $row): bool => $row['product']->id === $product->id)) {
                    continue;
                }

                $candidates->push([
                    'label' => 'Genel Örnek',
                    'product' => $product,
                    'variant' => $product->variants->first(),
                ]);
            }
        }

        return $candidates->take(3)->values();
    }

    protected function tenantCatalogCount(SupplierSource $source): int
    {
        return TenantCatalogProduct::query()
            ->whereHas('standardProduct.rawProducts', fn ($query) => $query->where('supplier_source_id', $source->id))
            ->count();
    }

    protected function quoteVisibleCount(SupplierSource $source): int
    {
        return TenantCatalogProduct::query()
            ->where('visible_in_quote', true)
            ->whereHas('standardProduct.rawProducts', fn ($query) => $query->where('supplier_source_id', $source->id))
            ->count();
    }

    protected function canRunReadOnlyPreview(SupplierSource $source): bool
    {
        return filled(data_get($source->config, 'source_file_path'));
    }

    protected function resolveSyncFrequency(SupplierSource $source): string
    {
        return (string) data_get($source->config, 'sync_policy.sync_frequency', data_get($source->config, 'sync_frequency', 'manual'));
    }

    protected function syncFrequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            'hourly' => 'Saatlik',
            'daily' => 'Günlük',
            'weekly' => 'Haftalık',
            'manual' => 'Manuel',
            default => 'Bilinmiyor',
        };
    }

    protected function heartbeatKeyFor(string $frequency): ?string
    {
        return match ($frequency) {
            'hourly' => 'product_data_hub_hourly',
            'daily' => 'product_data_hub_daily',
            'weekly' => 'product_data_hub_weekly',
            default => null,
        };
    }

    /**
     * @param array<int, string> $mappedTargets
     * @param array<int, string> $acceptedFields
     * @return array<string, string>
     */
    protected function mappingFieldState(array $mappedTargets, array $acceptedFields, bool $required = false): array
    {
        $ready = collect($acceptedFields)->contains(fn (string $field): bool => in_array($field, $mappedTargets, true));
        $status = $ready ? 'ready' : ($required ? 'critical_missing' : 'warning');

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Hazır',
                'critical_missing' => 'Kritik Eksik',
                default => 'Kontrol Gerekir',
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function xmlNodePathStatus(SupplierSource $source): array
    {
        $path = data_get($source->config, 'product_node_path')
            ?? data_get($source->config, 'item_path')
            ?? data_get($source->config, 'record_path');

        if ($source->source_type !== 'xml') {
            return ['status' => 'ready', 'status_label' => 'Hazır'];
        }

        return filled($path)
            ? ['status' => 'ready', 'status_label' => 'Hazır']
            : ['status' => 'warning', 'status_label' => 'Kontrol Gerekir'];
    }

    protected function mappingNeedsAttention(SupplierCategoryMapping $mapping): bool
    {
        return blank($mapping->standard_category_id)
            || in_array((string) $mapping->mapping_status, ['pending', 'needs_review', 'conflict'], true);
    }

    protected function sourceKey(SupplierSource $source): string
    {
        $candidate = data_get($source->config, 'profile_key')
            ?: data_get($source->config, 'source_profile_template')
            ?: $source->source_name
            ?: ('source-' . $source->id);

        return Str::upper(Str::slug((string) $candidate, '_'));
    }

    protected function sourceTypeLabel(SupplierSource $source): string
    {
        $uiType = (string) (data_get($source->config, 'ui_source_type', '') ?: data_get($source->config, 'format', ''));

        return match (strtolower($uiType !== '' ? $uiType : (string) $source->source_type)) {
            'xml' => 'XML',
            'json' => 'JSON',
            'api' => 'API',
            'csv' => 'CSV',
            'excel' => 'CSV',
            'manual' => 'local/file',
            default => $source->getSourceTypeDisplayName(),
        };
    }

    protected function safePreviewFailureMessage(SupplierSource $source, \Throwable $exception): string
    {
        if (filled(data_get($source->config, 'source_file_path'))) {
            return 'Yerel kaynak dosyası read-only önizleme için okunamadı.';
        }

        return 'Kaynak önizlemesi manuel kontrol gerektiriyor: ' . $this->safeMessage($exception->getMessage());
    }

    protected function safeMessage(?string $message): string
    {
        $masked = $this->sensitiveDataMasker->maskExceptionMessage((string) $message) ?? '';
        $masked = preg_replace('/[A-Z]:\\\\[^\\s"]+/i', '[gizli-yol]', $masked) ?? $masked;
        $masked = preg_replace('/\/[^\\s"]+\/[^\\s"]+/i', '[gizli-yol]', $masked) ?? $masked;

        return trim(Str::limit(strip_tags((string) $masked), 180)) !== ''
            ? trim(Str::limit(strip_tags((string) $masked), 180))
            : 'Hassas detay gizlendi.';
    }

    protected function checkedAt(): string
    {
        return now()->format('d.m.Y H:i');
    }

    protected function formatDateTime(mixed $value): string
    {
        if (!$value) {
            return 'Bilinmiyor';
        }

        if ($value instanceof Carbon) {
            return $value->format('d.m.Y H:i');
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return 'Bilinmiyor';
        }
    }
}
