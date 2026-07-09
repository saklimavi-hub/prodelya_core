<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\FeedSyncError;
use App\Models\FeedSyncLog;
use App\Models\OrderItem;
use App\Models\ProductCategorySuggestionLog;
use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardProductImage;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\SupplierProductVariantRaw;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Services\ProductFieldDictionaryService;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\ProductHubSyncDecisionService;
use App\Services\ProductDataHub\PozitronSourceProvisioningService;
use App\Services\ProductDataHub\RawProductStagingService;
use App\Services\ProductDataHub\SensitiveDataMasker;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SuperAdminSupplierSourceController extends Controller
{
    private const MASKED_SECRET_VALUE = '[hidden]';

    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary,
        private readonly PreviewParserService $previewParser,
        private readonly ProductHubSyncDecisionService $productHubSyncDecisionService,
        private readonly PozitronSourceProvisioningService $pozitronProvisioning,
        private readonly RawProductStagingService $rawProductStaging,
        private readonly SensitiveDataMasker $sensitiveDataMasker,
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser,
        private readonly SupplierSourceSyncService $sourceSync
    ) {
        // TODO: Add middleware for global supplier sources
        // $this->middleware('permission:manage_product_data_hub');
    }

    public function index(Request $request): View
    {
        $requestedFilter = trim((string) $request->query('filter', ''));
        $showTemp = $request->boolean('show_temp');
        $activeFilter = $requestedFilter !== ''
            ? $requestedFilter
            : ($showTemp ? 'temp' : 'active');
        $allSources = $this->buildHydratedSourceFlowCollection();
        $sources = match ($activeFilter) {
            'temp' => $allSources->filter(fn (SupplierSource $source) => (bool) ($source->is_temp_profile ?? false))->values(),
            'archived' => $allSources->filter(fn (SupplierSource $source) => (bool) ($source->is_archived ?? false))->values(),
            'inactive' => $allSources->filter(fn (SupplierSource $source) => $source->status === 'inactive' && !(bool) ($source->is_archived ?? false) && !(bool) ($source->is_temp_profile ?? false))->values(),
            'all' => $allSources,
            default => $allSources->filter(function (SupplierSource $source) {
                return $source->status === 'active'
                    && !(bool) ($source->is_temp_profile ?? false)
                    && !(bool) ($source->is_archived ?? false);
            })->values(),
        };

        $stats = [
            'total' => $allSources->count(),
            'active' => $allSources->where('status', 'active')->count(),
            'inactive' => $allSources->where('status', 'inactive')->count(),
            'error' => $allSources->where('status', 'error')->count(),
            'ready' => $allSources->where('is_ready', true)->count(),
            'temp' => $allSources->where('is_temp_profile', true)->count(),
            'url_missing' => $allSources->where('has_location', false)->count(),
            'mapping_missing' => $allSources->where('has_field_mappings', false)->count(),
            'visible_total' => $sources->count(),
        ];

        $suppliers = $this->buildSupplierSummaries($sources, $request);

        return view('super-admin.product-data-hub.sources.index', compact('suppliers', 'stats', 'showTemp', 'activeFilter'));
    }

    public function showSupplier(Request $request, Supplier $supplier): View
    {
        $allSources = $this->buildHydratedSourceFlowCollection()
            ->filter(fn (SupplierSource $source) => (int) $source->supplier_id === (int) $supplier->id)
            ->values();

        abort_if($allSources->isEmpty(), 404);

        $stats = [
            'total_sources' => $allSources->count(),
            'active_sources' => $allSources->where('status', 'active')->count(),
            'review_total' => $allSources->sum(fn (SupplierSource $source) => (int) data_get($source, 'review_summary.review_total', 0)),
            'tenant_catalog_products' => $allSources->sum(fn (SupplierSource $source) => (int) data_get($source, 'dependency_summary.tenant_catalog_products', 0)),
            'tenant_catalog_variants' => $allSources->sum(fn (SupplierSource $source) => (int) data_get($source, 'dependency_summary.tenant_catalog_variants', 0)),
            'projection_pending' => $allSources->where('projection_pending', true)->count(),
        ];

        return view('super-admin.product-data-hub.sources.supplier-show', [
            'supplier' => $supplier,
            'sources' => $allSources,
            'stats' => $stats,
            'panelBackRoute' => route('admin.super.product-data-hub.sources.index', $request->only('filter')),
        ]);
    }

    private function buildHydratedSourceFlowCollection(): Collection
    {
        $sourceProfiles = $this->sourceProfiles();
        $lastCategoryScans = SupplierCategoryMapping::query()
            ->selectRaw('supplier_source_id, MAX(last_scanned_at) as last_scanned_at')
            ->groupBy('supplier_source_id')
            ->pluck('last_scanned_at', 'supplier_source_id');

        $sources = SupplierSource::with(['supplier', 'fieldMappings'])
            ->withCount(['fieldMappings', 'categoryMappings', 'rawProducts'])
            ->orderBy('status')
            ->orderBy('source_name')
            ->get();

        $sourceIds = $sources->pluck('id');
        $syncRunsBySource = ProductDataHubSyncRun::query()
            ->whereIn('supplier_source_id', $sourceIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('supplier_source_id');
        $latestSyncRuns = $syncRunsBySource->map(fn (Collection $runs) => $runs->first());
        $latestPreviewLogs = FeedSyncLog::query()
            ->whereIn('supplier_source_id', $sourceIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('supplier_source_id')
            ->map(fn (Collection $logs) => $this->resolveLatestPreviewLog($logs));
        $rawVariantCounts = SupplierProductVariantRaw::query()
            ->selectRaw('supplier_source_id, COUNT(*) as aggregate_count')
            ->whereIn('supplier_source_id', $sourceIds)
            ->groupBy('supplier_source_id')
            ->pluck('aggregate_count', 'supplier_source_id');
        $warningProductCounts = SupplierProductRaw::query()
            ->selectRaw('supplier_source_id, COUNT(*) as aggregate_count')
            ->whereIn('supplier_source_id', $sourceIds)
            ->where('warning_flag', true)
            ->groupBy('supplier_source_id')
            ->pluck('aggregate_count', 'supplier_source_id');
        $categoryPendingCounts = SupplierCategoryMapping::query()
            ->whereIn('supplier_source_id', $sourceIds)
            ->get(['supplier_source_id', 'standard_category_id', 'mapping_status'])
            ->groupBy('supplier_source_id')
            ->map(fn (Collection $rows) => $rows->filter(fn (SupplierCategoryMapping $mapping) => $this->mappingNeedsAttention($mapping))->count());
        $standardVariantCounts = DB::table('standard_product_variants as spv')
            ->join('standard_products as sp', 'sp.id', '=', 'spv.standard_product_id')
            ->join('supplier_products_raw as spr', 'spr.standard_product_id', '=', 'sp.id')
            ->whereIn('spr.supplier_source_id', $sourceIds)
            ->selectRaw('spr.supplier_source_id, COUNT(DISTINCT spv.id) as aggregate_count')
            ->groupBy('spr.supplier_source_id')
            ->pluck('aggregate_count', 'spr.supplier_source_id');
        $reviewSummaries = ProductDataHubSyncChange::query()
            ->whereIn('supplier_source_id', $sourceIds)
            ->openReview()
            ->get(['supplier_source_id', 'change_type', 'review_status', 'is_passive_candidate'])
            ->groupBy('supplier_source_id')
            ->map(function (Collection $rows) {
                return [
                    'new_product' => $rows->where('change_type', 'new_product')->count(),
                    'new_variant' => $rows->where('change_type', 'new_variant')->count(),
                    'missing_product' => $rows->where('change_type', 'missing_product')->count(),
                    'missing_variant' => $rows->where('change_type', 'missing_variant')->count(),
                    'passive_candidate' => $rows->filter(fn (ProductDataHubSyncChange $change) => (bool) $change->is_passive_candidate)->count(),
                    'review_total' => $rows->count(),
                ];
            });

        return $sources->map(function (SupplierSource $source) use (
            $sourceProfiles,
            $lastCategoryScans,
            $latestSyncRuns,
            $latestPreviewLogs,
            $rawVariantCounts,
            $warningProductCounts,
            $categoryPendingCounts,
            $standardVariantCounts,
            $reviewSummaries,
            $syncRunsBySource
        ) {
            $profileTemplateKey = $this->resolveSourceProfileTemplateKey($source->config ?? [], $source->supplier) ?? 'CUSTOM';
            $profileKey = $this->resolveProfileKey($source->supplier, $source->config ?? []);
            $isTempProfile = $this->isTempProfile($source->supplier, $profileTemplateKey, $sourceProfiles);
            $hasProfileTemplate = array_key_exists($profileTemplateKey, $sourceProfiles);
            $hasLocation = filled($source->url) || filled($source->config['source_file_path'] ?? null);
            $requiredSummary = $this->fieldDictionary->buildRequiredMappingSummary(
                $source->fieldMappings
                    ->keyBy('source_field')
                    ->map(fn (SupplierFieldMapping $mapping) => ['standard_field_key' => $mapping->target_field])
                    ->all()
            );
            $hasFieldMappings = $requiredSummary['count'] === 0 && (int) ($source->field_mappings_count ?? 0) > 0;
            $lastCategoryScanAt = $lastCategoryScans[$source->id] ?? null;
            $latestSync = $latestSyncRuns[$source->id] ?? null;
            $sourceRuns = $syncRunsBySource[$source->id] ?? collect();
            $latestPreview = $latestPreviewLogs[$source->id] ?? null;
            $lifecycleState = (string) ($source->config['lifecycle_state'] ?? '');
            $dependencySummary = $this->sourceDependencySummary($source);
            $rawVariantCount = (int) ($rawVariantCounts[$source->id] ?? 0);
            $standardVariantCount = (int) ($standardVariantCounts[$source->id] ?? 0);
            $categoryPendingCount = (int) ($categoryPendingCounts[$source->id] ?? 0);
            $warningProductCount = (int) ($warningProductCounts[$source->id] ?? 0);

            $source->setAttribute('display_source_type', $this->displaySourceType($source));
            $source->setAttribute('profile_key', $profileKey);
            $source->setAttribute('source_profile_template', $profileTemplateKey);
            $source->setAttribute('profile_prefix', $source->config['supplier_prefix']
                ?? config("prodelya_product_data_hub.supplier_profiles.{$profileTemplateKey}.supplier_code_prefix")
                ?? '-');
            $source->setAttribute('display_location', $source->url ?: ($source->config['source_file_path'] ?? '-'));
            $source->setAttribute('display_path', $source->config['product_node_path'] ?? $source->config['items_path'] ?? '-');
            $source->setAttribute('last_test_display', FeedSyncLog::query()->where('supplier_source_id', $source->id)->latest('completed_at')->value('completed_at'));
            $source->setAttribute('last_preview_display', $latestPreview?->created_at);
            $source->setAttribute('last_category_scan_display', $lastCategoryScanAt);
            $source->setAttribute('is_temp_profile', $isTempProfile);
            $source->setAttribute('has_profile_template', $hasProfileTemplate);
            $source->setAttribute('has_location', $hasLocation);
            $source->setAttribute('has_field_mappings', $hasFieldMappings);
            $source->setAttribute('has_category_mappings', (int) ($source->category_mappings_count ?? 0) > 0);
            $source->setAttribute('missing_required_mapping_count', $requiredSummary['count']);
            $source->setAttribute('missing_required_mapping_labels', array_column($requiredSummary['missing'], 'label'));
            $source->setAttribute('is_ready', !$isTempProfile && $hasLocation && $hasFieldMappings);
            $source->setAttribute('latest_sync_run', $latestSync);
            $source->setAttribute('latest_preview_log', $latestPreview);
            $source->setAttribute('sync_frequency', $this->resolveSyncFrequency($source));
            $source->setAttribute('sync_frequency_label', $this->resolveSyncFrequencyLabel($source));
            $source->setAttribute('next_sync_at', $this->resolveNextPlannedSync($source));
            $source->setAttribute('next_sync_label', $this->resolveNextPlannedSyncLabel($source));
            $source->setAttribute('auto_build_enabled', $this->resolveAutoBuildEnabled($source));
            $source->setAttribute('auto_project_enabled', $this->resolveAutoProjectEnabled($source));
            $source->setAttribute('sync_status_label', $this->resolveSyncStatusLabel($latestSync));
            $source->setAttribute('sync_status_badge', $this->resolveSyncStatusBadge($latestSync));
            $source->setAttribute('sync_summary', $this->resolveSyncSummary($latestSync));
            $source->setAttribute('freshness_summary', $this->buildFreshnessSummary($sourceRuns));
            $source->setAttribute('sync_decision_summary', $this->productHubSyncDecisionService->summarize($latestSync, null));
            $source->setAttribute('lifecycle_state', $lifecycleState);
            $source->setAttribute('is_archived', $lifecycleState === 'archived');
            $source->setAttribute('dependency_summary', $dependencySummary);
            $source->setAttribute('can_hard_delete', $dependencySummary['total'] === 0);
            $buildPending = (int) ($dependencySummary['raw_products'] ?? 0) > 0 && (int) ($dependencySummary['standard_products'] ?? 0) === 0;
            $projectionPending = (int) ($dependencySummary['standard_products'] ?? 0) > 0 && (int) ($dependencySummary['tenant_catalog_products'] ?? 0) === 0;
            $source->setAttribute('build_pending', $buildPending);
            $source->setAttribute('projection_pending', $projectionPending);
            $source->setAttribute('raw_variant_count', $rawVariantCount);
            $source->setAttribute('standard_variant_count', $standardVariantCount);
            $source->setAttribute('category_pending_count', $categoryPendingCount);
            $source->setAttribute('warning_product_count', $warningProductCount);
            $source->setAttribute('review_summary', $reviewSummaries[$source->id] ?? ['new_product' => 0, 'new_variant' => 0, 'missing_product' => 0, 'missing_variant' => 0, 'passive_candidate' => 0, 'review_total' => 0]);
            $source->setAttribute('quality_alerts', array_values(array_filter([
                $buildPending ? sprintf('%d aktarım kaydı var; ürün havuzuna alma bekliyor.', (int) ($dependencySummary['raw_products'] ?? 0)) : null,
                $projectionPending ? sprintf('%d standart ürün hazır; Abone Firma kataloğuna yansıtma bekliyor.', (int) ($dependencySummary['standard_products'] ?? 0)) : null,
            ])));
            $source->setAttribute('status_label', $lifecycleState === 'archived' ? 'Arşiv' : ($source->status === 'active' ? 'Aktif' : ($source->status === 'inactive' ? 'Pasif' : 'Hatalı')));
            $source->setAttribute('status_badge', $lifecycleState === 'archived' ? 'amber' : ($source->status === 'active' ? 'green' : ($source->status === 'inactive' ? 'gray' : 'red')));
            $source->setAttribute('flow_snapshot', $this->buildFlowSnapshot($source, $latestPreview, $latestSync, $dependencySummary, $requiredSummary, $categoryPendingCount, $warningProductCount, $rawVariantCount, $standardVariantCount, (array) ($source->review_summary ?? [])));
            $source->setAttribute('sort_key', sprintf('%d-%s-%s', $isTempProfile ? 1 : 0, Str::lower($source->supplier->name ?? ''), Str::lower($source->source_name)));

            return $source;
        })->sortBy('sort_key')->values();
    }

    private function buildSupplierSummaries(Collection $sources, Request $request): Collection
    {
        return $sources
            ->groupBy('supplier_id')
            ->map(function (Collection $group) use ($request) {
                /** @var SupplierSource $primary */
                $primary = $group->sortBy('sort_key')->first();
                $reviewTotal = $group->sum(fn (SupplierSource $source) => (int) data_get($source, 'review_summary.review_total', 0));
                $priceStockDelta = $group->sum(fn (SupplierSource $source) => (int) data_get($source, 'freshness_summary.price_changed_total', 0) + (int) data_get($source, 'freshness_summary.stock_changed_total', 0));
                $projectionPending = $group->sum(fn (SupplierSource $source) => (int) data_get($source, 'sync_decision_summary.projection_pending', 0));
                $tenantImpact = $group->sum(fn (SupplierSource $source) => (int) data_get($source, 'dependency_summary.tenant_catalog_products', 0));
                $quoteImpact = $group->sum(fn (SupplierSource $source) => (int) data_get($source, 'dependency_summary.quote_visible_products', 0) + (int) data_get($source, 'dependency_summary.quote_visible_variants', 0));
                $latestSync = $group->pluck('latest_sync_run')->filter()->sortByDesc('id')->first();

                return [
                    'supplier' => $primary->supplier,
                    'source_count' => $group->count(),
                    'active_source_count' => $group->where('status', 'active')->count(),
                    'review_total' => $reviewTotal,
                    'price_stock_delta_total' => $priceStockDelta,
                    'projection_pending' => $projectionPending,
                    'tenant_catalog_products' => $tenantImpact,
                    'quote_visible_total' => $quoteImpact,
                    'last_sync_at' => $latestSync?->finished_at,
                    'last_sync_status' => $latestSync?->normalizedStatus() ?? 'missing',
                    'has_tenant_impact' => $tenantImpact > 0 || $quoteImpact > 0,
                    'detail_href' => route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $primary->supplier_id] + $request->only('filter')),
                ];
            })
            ->sortBy(fn (array $row) => Str::lower($row['supplier']->name))
            ->values();
    }

    private function resolveLatestPreviewLog(Collection $logs): ?FeedSyncLog
    {
        return $logs->first(function (FeedSyncLog $log) {
            $metadata = $this->decodeSyncMetadata($log);

            return filled($metadata['preview_mode'] ?? null)
                || str_contains(Str::lower((string) ($log->error_summary ?? '')), 'preview');
        }) ?: $logs->first();
    }

    private function decodeSyncMetadata(FeedSyncLog $log): array
    {
        $metadata = $log->getAttribute('sync_metadata');

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function mappingNeedsAttention(SupplierCategoryMapping $mapping): bool
    {
        return blank($mapping->standard_category_id)
            || in_array((string) $mapping->mapping_status, ['pending', 'needs_review', 'conflict', 'target_missing', 'review_required', 'low_confidence'], true);
    }

    private function buildFlowSnapshot(
        SupplierSource $source,
        ?FeedSyncLog $latestPreview,
        ?ProductDataHubSyncRun $latestSync,
        array $dependencySummary,
        array $requiredSummary,
        int $categoryPendingCount,
        int $warningProductCount,
        int $rawVariantCount,
        int $standardVariantCount,
        array $reviewSummary = []
    ): array {
        $previewMeta = $latestPreview ? $this->decodeSyncMetadata($latestPreview) : [];
        $previewMode = (string) ($previewMeta['preview_mode'] ?? '');
        $hasLocation = (bool) ($source->has_location ?? false);
        $missingRequiredCount = (int) ($requiredSummary['count'] ?? 0);
        $rawProductCount = (int) ($dependencySummary['raw_products'] ?? 0);
        $standardProductCount = (int) ($dependencySummary['standard_products'] ?? 0);
        $tenantCatalogProductCount = (int) ($dependencySummary['tenant_catalog_products'] ?? 0);
        $tenantCatalogVariantCount = (int) ($dependencySummary['tenant_catalog_variants'] ?? 0);
        $tenantAccessCount = (int) ($dependencySummary['tenant_access'] ?? 0);
        $quoteVisibleProductCount = (int) ($dependencySummary['quote_visible_products'] ?? 0);
        $quoteVisibleVariantCount = (int) ($dependencySummary['quote_visible_variants'] ?? 0);
        $syncErrors = (int) data_get($source->sync_summary, 'errors', 0);
        $reviewTotal = (int) ($reviewSummary['review_total'] ?? 0);

        $latestSyncStatus = $latestSync?->normalizedStatus();

        $previewStep = match (true) {
            !$latestPreview => $this->flowStep('preview', 'Önizleme', 'İlk kayıtları canlı kaynaktan okuyarak yapı ve örnek değerleri kontrol eder.', 'missing', 'Eksik', 'Henüz preview alınmadı.'),
            $previewMode === 'success' => $this->flowStep('preview', 'Önizleme', 'İlk kayıtları canlı kaynaktan okuyarak yapı ve örnek değerleri kontrol eder.', 'ready', 'Hazır', 'Son preview canlı kaynaktan başarılı alındı.'),
            $previewMode === 'fallback' => $this->flowStep('preview', 'Önizleme', 'İlk kayıtları canlı kaynaktan okuyarak yapı ve örnek değerleri kontrol eder.', 'warning', 'Uyarı', 'Demo fallback gösteriliyor; canlı kaynak gibi değerlendirilmemeli.'),
            default => $this->flowStep('preview', 'Önizleme', 'İlk kayıtları canlı kaynaktan okuyarak yapı ve örnek değerleri kontrol eder.', 'error', 'Hata', 'Son preview denemesi sorunlu görünüyor.'),
        };

        $fieldMappingStep = match (true) {
            $missingRequiredCount === 0 && (int) ($source->field_mappings_count ?? 0) > 0 => $this->flowStep('field_mapping', 'Alan Eşleme', 'Tedarikçi alanlarını Prodelya standart alanlarına bağlar.', 'ready', 'Hazır', 'Zorunlu alanlar tamam.'),
            (int) ($source->field_mappings_count ?? 0) > 0 => $this->flowStep('field_mapping', 'Alan Eşleme', 'Tedarikçi alanlarını Prodelya standart alanlarına bağlar.', 'warning', 'Uyarı', $missingRequiredCount . ' zorunlu alan eksik.'),
            default => $this->flowStep('field_mapping', 'Alan Eşleme', 'Tedarikçi alanlarını Prodelya standart alanlarına bağlar.', 'missing', 'Eksik', 'Alan eşleme henüz kaydedilmedi.'),
        };

        $categoryStep = match (true) {
            (int) ($source->category_mappings_count ?? 0) === 0 => $this->flowStep('category', 'Kategori', 'Tedarikçi kategorilerini Prodelya standart kategori ağacına eşler.', 'missing', 'Eksik', 'Kategori eşleme henüz başlamadı.'),
            $categoryPendingCount > 0 => $this->flowStep('category', 'Kategori', 'Tedarikçi kategorilerini Prodelya standart kategori ağacına eşler.', 'warning', 'Uyarı', $categoryPendingCount . ' kategori eşleşmemiş kaydı var.'),
            default => $this->flowStep('category', 'Kategori', 'Tedarikçi kategorilerini Prodelya standart kategori ağacına eşler.', 'ready', 'Hazır', 'Kategori eşleme kuyruğu temiz görünüyor.'),
        };

        $qualityStep = match (true) {
            !$hasLocation || $missingRequiredCount > 0 => $this->flowStep('quality', 'Kalite Kontrol', 'Görsel, fiyat, stok, varyant ve uyarıları kontrol eder.', 'error', 'Hata', 'Kaynak veya zorunlu alanlar eksik olduğu için kalite kapısı bloklu.'),
            $warningProductCount > 0 || $categoryPendingCount > 0 || $syncErrors > 0 => $this->flowStep('quality', 'Kalite Kontrol', 'Görsel, fiyat, stok, varyant ve uyarıları kontrol eder.', 'warning', 'Uyarı', trim($warningProductCount . ' uyarılı ürün, ' . $categoryPendingCount . ' kategori eşleşmemiş kaydı, ' . $syncErrors . ' son işlem hatası.')),
            $rawProductCount > 0 || $standardProductCount > 0 => $this->flowStep('quality', 'Kalite Kontrol', 'Görsel, fiyat, stok, varyant ve uyarıları kontrol eder.', 'ready', 'Hazır', 'Kritik kalite uyarısı görünmüyor.'),
            default => $this->flowStep('quality', 'Kalite Kontrol', 'Görsel, fiyat, stok, varyant ve uyarıları kontrol eder.', 'missing', 'Eksik', 'Kalite kontrolü için henüz ürün akışı yok.'),
        };

        $standardPoolStep = match (true) {
            $standardProductCount > 0 => $this->flowStep('standard_pool', 'Ürün Havuzu', 'Temizlenmiş merkezi ürün/varyant havuzuna aktarım durumudur.', (($source->build_pending ?? false) || $latestSyncStatus === ProductDataHubSyncRun::STATUS_FAILED) ? 'warning' : 'ready', (($source->build_pending ?? false) || $latestSyncStatus === ProductDataHubSyncRun::STATUS_FAILED) ? 'Uyarı' : 'Hazır', $standardProductCount . ' ürün / ' . $standardVariantCount . ' varyant hazır.'),
            $rawProductCount > 0 => $this->flowStep('standard_pool', 'Ürün Havuzu', 'Temizlenmiş merkezi ürün/varyant havuzuna aktarım durumudur.', 'missing', 'Eksik', $rawProductCount . ' hazırlık kaydı var ama ürün havuzu henüz boş.'),
            default => $this->flowStep('standard_pool', 'Ürün Havuzu', 'Temizlenmiş merkezi ürün/varyant havuzuna aktarım durumudur.', 'missing', 'Eksik', 'Ürün havuzu için önce hazırlık kaydı oluşmalı.'),
        };

        $catalogStep = match (true) {
            $tenantAccessCount > 0 && $tenantCatalogProductCount > 0 => $this->flowStep('catalog_projection', 'Abone Katalog Yayını', 'Ürünlerin Abone Firma kataloglarına yansıtılma durumudur.', ($source->projection_pending ?? false) ? 'warning' : 'ready', ($source->projection_pending ?? false) ? 'Uyarı' : 'Hazır', $tenantCatalogProductCount . ' katalog ürünü yansımış.'),
            $standardProductCount > 0 && $tenantAccessCount > 0 => $this->flowStep('catalog_projection', 'Abone Katalog Yayını', 'Ürünlerin Abone Firma kataloglarına yansıtılma durumudur.', 'warning', 'Uyarı', 'Erişim var ama projection bekliyor.'),
            $standardProductCount > 0 => $this->flowStep('catalog_projection', 'Abone Katalog Yayını', 'Ürünlerin Abone Firma kataloglarına yansıtılma durumudur.', 'missing', 'Eksik', 'Önce Abone Firma tedarikçi erişimi tanımlanmalı.'),
            default => $this->flowStep('catalog_projection', 'Abone Katalog Yayını', 'Ürünlerin Abone Firma kataloglarına yansıtılma durumudur.', 'missing', 'Eksik', 'Projection için standart havuz hazır değil.'),
        };

        $reportStep = match (true) {
            $latestSyncStatus === ProductDataHubSyncRun::STATUS_RUNNING => $this->flowStep('report', 'Rapor', 'Son preview, sync, hata ve uyarı raporlarıdır.', 'error', 'Hata', 'Son sync çalışıyor; stuck kontrolü gerekebilir.'),
            $latestSyncStatus === ProductDataHubSyncRun::STATUS_STUCK => $this->flowStep('report', 'Rapor', 'Son preview, sync, hata ve uyarı raporlarıdır.', 'error', 'Hata', 'Son sync stuck olarak işaretlendi; recovery incelemesi önerilir.'),
            $latestSyncStatus === ProductDataHubSyncRun::STATUS_FAILED => $this->flowStep('report', 'Rapor', 'Son preview, sync, hata ve uyarı raporlarıdır.', 'error', 'Hata', 'Son sync hatalı tamamlandı.'),
            $latestPreview || $latestSync => $this->flowStep('report', 'Rapor', 'Son preview, sync, hata ve uyarı raporlarıdır.', 'ready', 'Hazır', 'Preview veya sync raporu mevcut.'),
            default => $this->flowStep('report', 'Rapor', 'Son preview, sync, hata ve uyarı raporlarıdır.', 'missing', 'Eksik', 'Henüz rapor oluşmadı.'),
        };

        $steps = [
            $this->flowStep('source', 'Kaynak', 'XML, JSON, API veya CSV bağlantısı.', $hasLocation ? 'ready' : 'missing', $hasLocation ? 'Hazır' : 'Eksik', $hasLocation ? 'URL veya dosya yolu tanımlı.' : 'URL veya dosya yolu eksik.'),
            $previewStep,
            $fieldMappingStep,
            $categoryStep,
            $qualityStep,
            $standardPoolStep,
            $catalogStep,
            $reportStep,
        ];

        $primaryAction = match (true) {
            $reviewTotal > 0 => $this->flowAction('İnceleme Bekleyenleri Aç', route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id, 'review_only' => 1]), 'warning'),
            !$hasLocation => $this->flowAction('Kaynak Bilgilerini Tamamla', route('admin.super.product-data-hub.sources.edit', $source), 'light'),
            $previewStep['status'] === 'missing' => $this->flowAction('Önizle', route('admin.super.product-data-hub.sources.preview', $source), 'primary'),
            $previewStep['status'] === 'error' => $this->flowAction('Önizleme Hatasını Aç', route('admin.super.product-data-hub.sources.preview', $source), 'primary'),
            $previewStep['status'] === 'warning' => $this->flowAction('Önizlemeyi Aç', route('admin.super.product-data-hub.sources.preview', $source), 'primary'),
            $fieldMappingStep['status'] !== 'ready' => $this->flowAction('Alan Eşlemeyi Aç', route('admin.super.product-data-hub.field-mappings.source', $source), 'warning'),
            $categoryStep['status'] !== 'ready' => $this->flowAction('Kategori Eşlemeyi Aç', route('admin.super.product-data-hub.category-mappings.index', ['supplier_id' => $source->supplier_id]), 'warning'),
            $standardPoolStep['status'] === 'missing' => $this->flowAction('Ürün Havuzu Durumunu Aç', route('admin.super.product-data-hub.raw-products.index'), 'light'),
            $catalogStep['status'] !== 'ready' => $this->flowAction('Abone Katalog Yayınını Aç', route('admin.super.product-data-hub.catalog-output'), 'light'),
            $source->status === 'active' => $this->flowAction('Fiyat/Stok Değişimlerini Tara', route('admin.super.product-data-hub.sources.delta-dry-run', $source), 'primary', 'post'),
            default => $this->flowAction('Son Raporu Aç', route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]), 'light'),
        };

        return [
            'steps' => $steps,
            'primary_action' => $primaryAction,
            'secondary_actions' => [
                $this->flowAction('Kaynağı Düzenle', route('admin.super.product-data-hub.sources.edit', $source), 'light'),
                $this->flowAction('Önizlemeyi Aç', route('admin.super.product-data-hub.sources.preview', $source), 'light'),
                $this->flowAction('Alan Eşlemeyi Aç', route('admin.super.product-data-hub.field-mappings.source', $source), 'light'),
                $this->flowAction('Kategori Eşlemeyi Aç', route('admin.super.product-data-hub.category-mappings.index', ['supplier_id' => $source->supplier_id]), 'light'),
                $this->flowAction('İnceleme Bekleyenleri Aç', route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id, 'review_only' => 1]), 'light'),
                $this->flowAction('Son Raporu Aç', route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]), 'light'),
            ],
            'summary' => [
                'preview_status' => $previewStep['status'],
                'preview_label' => $previewStep['status_label'],
                'preview_note' => $reviewTotal > 0 ? 'İnceleme bekleyen değişiklikler var.' : $previewStep['note'],
                'raw_products' => $rawProductCount,
                'raw_variants' => $rawVariantCount,
                'standard_products' => $standardProductCount,
                'standard_variants' => $standardVariantCount,
                'category_pending' => $categoryPendingCount,
                'warning_products' => $warningProductCount,
                'tenant_catalog_products' => $tenantCatalogProductCount,
                'tenant_catalog_variants' => $tenantCatalogVariantCount,
                'quote_visible_products' => $quoteVisibleProductCount,
                'quote_visible_variants' => $quoteVisibleVariantCount,
                'quote_hidden_products' => max(0, $tenantCatalogProductCount - $quoteVisibleProductCount),
                'quote_hidden_variants' => max(0, $tenantCatalogVariantCount - $quoteVisibleVariantCount),
                'quote_visibility_reason' => match (true) {
                    $tenantAccessCount === 0 => 'Teklif kullanımı kapalı / erişim yok',
                    $tenantCatalogProductCount === 0 && $standardProductCount > 0 => 'Kataloğa yansıtma bekliyor',
                    $missingRequiredCount > 0 => 'Alan eşleme eksik',
                    $categoryPendingCount > 0 => 'Kategori eşleşmesi eksik',
                    $quoteVisibleProductCount === 0 && $quoteVisibleVariantCount === 0 && ($tenantCatalogProductCount > 0 || $tenantCatalogVariantCount > 0) => 'Teklif görünürlüğü kapalı olabilir',
                    default => 'Görünürlük zinciri açık',
                },
                'tenant_access' => $tenantAccessCount,
                'review_total' => $reviewTotal,
                'new_product_review_count' => (int) ($reviewSummary['new_product'] ?? 0),
                'new_variant_review_count' => (int) ($reviewSummary['new_variant'] ?? 0),
                'missing_product_review_count' => (int) ($reviewSummary['missing_product'] ?? 0),
                'missing_variant_review_count' => (int) ($reviewSummary['missing_variant'] ?? 0),
                'passive_candidate_review_count' => (int) ($reviewSummary['passive_candidate'] ?? 0),
            ],
        ];
    }

    private function flowStep(string $key, string $title, string $description, string $status, string $statusLabel, string $note): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'status_label' => $statusLabel,
            'note' => $note,
        ];
    }

    private function flowAction(string $label, string $href, string $tone, string $method = 'get'): array
    {
        return [
            'label' => $label,
            'href' => $href,
            'tone' => $tone,
            'method' => $method,
        ];
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $sourceProfiles = $this->sourceProfiles();
        $profileTemplates = $this->sourceProfileTemplates($sourceProfiles);
        $templateSource = SupplierSource::query()
            ->selectable()
            ->with('supplier')
            ->find($request->integer('template_source_id'));

        $copyableSources = SupplierSource::query()
            ->selectable()
            ->with('supplier')
            ->orderBy('source_name')
            ->get();

        $formDefaults = $this->sourceFormDefaults($templateSource);

        return view('super-admin.product-data-hub.sources.create', compact(
            'suppliers',
            'sourceProfiles',
            'profileTemplates',
            'copyableSources',
            'templateSource',
            'formDefaults'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSource($request, false);
        $supplier = $this->resolveSourceSupplier($validated);
        $sourceProfileTemplate = $this->resolveSourceProfileTemplateKey($validated, $supplier);
        $profileKey = $this->resolveProfileKey($supplier, $validated);
        $profileConfig = config("prodelya_product_data_hub.supplier_profiles.{$sourceProfileTemplate}", []);
        $storedSourceType = $this->normalizeStoredSourceType($validated['source_type']);
        $format = $this->resolveFormat($validated['source_type'], $validated['format'] ?? null);

        $config = $this->buildConfig($validated, $sourceProfileTemplate, $profileKey, $profileConfig, $format);

        $source = SupplierSource::create([
            'supplier_id' => $validated['supplier_id'],
            'source_name' => $validated['source_name'],
            'source_type' => $storedSourceType,
            'url' => $validated['url'] ?? null,
            'config' => $config,
            'status' => $validated['status'],
        ]);

        $this->copyTemplateMappingsIfNeeded($validated, $source);

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with('success', 'Global tedarikçi kaynağı başarıyla oluşturuldu.');
    }

    public function edit(SupplierSource $source): View
    {
        $suppliers = Supplier::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $sourceProfiles = $this->sourceProfiles();
        $profileTemplates = $this->sourceProfileTemplates($sourceProfiles);
        $selectedSourceType = $this->displaySourceType($source);
        $source->setAttribute('masked_request_headers_display', $this->formatRequestHeadersForDisplay($source->config['request_headers'] ?? null));

        return view('super-admin.product-data-hub.sources.edit', compact('source', 'suppliers', 'sourceProfiles', 'profileTemplates', 'selectedSourceType'));
    }

    public function update(Request $request, SupplierSource $source): RedirectResponse
    {
        $validated = $this->validateSource($request, true);
        $supplier = Supplier::findOrFail($validated['supplier_id']);
        $sourceProfileTemplate = $this->resolveSourceProfileTemplateKey($validated, $supplier);
        $profileKey = $this->resolveProfileKey($supplier, $validated);
        $profileConfig = config("prodelya_product_data_hub.supplier_profiles.{$sourceProfileTemplate}", []);
        $storedSourceType = $this->normalizeStoredSourceType($validated['source_type']);
        $format = $this->resolveFormat($validated['source_type'], $validated['format'] ?? null);

        $config = $this->buildConfig($validated, $sourceProfileTemplate, $profileKey, $profileConfig, $format, $source->config ?? []);

        $source->update([
            'supplier_id' => $validated['supplier_id'],
            'source_name' => $validated['source_name'],
            'source_type' => $storedSourceType,
            'url' => $validated['url'],
            'config' => $config,
            'status' => $validated['status'],
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with('success', 'Global tedarikçi kaynağı başarıyla güncellendi.');
    }

    public function destroy(SupplierSource $source): RedirectResponse
    {
        $dependencySummary = $this->sourceDependencySummary($source);

        if ($dependencySummary['total'] > 0) {
            return redirect()
                ->route('admin.super.product-data-hub.sources.index')
                ->with('error', 'Bu kaynak bağlı kayıtlar içeriyor. Veri kaybı yaşamamak için silmek yerine pasifleştirin.');
        }

        $supplier = $source->supplier;
        $sourceName = $source->source_name;
        $source->delete();

        if ($supplier
            && Str::startsWith((string) $supplier->code, 'TMP-')
            && SupplierSource::query()->where('supplier_id', $supplier->id)->doesntExist()
            && TenantSupplierAccess::query()->where('supplier_id', $supplier->id)->doesntExist()
        ) {
            $supplier->delete();
        }

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with('success', $sourceName . ' kaynağı güvenli şekilde silindi.');
    }

    public function deactivate(SupplierSource $source): RedirectResponse
    {
        $config = (array) ($source->config ?? []);
        $config['lifecycle_state'] = 'inactive';
        $config['deactivated_at'] = now()->toDateTimeString();
        $config['deactivation_reason'] = 'super_admin_manual';

        $source->update([
            'status' => 'inactive',
            'config' => $config,
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with('success', 'Kaynak pasifleştirildi. İstenirse daha sonra yeniden aktifleştirilebilir.');
    }

    public function archive(SupplierSource $source): RedirectResponse
    {
        $config = (array) ($source->config ?? []);
        $config['lifecycle_state'] = 'archived';
        $config['temp_profile'] = true;
        $config['archived_at'] = now()->toDateTimeString();
        $config['archive_reason'] = $config['archive_reason'] ?? 'temporary_or_demo_source';

        $source->update([
            'status' => 'inactive',
            'config' => $config,
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with('success', 'Kaynak arşivlendi. Varsayılan listede gizlenecektir.');
    }

    public function preview(SupplierSource $source): View
    {
        $requestedLimit = strtolower(trim((string) request()->query('limit', '50')));
        $limit = match ($requestedLimit) {
            'all', 'tum', 'tümü' => 0,
            '50', '100', '250', '500' => (int) $requestedLimit,
            default => 50,
        };
        $activeFilter = strtolower(trim((string) request()->query('filter', 'all')));
        $fetchResult = $this->sourceFetch->fetch($source);
        $parserResult = [
            'ok' => false,
            'rows' => [],
            'profile_key' => $this->previewParser->getSupplierProfileKey($source),
            'content_type' => strtolower((string) ($source->config['format'] ?? $source->source_type)),
            'node_path' => $source->config['product_node_path']
                ?? config('prodelya_product_data_hub.supplier_profiles.' . $this->previewParser->getSupplierProfileKey($source) . '.product_node_path'),
            'records_read' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        if ($fetchResult['ok']) {
            $parserResult = $this->sourceParser->parse($source, (string) $fetchResult['content'], $limit);
        }

        $preview = $parserResult['ok']
            ? $this->previewParser->previewSource($source, $parserResult['rows'])
            : $this->previewParser->previewSource($source);

        $previewErrors = array_values(array_filter(array_merge(
            $fetchResult['errors'] ?? [],
            $parserResult['errors'] ?? []
        )));
        $previewWarnings = array_values(array_filter(array_merge(
            $fetchResult['warnings'] ?? [],
            $parserResult['warnings'] ?? []
        )));

        if (!in_array($requestedLimit, ['50', '100', '250', '500', 'all', 'tum', 'tümü'], true)) {
            $previewWarnings[] = 'Geçersiz limit seçildi. Varsayılan 50 kayıt gösterildi.';
        }

        if (($parserResult['profile_key'] ?? null) === 'AKDENIZ' && (int) ($fetchResult['status_code'] ?? 0) === 403) {
            $previewWarnings[] = 'Akdeniz kaynağı IP izinli olabilir. Canlı sistemde sabit sunucu IP’si Akdeniz’e bildirilmelidir. Local geliştirmede onaylı IP’den indirilen XML dosyasını Yerel Dosya Yolu alanına ekleyerek preview alabilirsiniz.';
        }

        $fetchErrorType = $fetchResult['error_type'] ?? 'none';

        $sourceMode = $preview['source_mode'];
        $mappingMode = $preview['mapping_source'];
        $mappingWarnings = $preview['mapping_warnings'];
        $standardizationNotes = $preview['profile_notes'];
        $allPreviewProducts = collect($preview['products'])->map(function (array $row) use ($mappingMode, $source) {
            $row['mapping_mode'] = $mappingMode;
            $row['mapping_badge'] = $mappingMode === 'db' ? 'DB mapping kullanıldı' : 'Öneri mapping kullanıldı';
            return $this->enrichPreviewRow($row, false, $source);
        });
        $allPreviewVariants = collect($preview['variants'])->map(function (array $row) use ($mappingMode, $source) {
            $row['mapping_mode'] = $mappingMode;
            $row['mapping_badge'] = $mappingMode === 'db' ? 'DB mapping kullanıldı' : 'Öneri mapping kullanıldı';
            return $this->enrichPreviewRow($row, true, $source);
        });
        $previewProducts = $this->applyPreviewFilter($allPreviewProducts, $activeFilter);
        $previewVariants = $this->applyPreviewFilter($allPreviewVariants, $activeFilter);
        $allPreviewRows = $allPreviewProducts->concat($allPreviewVariants);
        $stats = [
            'product_count' => $preview['stats']['product_count'],
            'variant_count' => $preview['stats']['variant_count'],
            'records_read' => $parserResult['records_read'] ?: $preview['stats']['records_read'],
            'matched_fields' => $preview['stats']['matched_fields'],
            'warnings' => $preview['stats']['warning_count'] + count($previewWarnings),
            'errors' => $preview['stats']['error_count'] + count($previewErrors),
            'mapping_source' => $preview['stats']['mapping_source'],
            'displayed_product_count' => $previewProducts->count(),
            'displayed_variant_count' => $previewVariants->count(),
            'displayed_row_count' => $previewProducts->count() + $previewVariants->count(),
            'warning_product_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['has_warning'] ?? false))->count(),
            'missing_image_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['missing_image'] ?? false))->count(),
            'missing_price_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['missing_price'] ?? false))->count(),
            'missing_category_count' => $allPreviewProducts->filter(fn (array $row) => (bool) ($row['missing_category'] ?? false))->count(),
            'missing_product_code_count' => $allPreviewProducts->filter(fn (array $row) => (bool) ($row['missing_product_code'] ?? false))->count(),
            'missing_variant_code_count' => $allPreviewVariants->filter(fn (array $row) => (bool) ($row['missing_variant_code'] ?? false))->count(),
            'parse_error_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['has_parse_error'] ?? false))->count() + count($previewErrors),
            'net_price_warning_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['net_price_warning'] ?? false))->count(),
            'supplier_warning_count' => $allPreviewRows->filter(fn (array $row) => (bool) ($row['supplier_warning_flag'] ?? false))->count(),
            'critical_issue_count' => $allPreviewRows->filter(fn (array $row) => (int) ($row['critical_issue_count'] ?? 0) > 0)->count(),
            'review_issue_count' => $allPreviewRows->filter(fn (array $row) => (int) ($row['review_issue_count'] ?? 0) > 0)->count(),
            'info_issue_count' => $allPreviewRows->filter(fn (array $row) => (int) ($row['info_issue_count'] ?? 0) > 0)->count(),
        ];
        $stageBlockedReasons = [];
        if ($sourceMode !== 'live_source') {
            $stageBlockedReasons[] = 'Staging’e Aktar yalnız gerçek kaynak preview ile kullanılabilir.';
        }
        if (!empty($mappingWarnings)) {
            $stageBlockedReasons[] = 'Zorunlu alanlar tamam değil: ' . implode(', ', $mappingWarnings);
        }
        $isCustomProfile = (($source->config['profile_key'] ?? null) === 'CUSTOM');
        if ($isCustomProfile && $allPreviewProducts->contains(fn (array $row) => (bool) ($row['temporary_product_code'] ?? false))) {
            $stageBlockedReasons[] = 'Ürün kodu XML’den gelmeyen kayıtlar için geçici kod üretildi. Önce alan eşlemesini tamamlayın.';
        }
        if ($isCustomProfile && $allPreviewVariants->contains(fn (array $row) => (bool) ($row['temporary_variant_code'] ?? false))) {
            $stageBlockedReasons[] = 'Varyant kodu eşlenmemiş veya boş. Satılabilir varyant ayrımı için Varyant Kodu alanını eşleyin.';
        }
        $canStagePreview = empty($stageBlockedReasons);
        $sourceSummary = [
            'supplier_name' => $source->supplier->name,
            'source_id' => $source->id,
            'profile_key' => $profileKey = ($parserResult['profile_key'] ?? $this->previewParser->getSupplierProfileKey($source)),
            'last_preview_at' => FeedSyncLog::query()->where('supplier_source_id', $source->id)->latest('created_at')->value('created_at'),
            'last_fetch_at' => FeedSyncLog::query()->where('supplier_source_id', $source->id)->latest('completed_at')->value('completed_at'),
        ];

        if ($previewProducts->isEmpty() && $sourceMode === 'live_source') {
            $previewWarnings[] = 'Seçilen limit veya filtre için ürün bulunamadı.';
        }

        $this->recordPreviewAttempt(
            $source,
            $sourceMode === 'live_source' ? 'success' : 'fallback',
            $stats['records_read'],
            $stats['warnings'],
            $stats['errors'],
            $sourceMode === 'live_source'
                ? 'Gerçek kaynak verisi üzerinden önizleme oluşturuldu.'
                : 'Gerçek kaynak okunamadı. Demo fallback verisi gösteriliyor.'
        );

        $availableLimits = [
            '50' => '50',
            '100' => '100',
            '250' => '250',
            '500' => '500',
            'all' => 'Tümü',
        ];
        $availableFilters = [
            'all' => 'Tümü',
            'warning' => 'Uyarılı ürünler',
            'missing-image' => 'Görseli eksik',
            'missing-price' => 'Fiyatı eksik',
            'net-price-warning' => 'Net fiyat uyarılı',
            'supplier-warning' => 'Kırmızı / Turuncu uyarılı',
            'parse-error' => 'Parse hatalı',
        ];

        return view('super-admin.product-data-hub.sources.preview', compact(
            'source',
            'previewProducts',
            'previewVariants',
            'stats',
            'standardizationNotes',
            'mappingMode',
            'mappingWarnings',
            'sourceMode',
            'previewWarnings',
            'previewErrors',
            'fetchErrorType',
            'parserResult',
            'activeFilter',
            'requestedLimit',
            'availableLimits',
            'availableFilters',
            'sourceSummary',
            'canStagePreview',
            'stageBlockedReasons'
        ));
    }

    public function stagePreview(Request $request, SupplierSource $source): RedirectResponse
    {
        $request->validate([
            'confirm_stage' => 'accepted',
        ], [
            'confirm_stage.accepted' => 'Ham ürün havuzuna aktarmadan önce onay kutusunu işaretleyin.',
        ]);

        $fetchResult = $this->sourceFetch->fetch($source);
        if (!$fetchResult['ok']) {
            return redirect()
                ->route('admin.super.product-data-hub.sources.preview', $source)
                ->with('error', 'Demo önizleme staging’e aktarılamaz. Önce gerçek kaynak verisi okunmalıdır.');
        }

        $parserResult = $this->sourceParser->parse($source, (string) $fetchResult['content']);
        if (!$parserResult['ok']) {
            return redirect()
                ->route('admin.super.product-data-hub.sources.preview', $source)
                ->with('error', 'Demo önizleme staging’e aktarılamaz. Önce gerçek kaynak verisi okunmalıdır.');
        }

        $preview = $this->previewParser->previewSource($source, $parserResult['rows']);
        if (($preview['source_mode'] ?? 'demo_fallback') !== 'live_source') {
            return redirect()
                ->route('admin.super.product-data-hub.sources.preview', $source)
                ->with('error', 'Demo önizleme staging’e aktarılamaz. Önce gerçek kaynak verisi okunmalıdır.');
        }
        if (!empty($preview['mapping_warnings'] ?? [])) {
            return redirect()
                ->route('admin.super.product-data-hub.sources.preview', $source)
                ->with('error', 'Zorunlu alan eşlemeleri tamamlanmadan Staging’e Aktar kullanılamaz.');
        }

        $previewRows = collect($preview['products'] ?? [])->concat($preview['variants'] ?? []);
        $hasTemporaryCode = (($source->config['profile_key'] ?? null) === 'CUSTOM') && $previewRows->contains(function (array $row) {
            return collect($row['warnings'] ?? [])->contains(fn (string $warning) => str_contains($warning, 'geçici kod üretildi'))
                || collect($row['warnings'] ?? [])->contains(fn (string $warning) => str_contains($warning, 'Varyant kodu eşlenmemiş'))
                || collect($row['errors'] ?? [])->contains(fn (string $warning) => str_contains($warning, 'Zorunlu alan eksik'));
        });

        if ($hasTemporaryCode) {
            return redirect()
                ->route('admin.super.product-data-hub.sources.preview', $source)
                ->with('error', 'Geçici kod üretilen veya zorunlu alanı eksik kayıtlar varken Staging’e Aktar kullanılamaz.');
        }

        $result = $this->rawProductStaging->stagePreview($source, $preview);

        return redirect()
            ->route('admin.super.product-data-hub.sources.preview', $source)
            ->with('success', "İşlem tamamlandı: Okunan kayıt {$result['records_read']}, aktarılan ürün {$result['products']}, aktarılan varyasyon {$result['variants']}, yeni kayıt " . (($result['created_products'] ?? 0) + ($result['created_variants'] ?? 0)) . ", güncellenen kayıt " . (($result['updated_products'] ?? 0) + ($result['updated_variants'] ?? 0)) . ", atlanan kayıt {$result['skipped_count']}, uyarılı kayıt {$result['warning_count']}, hatalı kayıt {$result['error_count']}.");
    }

    public function testConnection(SupplierSource $source): RedirectResponse
    {
        $result = $this->sourceFetch->testConnection($source);

        return redirect()
            ->route('admin.super.product-data-hub.sources.preview', $source)
            ->with(
                $result['ok'] ? 'success' : 'error',
                trim($result['message'] . ' HTTP: ' . ($result['status_code'] ?? '-') . ' | Süre: ' . ($result['duration_ms'] ?? 0) . ' ms')
            );
    }

    public function syncNow(Request $request, SupplierSource $source): RedirectResponse
    {
        $result = $this->sourceSync->syncSource($source, [
            'run_type' => 'manual',
            'dry_run' => $request->boolean('dry_run'),
            'no_build' => $request->boolean('no_build'),
            'no_project' => $request->boolean('no_project'),
        ]);
        $run = $result['run'];

        return redirect()
            ->route('admin.super.product-data-hub.sources.index')
            ->with(
                in_array($run->normalizedStatus(), [ProductDataHubSyncRun::STATUS_COMPLETED, ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS], true) ? 'success' : 'error',
                $run->normalizedStatus() === ProductDataHubSyncRun::STATUS_FAILED
                    ? 'Senkron başlatılamadı: ' . ($run->error_message ?: 'Kaynak verisi okunamadı.')
                    : (($request->boolean('dry_run')
                            ? 'Bu işlem test çalıştırmasıdır, ürün/stok/fiyat verisi değiştirilmedi. '
                            : 'İşlem tamamlandı: ')
                        . "Okunan {$run->records_read}, yeni {$run->products_created}, güncellenen {$run->products_updated}, değişmeyen {$run->products_unchanged}, XML’den çıkan {$run->products_missing_from_feed}, fiyat değişen {$run->price_changed_count}, stok değişen {$run->stock_changed_count}, görsel değişen {$run->image_changed_count}, hata {$run->error_count}.")
            );
    }

    public function deltaDryRun(SupplierSource $source): RedirectResponse
    {
        return $this->runDeltaAction($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ], 'Fiyat/stok değişim taraması');
    }

    public function applyPriceStock(SupplierSource $source): RedirectResponse
    {
        return $this->runDeltaAction($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'project_dirty' => true,
        ], 'Fiyat/stok güncelleme');
    }

    public function applyPriceStockAndProjectDirty(SupplierSource $source): RedirectResponse
    {
        return $this->runDeltaAction($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'project_dirty' => true,
        ], 'Fiyat/stok güncelleme + Abone Kataloğa Yansıtma');
    }

    public function syncReports(Request $request): View
    {
        $visibleSources = SupplierSource::query()
            ->selectable()
            ->with('supplier')
            ->orderBy('source_name')
            ->get();
        $visibleSourceIds = $visibleSources->pluck('id');
        $sourceId = $request->integer('source_id') ?: null;
        if ($sourceId !== null && !$visibleSourceIds->contains($sourceId)) {
            $sourceId = null;
        }
        $changeType = trim((string) $request->query('change_type', ''));
        $reviewStatus = trim((string) $request->query('review_status', ''));
        $reviewOnly = $request->boolean('review_only');

        $runs = ProductDataHubSyncRun::query()
            ->with(['source.supplier'])
            ->whereIn('supplier_source_id', $visibleSourceIds)
            ->when($sourceId, fn ($query) => $query->where('supplier_source_id', $sourceId))
            ->latest('id')
            ->get()
            ->map(function (ProductDataHubSyncRun $run) {
                $run->setAttribute('display_run_type', $this->resolveRunTypeLabel($run));
                $run->setAttribute('display_status_label', $this->resolveSyncStatusLabel($run));
                $run->setAttribute('display_status_badge', $this->resolveSyncStatusBadge($run));
                $run->setAttribute('blocked_total', $this->resolveRunBlockedTotal($run));

                return $run;
            });

        $selectedRun = $runs->first();
        $changes = $selectedRun
            ? $selectedRun->changes()
                ->when($changeType !== '', fn ($query) => $query->where('change_type', $changeType))
                ->when($reviewOnly, fn ($query) => $query->whereNotNull('review_status'))
                ->when($reviewStatus !== '', fn ($query) => $query->where('review_status', $reviewStatus))
                ->latest('id')
                ->get()
            : collect();

        $decisionSummary = $this->productHubSyncDecisionService->summarize($selectedRun, $changes);

        $sources = $visibleSources;

        return view('super-admin.product-data-hub.sources.sync-reports', compact('runs', 'selectedRun', 'changes', 'sources', 'sourceId', 'changeType', 'reviewStatus', 'reviewOnly', 'decisionSummary'));
    }

    private function validateSource(Request $request, bool $updating): array
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:255',
            'source_name' => 'required|string|max:255',
            'source_type' => 'required|in:xml,json,csv,api,excel,manual',
            'url' => 'nullable|url',
            'format' => 'nullable|string|max:50',
            'profile_key' => 'nullable|string|max:50',
            'source_profile_template' => 'nullable|string|max:50',
            'template_source_id' => 'nullable|exists:supplier_sources,id',
            'source_file_path' => 'nullable|string|max:500',
            'product_node_path' => 'nullable|string|max:255',
            'items_path' => 'nullable|string|max:255',
            'supplier_prefix' => 'nullable|string|max:20',
            'generated_code_template' => 'nullable|string|max:255',
            'generated_variant_code_template' => 'nullable|string|max:255',
            'sync_frequency' => 'required|in:manual,hourly,daily,weekly',
            'update_stock' => 'nullable|boolean',
            'update_price' => 'nullable|boolean',
            'update_images' => 'nullable|boolean',
            'update_categories' => 'nullable|boolean',
            'sync_auto_build' => 'nullable|boolean',
            'sync_auto_project_to_tenant_catalog' => 'nullable|boolean',
            'sync_block_on_missing_category' => 'nullable|boolean',
            'sync_block_on_missing_price' => 'nullable|boolean',
            'sync_block_on_conflict_category' => 'nullable|boolean',
            'sync_allow_warning_products_to_catalog' => 'nullable|boolean',
            'missing_product_policy' => 'nullable|in:never,manual_review,inactive_candidate,auto_inactive',
            'missing_product_grace_runs' => 'nullable|integer|min:0|max:50',
            'report_enabled' => 'nullable|boolean',
            'report_channel' => 'nullable|in:screen,email,notification_center',
            'http_method' => 'nullable|in:GET,POST,get,post',
            'auth_type' => 'nullable|in:none,basic,bearer,api_key',
            'user_agent' => 'nullable|string|max:255',
            'timeout_seconds' => 'nullable|integer|min:1|max:120',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:500',
            'auth_username' => 'nullable|string|max:255',
            'auth_password' => 'nullable|string|max:255',
            'auth_token' => 'nullable|string|max:500',
            'api_key_name' => 'nullable|string|max:255',
            'api_key_value' => 'nullable|string|max:500',
            'request_headers' => 'nullable|string|max:5000',
            'request_body' => 'nullable|string|max:5000',
            'ip_whitelist_required' => 'nullable|boolean',
            'proxy_strategy' => 'nullable|in:none,approved_server,manual_file_upload,external_worker_placeholder',
            'enrich_gallery_from_product_page' => 'nullable|boolean',
            'max_gallery_enrichment_products' => 'nullable|integer|min:1|max:50',
            'max_gallery_images' => 'nullable|integer|min:1|max:50',
            'product_page_gallery_selector' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive' . ($updating ? ',error' : ''),
            'notes' => 'nullable|string',
        ]);

        if (!$updating && blank($validated['supplier_id'] ?? null) && blank($validated['supplier_name'] ?? null)) {
            throw ValidationException::withMessages([
                'supplier_name' => 'Mevcut tedarikçi seçin veya yeni tedarikçi adı girin.',
            ]);
        }

        if (Str::startsWith((string) ($validated['profile_key'] ?? ''), 'TMP-')) {
            throw ValidationException::withMessages([
                'profile_key' => 'TMP / test profilleri gerçek kaynak oluşturmak için kullanılamaz.',
            ]);
        }

        $selectedTemplateKey = $this->resolveSelectedProfileTemplateKey($validated);
        if ($selectedTemplateKey !== null && $selectedTemplateKey !== 'CUSTOM' && !config()->has("prodelya_product_data_hub.supplier_profiles.{$selectedTemplateKey}")) {
            throw ValidationException::withMessages([
                'source_profile_template' => 'Seçilen kaynak profil şablonu tanınmıyor.',
            ]);
        }

        if ($selectedTemplateKey !== null) {
            $templateConfig = config("prodelya_product_data_hub.supplier_profiles.{$selectedTemplateKey}", []);
            $allowedSourceTypes = array_values(array_filter((array) ($templateConfig['allowed_ui_source_types'] ?? [])));
            if ($allowedSourceTypes !== [] && !in_array($validated['source_type'], $allowedSourceTypes, true)) {
                throw ValidationException::withMessages([
                    'source_type' => 'Seçilen profil yalnız şu kaynak tipleriyle kullanılabilir: ' . implode(', ', $allowedSourceTypes) . '.',
                ]);
            }

            if (filled($validated['source_profile_template'] ?? null) && $selectedTemplateKey !== 'CUSTOM') {
                $expectedIdentityKey = Str::upper((string) ($templateConfig['profile_identity_key'] ?? $selectedTemplateKey));
                $submittedIdentityKey = Str::upper(trim((string) ($validated['profile_key'] ?? '')));
                if ($submittedIdentityKey !== '' && $submittedIdentityKey !== $expectedIdentityKey) {
                    throw ValidationException::withMessages([
                        'profile_key' => 'Seçilen profil şablonu ile Profil Key uyuşmuyor.',
                    ]);
                }
            }
        }

        if (filled($validated['request_headers'] ?? null)) {
            $decodedHeaders = json_decode((string) $validated['request_headers'], true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedHeaders)) {
                throw ValidationException::withMessages([
                    'request_headers' => 'Özel HTTP Header alanı geçerli bir JSON nesnesi olmalıdır.',
                ]);
            }
        }

        return $validated;
    }

    private function buildConfig(
        array $validated,
        ?string $sourceProfileTemplate,
        string $profileKey,
        array $profileConfig,
        ?string $format,
        array $existingConfig = []
    ): array
    {
        return [
            'ui_source_type' => $validated['source_type'],
            'format' => $format,
            'profile_key' => $profileKey,
            'source_profile_template' => $sourceProfileTemplate,
            'source_file_path' => $validated['source_file_path'] ?? null,
            'product_node_path' => $validated['product_node_path'] ?? null,
            'items_path' => $validated['items_path'] ?? null,
            'supplier_prefix' => $validated['supplier_prefix'] ?? ($profileConfig['supplier_code_prefix'] ?? null),
            'generated_code_template' => $validated['generated_code_template'] ?? ($profileConfig['generated_code_template'] ?? null),
            'generated_variant_code_template' => $validated['generated_variant_code_template'] ?? ($profileConfig['generated_variant_code_template'] ?? null),
            'currency' => $profileConfig['currency'] ?? ($existingConfig['currency'] ?? null),
            'pricing_policy_type' => $profileConfig['pricing_policy_type'] ?? ($existingConfig['pricing_policy_type'] ?? null),
            'net_price_warning' => (bool) ($profileConfig['net_price_warning'] ?? $existingConfig['net_price_warning'] ?? false),
            'sync_frequency' => $validated['sync_frequency'],
            'sync_auto_build' => (bool) ($validated['sync_auto_build'] ?? true),
            'sync_auto_project_to_tenant_catalog' => (bool) ($validated['sync_auto_project_to_tenant_catalog'] ?? true),
            'sync_block_on_missing_category' => false,
            'missing_category_policy' => 'warn_and_project',
            'sync_block_on_missing_price' => (bool) ($validated['sync_block_on_missing_price'] ?? false),
            'sync_block_on_conflict_category' => (bool) ($validated['sync_block_on_conflict_category'] ?? true),
            'sync_allow_warning_products_to_catalog' => (bool) ($validated['sync_allow_warning_products_to_catalog'] ?? true),
            'sync_policy' => [
                'sync_frequency' => $validated['sync_frequency'],
                'update_stock' => (bool) ($validated['update_stock'] ?? true),
                'update_price' => (bool) ($validated['update_price'] ?? true),
                'update_images' => (bool) ($validated['update_images'] ?? true),
                'update_categories' => (bool) ($validated['update_categories'] ?? true),
                'sync_auto_build' => (bool) ($validated['sync_auto_build'] ?? true),
                'sync_auto_project_to_tenant_catalog' => (bool) ($validated['sync_auto_project_to_tenant_catalog'] ?? true),
                'sync_block_on_missing_category' => false,
                'missing_category_policy' => 'warn_and_project',
                'sync_block_on_missing_price' => (bool) ($validated['sync_block_on_missing_price'] ?? false),
                'sync_block_on_conflict_category' => (bool) ($validated['sync_block_on_conflict_category'] ?? true),
                'sync_allow_warning_products_to_catalog' => (bool) ($validated['sync_allow_warning_products_to_catalog'] ?? true),
                'missing_product_policy' => $validated['missing_product_policy'] ?? 'manual_review',
                'missing_product_grace_runs' => (int) ($validated['missing_product_grace_runs'] ?? 1),
                'report_enabled' => (bool) ($validated['report_enabled'] ?? true),
                'report_channel' => $validated['report_channel'] ?? 'screen',
            ],
            'copied_from_source_id' => $validated['template_source_id'] ?? null,
            'http_method' => Str::upper((string) ($validated['http_method'] ?? 'GET')),
            'auth_type' => $validated['auth_type'] ?? 'none',
            'user_agent' => filled($validated['user_agent'] ?? null)
                ? trim((string) $validated['user_agent'])
                : null,
            'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? ($existingConfig['timeout_seconds'] ?? 25)),
            'username' => $validated['username'] ?? null,
            'password' => filled($validated['password'] ?? null) ? $validated['password'] : ($existingConfig['password'] ?? null),
            'api_key' => filled($validated['api_key'] ?? null) ? $validated['api_key'] : ($existingConfig['api_key'] ?? null),
            'auth_username' => $validated['auth_username'] ?? ($validated['username'] ?? null),
            'auth_password' => filled($validated['auth_password'] ?? null) ? $validated['auth_password'] : ($existingConfig['auth_password'] ?? ($existingConfig['password'] ?? null)),
            'auth_token' => filled($validated['auth_token'] ?? null) ? $validated['auth_token'] : ($existingConfig['auth_token'] ?? null),
            'api_key_name' => $validated['api_key_name'] ?? ($existingConfig['api_key_name'] ?? null),
            'api_key_value' => filled($validated['api_key_value'] ?? null) ? $validated['api_key_value'] : ($existingConfig['api_key_value'] ?? null),
            'request_headers' => $this->normalizeRequestHeaders(
                $validated['request_headers'] ?? null,
                $existingConfig['request_headers'] ?? null
            ),
            'request_body' => $validated['request_body'] ?? null,
            'ip_whitelist_required' => (bool) ($validated['ip_whitelist_required'] ?? false),
            'proxy_strategy' => $validated['proxy_strategy'] ?? 'none',
            'enrich_gallery_from_product_page' => (bool) ($validated['enrich_gallery_from_product_page'] ?? false),
            'max_gallery_enrichment_products' => (int) ($validated['max_gallery_enrichment_products'] ?? 5),
            'max_gallery_images' => (int) ($validated['max_gallery_images'] ?? 10),
            'product_page_gallery_selector' => $validated['product_page_gallery_selector'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function resolveSourceSupplier(array &$validated): Supplier
    {
        if (!empty($validated['supplier_id'])) {
            return Supplier::findOrFail($validated['supplier_id']);
        }

        $supplierName = trim((string) ($validated['supplier_name'] ?? ''));
        $profileKey = trim((string) ($validated['profile_key'] ?? ''));
        $baseCode = $profileKey !== '' && $profileKey !== 'CUSTOM'
            ? Str::upper(Str::slug($profileKey, '-'))
            : Str::upper(Str::slug($supplierName, '-'));

        $code = $baseCode;
        $counter = 2;
        while (Supplier::query()->where('code', $code)->exists()) {
            $code = $baseCode . '-' . $counter;
            $counter++;
        }

        $supplier = Supplier::query()->create([
            'name' => $supplierName,
            'code' => $code,
            'status' => 'active',
        ]);

        $validated['supplier_id'] = $supplier->id;

        return $supplier;
    }

    private function copyTemplateMappingsIfNeeded(array $validated, SupplierSource $source): void
    {
        if (empty($validated['template_source_id'])) {
            return;
        }

        $templateSource = SupplierSource::query()->selectable()->find($validated['template_source_id']);

        if (!$templateSource) {
            return;
        }

        SupplierFieldMapping::query()
            ->where('supplier_source_id', $templateSource->id)
            ->get()
            ->each(function (SupplierFieldMapping $mapping) use ($source) {
                SupplierFieldMapping::query()->create([
                    'tenant_account_id' => $mapping->tenant_account_id,
                    'supplier_id' => $source->supplier_id,
                    'supplier_source_id' => $source->id,
                    'source_field' => $mapping->source_field,
                    'legacy_field_name' => $mapping->legacy_field_name,
                    'target_field' => $mapping->target_field,
                    'field_type' => $mapping->field_type,
                    'mapping_status' => $mapping->mapping_status,
                    'confidence_score' => $mapping->confidence_score,
                    'is_required' => $mapping->is_required,
                    'reviewed_at' => now(),
                    'meta' => array_merge((array) ($mapping->meta ?? []), [
                        'copied_from_source_id' => $mapping->supplier_source_id,
                    ]),
                ]);
            });
    }

    private function recordPreviewAttempt(
        SupplierSource $source,
        string $status,
        int $recordsRead,
        int $warningCount,
        int $errorCount,
        string $message
    ): void {
        FeedSyncLog::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'sync_type' => 'manual',
            'started_at' => now(),
            'completed_at' => now(),
            'status' => $status === 'success' ? 'completed' : 'failed',
            'total_records' => $recordsRead,
            'processed_records' => $recordsRead,
            'error_records' => $errorCount,
            'error_summary' => $message,
            'sync_metadata' => [
                'preview_mode' => $status,
                'warning_count' => $warningCount,
                'error_count' => $errorCount,
            ],
        ]);
    }

    private function sourceProfiles(): array
    {
        return config('prodelya_product_data_hub.supplier_profiles', []);
    }

    private function sourceProfileTemplates(array $profiles): array
    {
        return collect($profiles)->map(function (array $profile, string $key) {
            $aliases = (array) ($profile['field_aliases'] ?? []);
            $sourceFields = (array) ($profile['source_fields'] ?? []);
            $groups = $this->profileMappingGroups($aliases);
            $galleryFieldCount = collect($aliases)->filter(
                fn (string $targetField) => Str::startsWith($targetField, 'gallery_image_')
            )->count();

            return [
                'key' => $key,
                'display_name' => $profile['display_name'] ?? $key,
                'description' => $this->profileDescription($key, $profile),
                'product_model' => $profile['product_model'] ?? 'custom',
                'source_type' => $profile['ui_source_type'] ?? 'xml',
                'profile_identity_key' => $profile['profile_identity_key'] ?? $key,
                'default_url' => $profile['default_url'] ?? null,
                'suggested_source_name' => $profile['suggested_source_name'] ?? (($profile['display_name'] ?? $key) . ' Kaynağı'),
                'suggested_supplier_name' => $profile['suggested_supplier_name'] ?? ($profile['display_name'] ?? $key),
                'features' => [
                    'variants' => in_array($profile['product_model'] ?? '', ['parent_nested_variant', 'record_variant_row', 'flat_group_variant', 'parent_nested_variant_json'], true),
                    'multiple_images' => $galleryFieldCount > 0,
                    'gallery_images' => $galleryFieldCount > 0,
                    'stock' => collect($aliases)->contains(fn (string $targetField) => Str::contains($targetField, 'stock')),
                    'category_path' => collect($aliases)->contains(fn (string $targetField) => Str::contains($targetField, 'category')),
                    'warning_flags' => collect($aliases)->contains(fn (string $targetField) => $targetField === 'warning_flag'),
                    'net_price_warning' => $key === 'AKDENIZ',
                ],
                'field_counts' => [
                    'required' => collect($sourceFields)->take(6)->values()->all(),
                    'optional' => collect($sourceFields)->slice(6, 6)->values()->all(),
                ],
                'mapping_groups' => $groups,
                'supports_text' => [
                    'Varyant desteği' => in_array($profile['product_model'] ?? '', ['parent_nested_variant', 'record_variant_row', 'flat_group_variant', 'parent_nested_variant_json'], true) ? 'Var' : 'Yok',
                    'Çoklu görsel' => $galleryFieldCount > 0 ? 'Var' : 'Tekli / sınırlı',
                    'Fiyat standardı' => 'Liste fiyatı',
                    'Uyarılar' => $key === 'AKDENIZ'
                        ? 'Net fiyat / özel fiyat'
                        : (collect($aliases)->contains('warning_flag') ? 'Özel fiyat / uyarı' : 'Standart'),
                ],
            ];
        })->all();
    }

    private function sourceFormDefaults(?SupplierSource $templateSource): array
    {
        if (!$templateSource) {
            return [];
        }

        return [
            'supplier_id' => $templateSource->supplier_id,
            'source_name' => $templateSource->source_name . ' Kopya',
            'source_type' => $this->displaySourceType($templateSource),
            'source_profile_template' => $this->resolveSourceProfileTemplateKey($templateSource->config ?? [], $templateSource->supplier),
            'profile_key' => $this->resolveProfileKey($templateSource->supplier, $templateSource->config ?? []),
            'url' => $templateSource->url,
            'source_file_path' => $templateSource->config['source_file_path'] ?? null,
            'product_node_path' => $templateSource->config['product_node_path'] ?? null,
            'items_path' => $templateSource->config['items_path'] ?? null,
            'supplier_prefix' => $templateSource->config['supplier_prefix'] ?? null,
            'generated_code_template' => $templateSource->config['generated_code_template'] ?? null,
            'generated_variant_code_template' => $templateSource->config['generated_variant_code_template'] ?? null,
            'sync_frequency' => $templateSource->config['sync_frequency'] ?? 'manual',
            'update_stock' => data_get($templateSource->config, 'sync_policy.update_stock', true),
            'update_price' => data_get($templateSource->config, 'sync_policy.update_price', true),
            'update_images' => data_get($templateSource->config, 'sync_policy.update_images', true),
            'update_categories' => data_get($templateSource->config, 'sync_policy.update_categories', true),
            'sync_auto_build' => data_get($templateSource->config, 'sync_policy.sync_auto_build', $templateSource->config['sync_auto_build'] ?? true),
            'sync_auto_project_to_tenant_catalog' => data_get($templateSource->config, 'sync_policy.sync_auto_project_to_tenant_catalog', $templateSource->config['sync_auto_project_to_tenant_catalog'] ?? true),
            'sync_block_on_missing_category' => data_get($templateSource->config, 'sync_policy.sync_block_on_missing_category', $templateSource->config['sync_block_on_missing_category'] ?? true),
            'sync_block_on_missing_price' => data_get($templateSource->config, 'sync_policy.sync_block_on_missing_price', $templateSource->config['sync_block_on_missing_price'] ?? false),
            'sync_block_on_conflict_category' => data_get($templateSource->config, 'sync_policy.sync_block_on_conflict_category', $templateSource->config['sync_block_on_conflict_category'] ?? true),
            'sync_allow_warning_products_to_catalog' => data_get($templateSource->config, 'sync_policy.sync_allow_warning_products_to_catalog', $templateSource->config['sync_allow_warning_products_to_catalog'] ?? true),
            'missing_product_policy' => data_get($templateSource->config, 'sync_policy.missing_product_policy', 'manual_review'),
            'missing_product_grace_runs' => data_get($templateSource->config, 'sync_policy.missing_product_grace_runs', 1),
            'report_enabled' => data_get($templateSource->config, 'sync_policy.report_enabled', true),
            'report_channel' => data_get($templateSource->config, 'sync_policy.report_channel', 'screen'),
            'status' => 'active',
            'http_method' => $templateSource->config['http_method'] ?? 'GET',
            'auth_type' => $templateSource->config['auth_type'] ?? 'none',
            'user_agent' => $templateSource->config['user_agent'] ?? null,
            'timeout_seconds' => $templateSource->config['timeout_seconds'] ?? 25,
            'auth_username' => $templateSource->config['auth_username'] ?? null,
            'api_key_name' => $templateSource->config['api_key_name'] ?? 'X-API-KEY',
            'request_headers' => $templateSource->config['request_headers'] ?? null,
            'request_headers_display' => $this->formatRequestHeadersForDisplay($templateSource->config['request_headers'] ?? null),
            'request_body' => $templateSource->config['request_body'] ?? null,
            'ip_whitelist_required' => (bool) ($templateSource->config['ip_whitelist_required'] ?? false),
            'proxy_strategy' => $templateSource->config['proxy_strategy'] ?? 'none',
            'enrich_gallery_from_product_page' => (bool) ($templateSource->config['enrich_gallery_from_product_page'] ?? false),
            'max_gallery_enrichment_products' => $templateSource->config['max_gallery_enrichment_products'] ?? 5,
            'max_gallery_images' => $templateSource->config['max_gallery_images'] ?? 10,
            'product_page_gallery_selector' => $templateSource->config['product_page_gallery_selector'] ?? null,
            'notes' => $templateSource->config['notes'] ?? null,
        ];
    }

    private function normalizeRequestHeaders(mixed $requestHeaders, mixed $existingHeaders): ?string
    {
        if (!filled($requestHeaders)) {
            return null;
        }

        $decodedHeaders = $this->decodeRequestHeaders($requestHeaders);
        if ($decodedHeaders === null) {
            return is_string($requestHeaders) ? trim($requestHeaders) : null;
        }

        $existingDecodedHeaders = $this->decodeRequestHeaders($existingHeaders) ?? [];
        $decodedHeaders = $this->sensitiveDataMasker->restoreMaskedHeaders(
            $decodedHeaders,
            $existingDecodedHeaders,
            self::MASKED_SECRET_VALUE
        );

        return json_encode($decodedHeaders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function formatRequestHeadersForDisplay(mixed $requestHeaders): ?string
    {
        $decodedHeaders = $this->decodeRequestHeaders($requestHeaders);

        if ($decodedHeaders === null) {
            return is_string($requestHeaders) ? $requestHeaders : null;
        }

        $decodedHeaders = $this->sensitiveDataMasker->maskHeaders($decodedHeaders, self::MASKED_SECRET_VALUE);

        return json_encode($decodedHeaders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function decodeRequestHeaders(mixed $requestHeaders): ?array
    {
        if (is_array($requestHeaders)) {
            return $requestHeaders;
        }

        if (!is_string($requestHeaders) || trim($requestHeaders) === '') {
            return null;
        }

        $decoded = json_decode($requestHeaders, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : null;
    }

    private function isSensitiveHeaderKey(string $key): bool
    {
        return $this->sensitiveDataMasker->isSensitiveHeaderKey($key);
    }

    private function resolveProfileKey(Supplier $supplier, array $input): string
    {
        $profileKey = trim((string) ($input['profile_key'] ?? $input['config']['profile_key'] ?? ''));
        if ($profileKey !== '') {
            return $profileKey;
        }

        $templateKey = $this->resolveSourceProfileTemplateKey($input, $supplier);
        if (filled($templateKey)) {
            $profile = $this->fieldDictionary->getSupplierProfile($templateKey);

            return (string) ($profile['profile_identity_key'] ?? $templateKey);
        }

        return Str::upper(Str::slug($supplier->code ?: $supplier->name, '-'));
    }

    private function resolveSourceProfileTemplateKey(array $input, ?Supplier $supplier = null): ?string
    {
        return $this->fieldDictionary->resolveProfileTemplateKey(
            $input,
            $supplier?->code,
            $supplier?->name
        );
    }

    private function resolveSelectedProfileTemplateKey(array $input): ?string
    {
        $templateKey = $input['source_profile_template'] ?? null;
        if (filled($templateKey)) {
            return (string) $templateKey;
        }

        $legacyProfileKey = $input['profile_key'] ?? null;
        if (filled($legacyProfileKey) && ($legacyProfileKey === 'CUSTOM' || config()->has('prodelya_product_data_hub.supplier_profiles.' . $legacyProfileKey))) {
            return (string) $legacyProfileKey;
        }

        return null;
    }

    private function isTempProfile(Supplier $supplier, string $profileKey, array $profiles): bool
    {
        return Str::startsWith((string) $supplier->code, 'TMP-')
            || Str::startsWith((string) $profileKey, 'TMP-')
            || (!array_key_exists($profileKey, $profiles) && $profileKey !== 'CUSTOM');
    }

    private function sourceDependencySummary(SupplierSource $source): array
    {
        $standardProductIds = SupplierProductRaw::query()
            ->where('supplier_source_id', $source->id)
            ->whereNotNull('standard_product_id')
            ->distinct()
            ->pluck('standard_product_id');

        $summary = [
            'raw_products' => SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count(),
            'raw_variants' => SupplierProductVariantRaw::query()->where('supplier_source_id', $source->id)->count(),
            'field_mappings' => SupplierFieldMapping::query()->where('supplier_source_id', $source->id)->count(),
            'category_mappings' => SupplierCategoryMapping::query()->where('supplier_source_id', $source->id)->count(),
            'sync_logs' => FeedSyncLog::query()->where('supplier_source_id', $source->id)->count(),
            'sync_errors' => FeedSyncError::query()->where('supplier_source_id', $source->id)->count(),
            'sync_runs' => ProductDataHubSyncRun::query()->where('supplier_source_id', $source->id)->count(),
            'sync_changes' => ProductDataHubSyncChange::query()->where('supplier_source_id', $source->id)->count(),
            'suggestion_logs' => ProductCategorySuggestionLog::query()->where('supplier_source_id', $source->id)->count(),
            'standard_products' => $standardProductIds->count(),
            'standard_product_images' => StandardProductImage::query()->where('source_supplier_source_id', $source->id)->count(),
            'tenant_catalog_products' => $standardProductIds->isEmpty()
                ? 0
                : TenantCatalogProduct::query()->whereIn('standard_product_id', $standardProductIds)->count(),
            'tenant_catalog_variants' => $standardProductIds->isEmpty()
                ? 0
                : DB::table('tenant_catalog_product_variants as tcpv')
                    ->join('tenant_catalog_products as tcp', 'tcp.id', '=', 'tcpv.tenant_catalog_product_id')
                    ->whereIn('tcp.standard_product_id', $standardProductIds)
                    ->count(),
            'quote_visible_products' => $standardProductIds->isEmpty()
                ? 0
                : TenantCatalogProduct::query()
                    ->whereIn('standard_product_id', $standardProductIds)
                    ->where('visible_in_quote', true)
                    ->count(),
            'quote_visible_variants' => $standardProductIds->isEmpty()
                ? 0
                : DB::table('tenant_catalog_product_variants as tcpv')
                    ->join('tenant_catalog_products as tcp', 'tcp.id', '=', 'tcpv.tenant_catalog_product_id')
                    ->whereIn('tcp.standard_product_id', $standardProductIds)
                    ->where('tcpv.is_active', true)
                    ->where('tcpv.visible_in_catalog', true)
                    ->count(),
            'tenant_access' => TenantSupplierAccess::query()->where('supplier_id', $source->supplier_id)->count(),
            'order_items' => OrderItem::query()->where('supplier_source_id', $source->id)->count(),
        ];

        if (Schema::hasTable('product_mappings')) {
            $summary['product_mappings'] = DB::table('product_mappings')
                ->where('supplier_source_id', $source->id)
                ->count();
        }

        $summary['total'] = collect($summary)->sum();

        return $summary;
    }

    private function profileDescription(string $key, array $profile): string
    {
        return match ($key) {
            'YENI-NESIL' => 'Düz ürün + grup varyant yapısı, çoklu görsel ve liste fiyatı standardı için hazır şablon.',
            'ETKIN' => 'Parent + nested varyant yapısı, görsel galerisi ve tedarikçi uyarıları için hazır XML profili.',
            'AKDENIZ' => 'Kayıt-bazlı varyant yapısı, net fiyat uyarısı ve geniş galeri desteği için hazır profil.',
            'ILPEN' => 'Ürün kartı + varyasyon yapısı, görsel fallback ve varyant stok kodu desteği için hazır profil.',
            default => $profile['description'] ?? 'Hazır alan eşleme kütüphanesinden gelen kaynak profili.',
        };
    }

    private function profileMappingGroups(array $aliases): array
    {
        $groups = [
            'Ürün temel alanları' => [],
            'Kategori alanları' => [],
            'Varyant alanları' => [],
            'Fiyat alanları' => [],
            'Stok alanları' => [],
            'Görsel alanları' => [],
            'Uyarı alanları' => [],
        ];

        foreach ($aliases as $sourceField => $targetField) {
            $label = $sourceField . ' → ' . $targetField;

            if (Str::contains($targetField, 'category')) {
                $groups['Kategori alanları'][] = $label;
            } elseif (Str::contains($targetField, ['variant', 'option'])) {
                $groups['Varyant alanları'][] = $label;
            } elseif (Str::contains($targetField, ['price', 'currency', 'vat'])) {
                $groups['Fiyat alanları'][] = $label;
            } elseif (Str::contains($targetField, 'stock')) {
                $groups['Stok alanları'][] = $label;
            } elseif (Str::contains($targetField, ['image', 'gallery'])) {
                $groups['Görsel alanları'][] = $label;
            } elseif (Str::contains($targetField, 'warning')) {
                $groups['Uyarı alanları'][] = $label;
            } else {
                $groups['Ürün temel alanları'][] = $label;
            }
        }

        return collect($groups)
            ->map(fn (array $items) => array_slice($items, 0, 6))
            ->all();
    }

    private function normalizeStoredSourceType(string $sourceType): string
    {
        return $sourceType === 'json' ? 'api' : $sourceType;
    }

    private function resolveFormat(string $sourceType, ?string $format): ?string
    {
        if ($sourceType === 'json') {
            return 'json';
        }

        return $format;
    }

    private function displaySourceType(SupplierSource $source): string
    {
        if (($source->config['ui_source_type'] ?? null) === 'json' || strtolower((string) ($source->config['format'] ?? '')) === 'json') {
            return 'json';
        }

        return $source->source_type;
    }

    private function resolveSyncFrequency(SupplierSource $source): string
    {
        return (string) data_get($source->config, 'sync_policy.sync_frequency', data_get($source->config, 'sync_frequency', 'manual'));
    }

    private function resolveSyncFrequencyLabel(SupplierSource $source): string
    {
        return match ($this->resolveSyncFrequency($source)) {
            'hourly' => 'Saatlik',
            'daily' => 'Günlük',
            'weekly' => 'Haftalık',
            default => 'Manuel',
        };
    }

    private function resolveNextPlannedSync(SupplierSource $source): ?Carbon
    {
        $now = now();

        return match ($this->resolveSyncFrequency($source)) {
            'hourly' => $now->copy()->addHour()->startOfHour(),
            'daily' => $this->nextScheduledTime($now, 3, 0, null),
            'weekly' => $this->nextScheduledTime($now, 4, 0, Carbon::MONDAY),
            default => null,
        };
    }

    private function resolveNextPlannedSyncLabel(SupplierSource $source): string
    {
        $nextSync = $this->resolveNextPlannedSync($source);

        return $nextSync ? $nextSync->format('d.m.Y H:i') : 'Otomatik plan yok';
    }

    private function nextScheduledTime(Carbon $now, int $hour, int $minute, ?int $weekday): Carbon
    {
        $candidate = $now->copy()->setTime($hour, $minute, 0);

        if ($weekday !== null) {
            $currentWeekday = (int) $candidate->dayOfWeekIso;
            $delta = $weekday - $currentWeekday;

            if ($delta < 0) {
                $delta += 7;
            }

            $candidate->addDays($delta);
        }

        if ($candidate->lessThanOrEqualTo($now)) {
            return $weekday === null
                ? $candidate->addDay()
                : $candidate->addWeek();
        }

        return $candidate;
    }

    private function resolveAutoBuildEnabled(SupplierSource $source): bool
    {
        return (bool) data_get($source->config, 'sync_policy.sync_auto_build', data_get($source->config, 'sync_auto_build', true));
    }

    private function resolveAutoProjectEnabled(SupplierSource $source): bool
    {
        return (bool) data_get($source->config, 'sync_policy.sync_auto_project_to_tenant_catalog', data_get($source->config, 'sync_auto_project_to_tenant_catalog', true));
    }

    private function resolveSyncStatusLabel(?ProductDataHubSyncRun $run): string
    {
        if (!$run) {
            return 'Hiç çalışmadı';
        }

        if ((bool) data_get($run->report_payload, 'dry_run')) {
            return 'Dry-run';
        }

        return match ($run->normalizedStatus()) {
            ProductDataHubSyncRun::STATUS_COMPLETED => 'Başarılı',
            ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS => 'Uyarılı Tamamlandı',
            ProductDataHubSyncRun::STATUS_FAILED => 'Hatalı',
            ProductDataHubSyncRun::STATUS_RUNNING => 'Çalışıyor',
            ProductDataHubSyncRun::STATUS_STUCK => 'Takıldı',
            ProductDataHubSyncRun::STATUS_RECOVERED => 'Recovery Yapıldı',
            ProductDataHubSyncRun::STATUS_CANCELLED => 'İptal',
            default => 'Bekleniyor',
        };
    }

    private function resolveSyncStatusBadge(?ProductDataHubSyncRun $run): string
    {
        if (!$run) {
            return 'gray';
        }

        if ((bool) data_get($run->report_payload, 'dry_run')) {
            return 'blue';
        }

        return match ($run->normalizedStatus()) {
            ProductDataHubSyncRun::STATUS_COMPLETED => 'green',
            ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS => 'amber',
            ProductDataHubSyncRun::STATUS_FAILED => 'red',
            ProductDataHubSyncRun::STATUS_RUNNING => 'blue',
            ProductDataHubSyncRun::STATUS_STUCK => 'red',
            ProductDataHubSyncRun::STATUS_RECOVERED => 'purple',
            ProductDataHubSyncRun::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    private function resolveRunTypeLabel(ProductDataHubSyncRun $run): string
    {
        if ((bool) data_get($run->report_payload, 'dry_run')) {
            return 'Dry-run';
        }

        return match ($run->run_type) {
            'scheduled' => 'Zamanlanmış',
            default => 'Manuel',
        };
    }

    private function resolveRunBlockedTotal(ProductDataHubSyncRun $run): int
    {
        return (int) data_get($run->report_payload, 'projection.blocked_missing_category', 0)
            + (int) data_get($run->report_payload, 'projection.blocked_missing_price', 0)
            + (int) data_get($run->report_payload, 'projection.blocked_conflict_category', 0)
            + (int) data_get($run->report_payload, 'projection.blocked_projection_errors', 0);
    }

    private function resolveSyncSummary(?ProductDataHubSyncRun $run): array
    {
        if (!$run) {
            return [
                'created' => 0,
                'updated' => 0,
                'stock_changed' => 0,
                'price_changed' => 0,
                'missing_from_feed' => 0,
                'errors' => 0,
            ];
        }

        return [
            'created' => (int) $run->products_created,
            'updated' => (int) $run->products_updated,
            'stock_changed' => (int) $run->stock_changed_count,
            'price_changed' => (int) $run->price_changed_count,
            'missing_from_feed' => (int) $run->products_missing_from_feed,
            'errors' => (int) $run->error_count,
        ];
    }

    private function runDeltaAction(SupplierSource $source, array $options, string $actionLabel): RedirectResponse
    {
        if ($source->status !== 'active') {
            return redirect()
                ->route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id])
                ->with('error', 'Bu aksiyon yalnız aktif kaynaklarda çalıştırılabilir.');
        }

        try {
            $result = $this->sourceSync->syncSource($source, $options);
            $run = $result['run'];
        } catch (\Throwable $exception) {
            Log::error('Product Hub delta action failed.', [
                'source_id' => $source->id,
                'supplier_id' => $source->supplier_id,
                'action' => $actionLabel,
                'options' => $options,
                'exception' => $exception,
            ]);

            return redirect()
                ->route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id])
                ->with('error', 'Fiyat/stok güncelleme tamamlanamadı. Sistem kayıt şeması veya güvenlik kontrolü nedeniyle işlem durduruldu. Detaylar Senkron / Raporlar ekranında görülebilir.');
        }

        return redirect()
            ->route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id])
            ->with(
                in_array($run->normalizedStatus(), [ProductDataHubSyncRun::STATUS_COMPLETED, ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS], true) ? 'success' : 'error',
                $this->buildDeltaActionFlashMessage($source, $run, $actionLabel)
            );
    }

    private function buildDeltaActionFlashMessage(SupplierSource $source, ProductDataHubSyncRun $run, string $actionLabel): string
    {
        if ($run->normalizedStatus() === ProductDataHubSyncRun::STATUS_FAILED) {
            return $actionLabel . ' başlatılamadı: ' . ($run->error_message ?: 'Kaynak verisi okunamadı.');
        }

        $summaryKey = data_get($run->report_payload, 'delta_apply_summary') ? 'delta_apply_summary' : 'delta_summary';
        $priceChanged = (int) data_get($run->report_payload, "{$summaryKey}.counts.price_changed", 0)
            + (int) data_get($run->report_payload, "{$summaryKey}.counts.price_and_stock_changed", 0);
        $stockChanged = (int) data_get($run->report_payload, "{$summaryKey}.counts.stock_changed", 0)
            + (int) data_get($run->report_payload, "{$summaryKey}.counts.price_and_stock_changed", 0);
        $newProducts = (int) data_get($run->report_payload, "{$summaryKey}.counts.new_product", 0);
        $missingProducts = (int) data_get($run->report_payload, "{$summaryKey}.counts.missing_product", 0)
            + (int) data_get($run->report_payload, "{$summaryKey}.counts.missing_variant", 0);
        $applied = (int) data_get($run->report_payload, "{$summaryKey}.price_stock_applied", 0);
        if ($applied === 0) {
            $applied = (int) data_get($run->report_payload, "{$summaryKey}.price_changed_applied", 0)
                + (int) data_get($run->report_payload, "{$summaryKey}.stock_changed_applied", 0)
                + (int) data_get($run->report_payload, "{$summaryKey}.price_and_stock_changed_applied", 0);
        }
        $projected = (int) data_get($run->report_payload, "{$summaryKey}.tenant_catalog_products_updated", 0)
            + (int) data_get($run->report_payload, "{$summaryKey}.tenant_catalog_variants_updated", 0);
        $standardTouched = (int) data_get($run->report_payload, "{$summaryKey}.affected_standard_products_count", 0);
        $projectionVariants = (int) data_get($run->report_payload, "{$summaryKey}.tenant_catalog_variants_updated", 0);
        $reviewOnlySkipped = (int) data_get($run->report_payload, "{$summaryKey}.skipped_review_only_changes", 0);
        $requiredFieldSkipped = (int) data_get($run->report_payload, "{$summaryKey}.skipped_required_field_missing", 0);

        return trim(sprintf(
            '%s tamamlandı: %s / %s. Raw fiyat/stok değişimi %d, stok değişimi %d, yeni ürün %d, kaynakta görünmeyen %d, otomatik işlenen %d, standart katmana dokunan %d, tenant katalog varyantı güncellenen %d, kataloga yansıtılan %d, incelemede kalan riskli kayıt %d, zorunlu alan eksiği nedeniyle atlanan %d.',
            $actionLabel,
            $source->supplier?->name ?? 'Tedarikçi',
            $source->source_name,
            $priceChanged,
            $stockChanged,
            $newProducts,
            $missingProducts,
            $applied,
            $standardTouched,
            $projectionVariants,
            $projected,
            $reviewOnlySkipped,
            $requiredFieldSkipped
        ));
    }

    private function buildFreshnessSummary(Collection $runs): array
    {
        $deltaRuns = $runs->filter(fn (ProductDataHubSyncRun $run) => data_get($run->report_payload, 'mode') === 'delta')->values();
        $latestDryRun = $deltaRuns->first(fn (ProductDataHubSyncRun $run) => (bool) data_get($run->report_payload, 'dry_run'));
        $latestApply = $deltaRuns->first(fn (ProductDataHubSyncRun $run) => !(bool) data_get($run->report_payload, 'dry_run') && data_get($run->report_payload, 'delta_apply_summary') && data_get($run->report_payload, 'delta_apply_summary.projection_mode', 'none') === 'none');
        $latestProjected = $deltaRuns->first(fn (ProductDataHubSyncRun $run) => !(bool) data_get($run->report_payload, 'dry_run') && data_get($run->report_payload, 'delta_apply_summary.projection_mode') === 'dirty');
        $latestAnyDelta = $deltaRuns->first();
        $summaryKey = $latestAnyDelta && data_get($latestAnyDelta->report_payload, 'delta_apply_summary')
            ? 'delta_apply_summary'
            : 'delta_summary';

        return [
            'has_report' => $latestAnyDelta !== null,
            'last_check_at' => $latestDryRun?->finished_at,
            'last_apply_at' => $latestApply?->finished_at,
            'last_project_at' => $latestProjected?->finished_at,
            'price_changed_total' => (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.counts.price_changed", 0)
                + (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.counts.price_and_stock_changed", 0),
            'stock_changed_total' => (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.counts.stock_changed", 0)
                + (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.counts.price_and_stock_changed", 0),
            'projected_total' => (int) data_get($latestProjected?->report_payload, 'delta_apply_summary.tenant_catalog_products_updated', 0)
                + (int) data_get($latestProjected?->report_payload, 'delta_apply_summary.tenant_catalog_variants_updated', 0),
            'quote_hidden_total' => (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.projection_skipped_review_only_change", 0),
            'automatic_updates' => (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.price_stock_applied", 0)
                ?: (
                    (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.price_changed_applied", 0)
                    + (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.stock_changed_applied", 0)
                    + (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.price_and_stock_changed_applied", 0)
                ),
            'review_required' => (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.review_only_changes_detected", 0)
                + (int) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.skipped_required_field_missing", 0),
            'flags' => [
                'suspicious_price_jump' => (bool) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.flags.suspicious_price_jump", false),
                'feed_degraded' => (bool) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.flags.feed_degraded", false),
                'suspicious_feed_drop' => (bool) data_get($latestAnyDelta?->report_payload, "{$summaryKey}.flags.suspicious_feed_drop", false),
            ],
        ];
    }

    private function applyPreviewFilter(Collection $rows, string $filter): Collection
    {
        return (match ($filter) {
            'warning', 'uyarili' => $rows->filter(fn (array $row) => (bool) ($row['has_warning'] ?? false)),
            'missing-image', 'gorsel-eksik' => $rows->filter(fn (array $row) => (bool) ($row['missing_image'] ?? false)),
            'missing-price', 'fiyat-eksik' => $rows->filter(fn (array $row) => (bool) ($row['missing_price'] ?? false)),
            'net-price-warning' => $rows->filter(fn (array $row) => (bool) ($row['net_price_warning'] ?? false)),
            'supplier-warning' => $rows->filter(fn (array $row) => (bool) ($row['supplier_warning_flag'] ?? false)),
            'parse-error', 'parse-hatali' => $rows->filter(fn (array $row) => (bool) ($row['has_parse_error'] ?? false)),
            default => $rows,
        })->values();
    }

    private function enrichPreviewRow(array $row, bool $isVariant, SupplierSource $source): array
    {
        $profileKey = $this->previewParser->getSupplierProfileKey($source);
        $warnings = collect($row['warnings'] ?? [])->filter()->values()->all();
        $originalErrors = collect($row['errors'] ?? [])->filter()->values()->all();
        $infoMessages = [];
        $reviewMessages = [];
        $criticalMessages = [];

        $missingImage = blank($row[$isVariant ? 'variant_image_url' : 'image_url'] ?? null);
        $missingPrice = blank($row['list_price'] ?? null) || (float) ($row['list_price'] ?? 0) <= 0;
        $missingCategory = !$isVariant && blank($row['supplier_category_name'] ?? null);
        $missingProductCode = !$isVariant && blank($row['supplier_product_code'] ?? null);
        $missingVariantCode = $isVariant && blank($row['variant_stock_code'] ?? null);
        $missingName = !$isVariant && blank($row['product_name'] ?? null);
        $missingVariantRelation = $isVariant && blank($row['parent_supplier_product_id'] ?? null);
        $derivedProductCode = false;

        if ($profileKey === 'ILPEN' && !$isVariant && $missingProductCode) {
            $derivedProductCode = filled($row['generated_product_code'] ?? null)
                && (
                    filled($row['supplier_group_code'] ?? null)
                    || filled($row['supplier_product_id'] ?? null)
                );

            if ($derivedProductCode) {
                $missingProductCode = false;
                $infoMessages[] = 'İlpen profilinde ana ürün kodu ürün kartı seviyesinde gelmiyor. Kod bilgisi varyasyon/grup alanlarından türetildiği için bu kayıt kritik hata olarak değerlendirilmedi.';
            }
        }

        if ($missingProductCode) {
            $criticalMessages[] = 'Bu kayıtta ürün veya varyant kodu üretilemedi. Standard ürüne dönüştürmeden önce alan eşlemesi kontrol edilmelidir.';
        }

        if ($missingName) {
            $reviewMessages[] = 'Bu kayıtta ürün adı eksik. Ürün katalogda düzgün görünmeyebilir.';
        }

        if ($missingPrice) {
            $criticalMessages[] = 'Bu üründe liste fiyatı bulunamadı. Teklif/sipariş ekranında fiyat manuel kontrol edilmelidir.';
        }

        if ($missingImage) {
            $criticalMessages[] = 'Bu üründe görsel bulunamadı. Katalog görünümünde görsel boş kalabilir.';
        }

        if ($missingCategory) {
            $reviewMessages[] = 'Bu kayıtta tedarikçi kategori bilgisi eksik. Kategori eşleme öncesinde kontrol edilmelidir.';
        }

        if ($missingVariantCode) {
            $criticalMessages[] = 'Bu kayıtta ürün veya varyant kodu üretilemedi. Standard ürüne dönüştürmeden önce alan eşlemesi kontrol edilmelidir.';
        }

        if ($missingVariantRelation) {
            $reviewMessages[] = 'Varyant kodu eşlenmemiş veya boş. Satılabilir varyant ayrımı için Varyant Kodu alanını eşleyin.';
        }

        if ($profileKey === 'ILPEN' && $isVariant && (bool) ($row['image_fallback_used'] ?? false) && filled($row['variant_image_url'] ?? null)) {
            $infoMessages[] = 'Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.';
        }
        if (!$isVariant && (bool) ($row['temporary_product_code'] ?? false)) {
            $reviewMessages[] = 'Ürün kodu XML’den gelmediği için geçici kod üretildi.';
        }
        if ($isVariant && (bool) ($row['temporary_variant_code'] ?? false)) {
            $reviewMessages[] = 'Varyant kodu eşlenmemiş veya boş. Satılabilir varyant ayrımı için Varyant Kodu alanını eşleyin.';
        }

        if (!empty($row['net_price_warning']) || !empty($row['supplier_warning_flag']) || !empty($row['price_policy_warning'])) {
            $reviewMessages = array_merge($reviewMessages, array_values(array_filter($warnings)));
            $warnings = [];
        }

        $criticalMessages = array_values(array_unique(array_filter(array_merge($criticalMessages, $originalErrors))));
        $reviewMessages = array_values(array_unique(array_filter(array_merge($reviewMessages, $warnings))));
        $infoMessages = array_values(array_unique(array_filter($infoMessages)));

        $row['warnings'] = $reviewMessages;
        $row['errors'] = $criticalMessages;
        $row['info_messages'] = $infoMessages;
        $row['missing_image'] = $missingImage;
        $row['missing_price'] = $missingPrice;
        $row['missing_category'] = $missingCategory;
        $row['missing_product_code'] = $missingProductCode;
        $row['missing_variant_code'] = $missingVariantCode;
        $row['missing_variant_relation'] = $missingVariantRelation;
        $row['derived_product_code'] = $derivedProductCode;
        $row['temporary_product_code'] = (bool) ($row['temporary_product_code'] ?? false);
        $row['temporary_variant_code'] = (bool) ($row['temporary_variant_code'] ?? false);
        $row['has_parse_error'] = !empty($originalErrors);
        $row['critical_issue_count'] = count($criticalMessages);
        $row['review_issue_count'] = count($reviewMessages);
        $row['info_issue_count'] = count($infoMessages);
        $row['has_warning'] = !empty($row['warnings'])
            || !empty($row['info_messages'])
            || !empty($row['price_policy_warning'])
            || !empty($row['net_price_warning'])
            || !empty($row['supplier_warning_flag'])
            || !empty($row['has_parse_error']);

        return $row;
    }
}
