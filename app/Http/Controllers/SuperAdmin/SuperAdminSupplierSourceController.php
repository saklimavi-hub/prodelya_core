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
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\RawProductStagingService;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SuperAdminSupplierSourceController extends Controller
{
    public function __construct(
        private readonly PreviewParserService $previewParser,
        private readonly RawProductStagingService $rawProductStaging,
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser,
        private readonly SupplierSourceSyncService $sourceSync
    ) {
        // TODO: Add middleware for global supplier sources
        // $this->middleware('permission:manage_product_data_hub');
    }

    public function index(Request $request): View
    {
        $sourceProfiles = $this->sourceProfiles();
        $requestedFilter = trim((string) $request->query('filter', ''));
        $showTemp = $request->boolean('show_temp');
        $activeFilter = $requestedFilter !== ''
            ? $requestedFilter
            : ($showTemp ? 'temp' : 'active');
        $lastCategoryScans = SupplierCategoryMapping::query()
            ->selectRaw('supplier_source_id, MAX(last_scanned_at) as last_scanned_at')
            ->groupBy('supplier_source_id')
            ->pluck('last_scanned_at', 'supplier_source_id');

        $sources = SupplierSource::with(['supplier'])
            ->withCount(['fieldMappings', 'categoryMappings', 'rawProducts'])
            ->orderBy('status')
            ->orderBy('source_name')
            ->get();

        $latestSyncRuns = ProductDataHubSyncRun::query()
            ->whereIn('supplier_source_id', $sources->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('supplier_source_id')
            ->map(fn (Collection $runs) => $runs->first());

        $sources = $sources->map(function (SupplierSource $source) use ($sourceProfiles, $lastCategoryScans, $latestSyncRuns) {
            $profileKey = $this->resolveProfileKey($source->supplier, $source->config ?? []);
            $isTempProfile = $this->isTempProfile($source->supplier, $profileKey, $sourceProfiles);
            $hasProfileTemplate = array_key_exists($profileKey, $sourceProfiles);
            $hasLocation = filled($source->url) || filled($source->config['source_file_path'] ?? null);
            $hasFieldMappings = (int) ($source->field_mappings_count ?? 0) > 0;
            $lastCategoryScanAt = $lastCategoryScans[$source->id] ?? null;
            $latestSync = $latestSyncRuns[$source->id] ?? null;
            $lifecycleState = (string) ($source->config['lifecycle_state'] ?? '');
            $dependencySummary = $this->sourceDependencySummary($source);

            $source->setAttribute('display_source_type', $this->displaySourceType($source));
            $source->setAttribute('profile_key', $profileKey);
            $source->setAttribute('profile_prefix', $source->config['supplier_prefix']
                ?? config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.supplier_code_prefix")
                ?? '-');
            $source->setAttribute('display_location', $source->url ?: ($source->config['source_file_path'] ?? '-'));
            $source->setAttribute('display_path', $source->config['product_node_path'] ?? $source->config['items_path'] ?? '-');
            $source->setAttribute('last_test_display', FeedSyncLog::query()
                ->where('supplier_source_id', $source->id)
                ->latest('completed_at')
                ->value('completed_at'));
            $source->setAttribute('last_preview_display', FeedSyncLog::query()
                ->where('supplier_source_id', $source->id)
                ->latest('created_at')
                ->value('created_at'));
            $source->setAttribute('last_category_scan_display', $lastCategoryScanAt);
            $source->setAttribute('is_temp_profile', $isTempProfile);
            $source->setAttribute('has_profile_template', $hasProfileTemplate);
            $source->setAttribute('has_location', $hasLocation);
            $source->setAttribute('has_field_mappings', $hasFieldMappings);
            $source->setAttribute('has_category_mappings', (int) ($source->category_mappings_count ?? 0) > 0);
            $source->setAttribute('is_ready', !$isTempProfile && $hasLocation && $hasFieldMappings);
            $source->setAttribute('latest_sync_run', $latestSync);
            $source->setAttribute('sync_frequency', $this->resolveSyncFrequency($source));
            $source->setAttribute('sync_frequency_label', $this->resolveSyncFrequencyLabel($source));
            $source->setAttribute('next_sync_at', $this->resolveNextPlannedSync($source));
            $source->setAttribute('next_sync_label', $this->resolveNextPlannedSyncLabel($source));
            $source->setAttribute('auto_build_enabled', $this->resolveAutoBuildEnabled($source));
            $source->setAttribute('auto_project_enabled', $this->resolveAutoProjectEnabled($source));
            $source->setAttribute('sync_status_label', $this->resolveSyncStatusLabel($latestSync));
            $source->setAttribute('sync_status_badge', $this->resolveSyncStatusBadge($latestSync));
            $source->setAttribute('sync_summary', $this->resolveSyncSummary($latestSync));
            $source->setAttribute('lifecycle_state', $lifecycleState);
            $source->setAttribute('is_archived', $lifecycleState === 'archived');
            $source->setAttribute('dependency_summary', $dependencySummary);
            $source->setAttribute('can_hard_delete', $dependencySummary['total'] === 0);
            $buildPending = (int) ($dependencySummary['raw_products'] ?? 0) > 0
                && (int) ($dependencySummary['standard_products'] ?? 0) === 0;
            $projectionPending = (int) ($dependencySummary['standard_products'] ?? 0) > 0
                && (int) ($dependencySummary['tenant_catalog_products'] ?? 0) === 0;
            $source->setAttribute('build_pending', $buildPending);
            $source->setAttribute('projection_pending', $projectionPending);
            $source->setAttribute('quality_alerts', array_values(array_filter([
                $buildPending
                    ? sprintf(
                        '%d staging kaydı var, standard product 0. Build bekliyor.',
                        (int) ($dependencySummary['raw_products'] ?? 0)
                    )
                    : null,
                $projectionPending
                    ? sprintf(
                        '%d standard product var, tenant projection 0. Projection bekliyor.',
                        (int) ($dependencySummary['standard_products'] ?? 0)
                    )
                    : null,
            ])));
            $source->setAttribute('status_label', $lifecycleState === 'archived'
                ? 'Arşiv'
                : ($source->status === 'active' ? 'Aktif' : ($source->status === 'inactive' ? 'Pasif' : 'Hatalı')));
            $source->setAttribute('status_badge', $lifecycleState === 'archived'
                ? 'amber'
                : ($source->status === 'active' ? 'green' : ($source->status === 'inactive' ? 'gray' : 'red')));
            $source->setAttribute('sort_key', sprintf(
                '%d-%s-%s',
                $isTempProfile ? 1 : 0,
                Str::lower($source->supplier->name ?? ''),
                Str::lower($source->source_name)
            ));

            return $source;
        });

        $allSources = $sources->sortBy('sort_key')->values();
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

        return view('super-admin.product-data-hub.sources.index', compact('sources', 'stats', 'showTemp', 'activeFilter'));
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
        $profileKey = $this->resolveProfileKey($supplier, $validated);
        $profileConfig = config("prodelya_product_data_hub.supplier_profiles.{$profileKey}", []);
        $storedSourceType = $this->normalizeStoredSourceType($validated['source_type']);
        $format = $this->resolveFormat($validated['source_type'], $validated['format'] ?? null);

        $config = $this->buildConfig($validated, $profileKey, $profileConfig, $format);

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

        return view('super-admin.product-data-hub.sources.edit', compact('source', 'suppliers', 'sourceProfiles', 'profileTemplates', 'selectedSourceType'));
    }

    public function update(Request $request, SupplierSource $source): RedirectResponse
    {
        $validated = $this->validateSource($request, true);
        $supplier = Supplier::findOrFail($validated['supplier_id']);
        $profileKey = $this->resolveProfileKey($supplier, $validated);
        $profileConfig = config("prodelya_product_data_hub.supplier_profiles.{$profileKey}", []);
        $storedSourceType = $this->normalizeStoredSourceType($validated['source_type']);
        $format = $this->resolveFormat($validated['source_type'], $validated['format'] ?? null);

        $config = $this->buildConfig($validated, $profileKey, $profileConfig, $format, $source->config ?? []);

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
            'sourceSummary'
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
                in_array($run->status, ['success', 'partial'], true) ? 'success' : 'error',
                $run->status === 'failed'
                    ? 'Senkron başlatılamadı: ' . ($run->error_message ?: 'Kaynak verisi okunamadı.')
                    : (($request->boolean('dry_run')
                            ? 'Bu işlem test çalıştırmasıdır, ürün/stok/fiyat verisi değiştirilmedi. '
                            : 'İşlem tamamlandı: ')
                        . "Okunan {$run->records_read}, yeni {$run->products_created}, güncellenen {$run->products_updated}, değişmeyen {$run->products_unchanged}, XML’den çıkan {$run->products_missing_from_feed}, fiyat değişen {$run->price_changed_count}, stok değişen {$run->stock_changed_count}, görsel değişen {$run->image_changed_count}, hata {$run->error_count}.")
            );
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
                ->latest('id')
                ->get()
            : collect();

        $sources = $visibleSources;

        return view('super-admin.product-data-hub.sources.sync-reports', compact('runs', 'selectedRun', 'changes', 'sources', 'sourceId', 'changeType'));
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

        return $validated;
    }

    private function buildConfig(array $validated, string $profileKey, array $profileConfig, ?string $format, array $existingConfig = []): array
    {
        return [
            'ui_source_type' => $validated['source_type'],
            'format' => $format,
            'profile_key' => $profileKey,
            'source_file_path' => $validated['source_file_path'] ?? null,
            'product_node_path' => $validated['product_node_path'] ?? null,
            'items_path' => $validated['items_path'] ?? null,
            'supplier_prefix' => $validated['supplier_prefix'] ?? ($profileConfig['supplier_code_prefix'] ?? null),
            'generated_code_template' => $validated['generated_code_template'] ?? ($profileConfig['generated_code_template'] ?? null),
            'generated_variant_code_template' => $validated['generated_variant_code_template'] ?? ($profileConfig['generated_variant_code_template'] ?? null),
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
            'username' => $validated['username'] ?? null,
            'password' => filled($validated['password'] ?? null) ? $validated['password'] : ($existingConfig['password'] ?? null),
            'api_key' => filled($validated['api_key'] ?? null) ? $validated['api_key'] : ($existingConfig['api_key'] ?? null),
            'auth_username' => $validated['auth_username'] ?? ($validated['username'] ?? null),
            'auth_password' => filled($validated['auth_password'] ?? null) ? $validated['auth_password'] : ($existingConfig['auth_password'] ?? ($existingConfig['password'] ?? null)),
            'auth_token' => filled($validated['auth_token'] ?? null) ? $validated['auth_token'] : ($existingConfig['auth_token'] ?? null),
            'api_key_name' => $validated['api_key_name'] ?? ($existingConfig['api_key_name'] ?? null),
            'api_key_value' => filled($validated['api_key_value'] ?? null) ? $validated['api_key_value'] : ($existingConfig['api_key_value'] ?? null),
            'request_headers' => $validated['request_headers'] ?? null,
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
                'source_type' => 'xml',
                'features' => [
                    'variants' => in_array($profile['product_model'] ?? '', ['parent_nested_variant', 'record_variant_row', 'flat_group_variant'], true),
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
                    'Varyant desteği' => in_array($profile['product_model'] ?? '', ['parent_nested_variant', 'record_variant_row', 'flat_group_variant'], true) ? 'Var' : 'Yok',
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
            'profile_key' => $templateSource->config['profile_key'] ?? $this->resolveProfileKey($templateSource->supplier, $templateSource->config ?? []),
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
            'auth_username' => $templateSource->config['auth_username'] ?? null,
            'api_key_name' => $templateSource->config['api_key_name'] ?? 'X-API-KEY',
            'request_headers' => is_array($templateSource->config['request_headers'] ?? null)
                ? json_encode($templateSource->config['request_headers'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : ($templateSource->config['request_headers'] ?? null),
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

    private function resolveProfileKey(Supplier $supplier, array $input): string
    {
        $profileKey = $input['profile_key'] ?? $input['config']['profile_key'] ?? null;

        if (filled($profileKey)) {
            if ($profileKey === 'CUSTOM') {
                return 'CUSTOM';
            }

            return (string) $profileKey;
        }

        return config('prodelya_product_data_hub.supplier_profiles.' . $supplier->code)
            ? $supplier->code
            : Str::upper(Str::slug($supplier->code ?: $supplier->name, '-'));
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

        return match ($run->status) {
            'success' => 'Başarılı',
            'partial' => 'Kısmi',
            'failed' => 'Hatalı',
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

        return match ($run->status) {
            'success' => 'green',
            'partial' => 'amber',
            'failed' => 'red',
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
            $reviewMessages[] = 'Varyant grup kodu veya varyant kodu eksik. Parent/variant ilişkisi kontrol edilmelidir.';
        }

        if ($profileKey === 'ILPEN' && $isVariant && (bool) ($row['image_fallback_used'] ?? false) && filled($row['variant_image_url'] ?? null)) {
            $infoMessages[] = 'Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.';
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
