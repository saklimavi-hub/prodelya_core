<?php

namespace App\Services\SuperAdmin;

use App\Models\AuditLog;
use App\Models\FeedSyncLog;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSignupRequest;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Services\ProductDataHub\ProductHubOperationFlowService;
use App\Services\SuperAdmin\NotificationReadinessService;
use App\Services\SuperAdminDashboardSummaryService;
use App\Services\SuperAdminOperationAuditService;
use App\Services\TenantAccessService;
use App\Services\TenantOnboardingStatusService;
use App\Services\TenantSubscriptionStatusService;
use App\Services\TenantUsageService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SuperAdminOperationDashboardService
{
    public function __construct(
        private readonly SuperAdminDashboardSummaryService $summaryService,
        private readonly TenantOnboardingStatusService $onboardingStatusService,
        private readonly TenantSubscriptionStatusService $subscriptionStatusService,
        private readonly TenantUsageService $tenantUsageService,
        private readonly TenantAccessService $tenantAccessService,
        private readonly TenantSignupRequestReadinessService $signupRequestReadinessService,
        private readonly TenantUpgradeRequestReviewService $tenantUpgradeRequestReviewService,
        private readonly ProductHubOperationFlowService $productHubOperationFlowService,
        private readonly ProductDataHubLiveReadinessService $productDataHubLiveReadinessService,
        private readonly SuperAdminOperationAuditService $operationAuditService,
        private readonly SuperAdminSystemHealthService $systemHealthService,
        private readonly ProductionEnvironmentReadinessService $productionEnvironmentReadinessService,
        private readonly NotificationReadinessService $notificationReadinessService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboardContext(): array
    {
        $fallback = $this->fallbackContext();
        $rows = $this->safeTenantRows();
        $signup = $this->safeSection('signup_funnel', fn (): array => $this->buildSignupFunnelSection(), $fallback['signup_funnel']);
        $upgrade = $this->safeSection('upgrade_requests', fn (): array => $this->buildUpgradeRequestsSection(), $fallback['upgrade_requests']);
        $productDataHub = $this->safeSection('product_data_hub', fn (): array => $this->buildProductDataHubSection(), $fallback['product_data_hub']);
        $systemHealth = $this->safeSection('system_health', fn (): array => $this->buildSystemHealthSection(), $fallback['system_health']);

        return [
            'kpis' => $this->safeSection('kpis', fn (): array => $this->buildKpisSection($rows, $signup, $upgrade), $fallback['kpis']),
            'action_queue' => $this->safeSection('action_queue', fn (): array => $this->buildActionQueueSection($rows, $signup, $upgrade, $productDataHub, $systemHealth), $fallback['action_queue']),
            'tenant_readiness' => $this->safeSection('tenant_readiness', fn (): array => $this->buildTenantReadinessSection($rows), $fallback['tenant_readiness']),
            'signup_funnel' => $signup,
            'upgrade_requests' => $upgrade,
            'product_data_hub' => $productDataHub,
            'system_health' => $systemHealth,
            'recent_operations' => $this->safeSection('recent_operations', fn (): array => $this->buildRecentOperationsSection(), $fallback['recent_operations']),
            'security_warnings' => $this->safeSection('security_warnings', fn (): array => $this->buildSecurityWarningsSection(), $fallback['security_warnings']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fallbackContext(): array
    {
        return [
            'kpis' => [
                'cards' => [],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'action_queue' => [
                'critical' => [],
                'today' => [],
                'technical' => [],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'tenant_readiness' => [
                'counts' => $this->emptyTenantReadinessCounts(),
                'rows' => [],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'signup_funnel' => [
                'counts' => $this->emptySignupCounts(),
                'rows' => [],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'upgrade_requests' => [
                'counts' => $this->emptyUpgradeCounts(),
                'rows' => [],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'product_data_hub' => [
                'counts' => $this->emptyProductDataHubCounts(),
                'rows' => [],
                'warnings' => ['Bu bölüm için veri hazırlanamadı.'],
                'message' => 'Bu bölüm için veri hazırlanamadı.',
                'live_readiness' => [
                    'checked_at' => now()->format('d.m.Y H:i'),
                    'sources' => [],
                    'counts' => [
                        'active_sources' => 0,
                        'preview_ready' => 0,
                        'preview_manual' => 0,
                        'mapping_ready' => 0,
                        'mapping_missing' => 0,
                        'supplier_access_followup' => 0,
                    ],
                    'truth_smoke' => [
                        'status' => 'unknown',
                        'status_label' => 'Bilinmiyor',
                        'rows' => [],
                        'warnings' => ['Bu bölüm için veri hazırlanamadı.'],
                    ],
                    'risk_queue' => [
                        'review_pending' => 0,
                        'category_waiting' => 0,
                        'price_control' => 0,
                        'stock_control' => 0,
                        'projection_stale' => 0,
                        'sync_errors' => 0,
                        'projection_pending' => 0,
                        'tenant_impact_risk' => 0,
                        'source_error' => 0,
                    ],
                    'warnings' => ['Bu bölüm için veri hazırlanamadı.'],
                ],
            ],
            'system_health' => [
                'queue_worker' => $this->unknownHealthItem('queue_worker', 'Kuyruk Çalışanı'),
                'scheduler' => $this->unknownHealthItem('scheduler', 'Zamanlayıcı'),
                'failed_jobs' => $this->unknownHealthItem('failed_jobs', 'Başarısız İşler'),
                'backup' => $this->unknownHealthItem('backup', 'Son Yedekleme'),
                'disk_usage' => $this->unknownHealthItem('disk_usage', 'Disk Kullanımı'),
                'database' => $this->unknownHealthItem('database', 'Veritabanı'),
                'cache' => $this->unknownHealthItem('cache', 'Önbellek / Redis'),
                'storage_link' => $this->unknownHealthItem('storage_link', 'Depolama Bağlantısı'),
                'log_errors' => $this->unknownHealthItem('log_errors', 'Log Hataları'),
                'php_compatibility' => $this->unknownHealthItem('php_compatibility', 'PHP Uyumu'),
                'message' => 'Bu bölüm için veri hazırlanamadı.',
            ],
            'recent_operations' => [
                [
                    'event_title' => 'Veri hazırlanamadı',
                    'tenant' => 'Sistem',
                    'subject' => 'Sistem',
                    'actor' => 'Sistem',
                    'occurred_at' => now()->format('d.m.Y H:i'),
                    'tone' => 'amber',
                    'summary' => 'Bu bölüm için veri hazırlanamadı.',
                    'route' => null,
                ],
            ],
            'security_warnings' => [
                [
                    'key' => 'dashboard_section_fallback',
                    'title' => 'Operasyon paneli kısmi fallback modunda açıldı',
                    'tone' => 'warning',
                    'description' => 'Bir veya daha fazla bölüm için veri hazırlanamadı.',
                ],
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $signup
     * @param array<string, mixed> $upgrade
     * @return array<string, mixed>
     */
    protected function buildKpisSection(Collection $rows, array $signup, array $upgrade): array
    {
        $counts = $this->tenantReadinessCounts($rows);

        return [
            'cards' => [
                $this->kpiCard('total_tenants', 'Toplam Abone Firma', $counts['total'], 'Genel görünüm', 'slate'),
                $this->kpiCard('active_tenants', 'Aktif Abone Firma', $counts['active'], 'Canlı erişime açık', 'green'),
                $this->kpiCard('trial_tenants', 'Deneme Sürecinde', $counts['trial'], 'Süre takibi gerekli', 'blue'),
                $this->kpiCard('restricted_tenants', 'Süresi Dolmuş / Askıda', $counts['restricted'], 'Aksiyon bekliyor', 'red'),
                $this->kpiCard('ready_tenants', 'Canlıya Hazır', $counts['ready'], 'Hazır Abone Firmalar', 'green'),
                $this->kpiCard('warning_tenants', 'Kontrol Gerektiren', $counts['warning'], 'Eksik veya uyarılı Abone Firmalar', 'amber'),
                $this->kpiCard('open_signups', 'Açık Başvuru', (int) data_get($signup, 'counts.open', 0), 'Satış takibi gerektirir', 'blue'),
                $this->kpiCard('open_requests', 'Açık Talep', (int) data_get($upgrade, 'counts.open', 0), 'Onay / uygulama kuyruğu', 'amber'),
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    protected function buildTenantReadinessSection(Collection $rows): array
    {
        $counts = $this->tenantReadinessCounts($rows);

        $list = $rows
            ->map(function (array $row): array {
                /** @var TenantAccount $tenant */
                $tenant = $row['tenant'];

                return [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'package' => $row['package_label'],
                    'subscription_status' => $row['subscription']['label'],
                    'subscription_status_key' => $row['subscription']['status'],
                    'readiness_status' => $this->tenantReadinessStatus($row),
                    'missing_badges' => $this->tenantMissingBadges($row),
                    'last_activity' => $this->formatDateTime($row['last_activity_at'] ?? null),
                    'detail_route' => route('admin.super.tenants.show', $tenant),
                ];
            })
            ->sortBy([
                fn (array $item) => match ($item['readiness_status']) {
                    'blocked' => 0,
                    'warning' => 1,
                    default => 2,
                },
                fn (array $item) => $item['name'],
            ])
            ->take(8)
            ->values()
            ->all();

        return [
            'counts' => $counts,
            'rows' => $list,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSignupFunnelSection(): array
    {
        $baseQuery = TenantSignupRequest::query()->with(['requestedPackage', 'convertedTenant'])->latest();
        $previewOpenedIds = AuditLog::query()
            ->where('entity_type', 'tenant_signup_request')
            ->where('action', 'signup_request_conversion_preview_opened')
            ->distinct('entity_id')
            ->pluck('entity_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
        $previewOpenedCount = count($previewOpenedIds);
        $previewOpenedIdMap = array_fill_keys($previewOpenedIds, true);

        $rows = $baseQuery->limit(8)->get()->map(function (TenantSignupRequest $request) use ($previewOpenedIdMap): array {
            $readiness = $this->signupRequestReadinessService->evaluate($request);
            $hasPreviewOpened = isset($previewOpenedIdMap[$request->id]);
            $convertedTenant = $request->convertedTenant;
            $onboardingIncomplete = false;

            if ($request->status === TenantSignupRequest::STATUS_CONVERTED && $convertedTenant) {
                $onboarding = $this->onboardingStatusService->forTenant($convertedTenant);
                $onboardingIncomplete = in_array(false, [
                    $onboarding['has_package'] ?? false,
                    $onboarding['has_owner'] ?? false,
                    $onboarding['has_company_profile'] ?? false,
                    $onboarding['has_print_settings'] ?? false,
                    $onboarding['has_tenant_settings_defaults'] ?? false,
                    $onboarding['has_smtp_config'] ?? false,
                ], true);
            }

            $actionRoute = route('admin.super.signup-requests.show', $request);
            $actionLabel = 'Başvuruyu İncele';
            if ($request->status === TenantSignupRequest::STATUS_CONVERTED && $request->converted_tenant_account_id) {
                $actionRoute = route('admin.super.tenants.show', $request->converted_tenant_account_id);
                $actionLabel = $onboardingIncomplete ? 'Onboarding Hazırlığını Gör' : 'Abone Firma Aç';
            } elseif ($hasPreviewOpened || $request->status === TenantSignupRequest::STATUS_CONTACTED) {
                $actionRoute = route('admin.super.signup-requests.conversion-preview', $request);
                $actionLabel = 'Dönüşüm Önizlemesi';
            }

            $queueLabel = match (true) {
                $request->status === TenantSignupRequest::STATUS_NEW => 'Yeni Başvuru',
                $request->status === TenantSignupRequest::STATUS_CONTACTED && !$hasPreviewOpened => 'Dönüşüm Bekliyor',
                $hasPreviewOpened && $request->status !== TenantSignupRequest::STATUS_CONVERTED => 'Önizleme Açıldı',
                $request->status === TenantSignupRequest::STATUS_CONVERTED && $onboardingIncomplete => 'Onboarding Eksik',
                $request->status === TenantSignupRequest::STATUS_CONVERTED => 'Dönüştü',
                $request->status === TenantSignupRequest::STATUS_REJECTED => 'Reddedildi',
                $request->status === TenantSignupRequest::STATUS_ARCHIVED => 'Arşivlendi',
                default => 'Kontrol Gerekir',
            };

            return [
                'request_id' => $request->id,
                'company_name' => $request->company_name,
                'request_type' => $readiness['request_type_label'],
                'package' => $request->requestedPackage?->name ?? ($request->requested_package_key ?: 'Belirtilmedi'),
                'readiness_status' => $readiness['severity'],
                'readiness_label' => $readiness['summary_badge']['label'] ?? 'Kontrol Gerekir',
                'status' => TenantSignupRequest::statusOptions()[$request->status] ?? $request->status,
                'status_key' => $request->status,
                'queue_label' => $queueLabel,
                'last_action_at' => $this->formatDateTime($request->updated_at),
                'action_route' => $actionRoute,
                'action_label' => $actionLabel,
                'is_actionable' => filled($actionRoute),
                'priority' => $this->signupQueuePriority($request, $hasPreviewOpened, $onboardingIncomplete),
                'has_preview_opened' => $hasPreviewOpened,
                'onboarding_incomplete' => $onboardingIncomplete,
            ];
        })->sortBy([
            fn (array $item) => $item['priority'] ?? 999,
            fn (array $item) => $item['company_name'],
        ])->values()->all();

        $convertedOnboardingIncomplete = TenantSignupRequest::query()
            ->with('convertedTenant')
            ->where('status', TenantSignupRequest::STATUS_CONVERTED)
            ->get()
            ->filter(function (TenantSignupRequest $request): bool {
                if (!$request->convertedTenant) {
                    return false;
                }

                $onboarding = $this->onboardingStatusService->forTenant($request->convertedTenant);

                return in_array(false, [
                    $onboarding['has_package'] ?? false,
                    $onboarding['has_owner'] ?? false,
                    $onboarding['has_company_profile'] ?? false,
                    $onboarding['has_print_settings'] ?? false,
                    $onboarding['has_tenant_settings_defaults'] ?? false,
                    $onboarding['has_smtp_config'] ?? false,
                ], true);
            })
            ->count();

        $counts = [
            'new' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_NEW)->count(),
            'contacted' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_CONTACTED)->count(),
            'preview_opened' => $previewOpenedCount,
            'converted' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_CONVERTED)->count(),
            'rejected' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_REJECTED)->count(),
            'archived' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_ARCHIVED)->count(),
            'trial' => TenantSignupRequest::query()->where('request_type', TenantSignupRequest::TYPE_TRIAL)->count(),
            'demo' => TenantSignupRequest::query()->where('request_type', TenantSignupRequest::TYPE_DEMO)->count(),
            'last_7_days' => TenantSignupRequest::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'last_30_days' => TenantSignupRequest::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'contacted_waiting_conversion' => TenantSignupRequest::query()->where('status', TenantSignupRequest::STATUS_CONTACTED)->count(),
            'preview_pending_conversion' => TenantSignupRequest::query()
                ->whereIn('id', $previewOpenedIds)
                ->where('status', '!=', TenantSignupRequest::STATUS_CONVERTED)
                ->count(),
            'converted_onboarding_incomplete' => $convertedOnboardingIncomplete,
        ];
        $counts['open'] = $counts['new'] + $counts['contacted'];

        return [
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildUpgradeRequestsSection(): array
    {
        $applyBlockedIds = AuditLog::query()
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('action', 'tenant_upgrade_request_apply_blocked')
            ->distinct('entity_id')
            ->pluck('entity_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
        $applyFailedIds = AuditLog::query()
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('action', 'tenant_upgrade_request_apply_failed')
            ->distinct('entity_id')
            ->pluck('entity_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
        $applyBlockedMap = array_fill_keys($applyBlockedIds, true);
        $applyFailedMap = array_fill_keys($applyFailedIds, true);
        $applyBlockedOrFailed = count(array_unique(array_merge($applyBlockedIds, $applyFailedIds)));

        $rows = TenantUpgradeRequest::query()
            ->with(['tenantAccount', 'requestedBy'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function (TenantUpgradeRequest $request) use ($applyBlockedMap, $applyFailedMap): array {
                $impact = $this->tenantUpgradeRequestReviewService->buildImpactPreview($request);
                $summary = collect(array_merge($impact['warnings'] ?? [], $impact['impacts'] ?? []))
                    ->filter()
                    ->first() ?: 'Detay ekranda incelenmeli.';
                $hasApplyBlocked = isset($applyBlockedMap[$request->id]);
                $hasApplyFailed = isset($applyFailedMap[$request->id]);

                $queueBadge = match (true) {
                    $hasApplyFailed => 'Uygulama Hatası',
                    $hasApplyBlocked => 'Güvenlik Kontrolüne Takıldı',
                    $request->status === TenantUpgradeRequest::STATUS_APPROVED => 'Uygulama Bekliyor',
                    $request->status === TenantUpgradeRequest::STATUS_PENDING => 'Bekleyen',
                    $request->status === TenantUpgradeRequest::STATUS_IN_REVIEW => 'İncelemede',
                    $request->status === TenantUpgradeRequest::STATUS_APPLIED => 'Uygulandı',
                    default => $request->statusLabel(),
                };
                $queueTone = match (true) {
                    $hasApplyFailed => 'red',
                    $hasApplyBlocked => 'amber',
                    $request->status === TenantUpgradeRequest::STATUS_APPROVED => 'amber',
                    default => $request->statusTone(),
                };
                $actionLabel = match (true) {
                    $hasApplyFailed || $hasApplyBlocked => 'Detaya Git',
                    $request->status === TenantUpgradeRequest::STATUS_APPROVED => 'Detaya Git',
                    default => 'İncele',
                };

                return [
                    'request_id' => $request->id,
                    'tenant' => $request->tenantAccount?->name ?? 'Kayıt bulunamadı',
                    'request_type_label' => $request->requestTypeLabel(),
                    'status_label' => $request->statusLabel(),
                    'status_tone' => $request->statusTone(),
                    'status_key' => $request->status,
                    'risk_summary' => Str::limit((string) $summary, 120),
                    'last_action' => $this->formatDateTime($request->applied_at ?? $request->reviewed_at ?? $request->updated_at),
                    'action_route' => route('admin.super.upgrade-requests.show', $request),
                    'action_label' => $actionLabel,
                    'is_actionable' => true,
                    'queue_badge' => $queueBadge,
                    'queue_badge_tone' => $queueTone,
                    'has_apply_blocked' => $hasApplyBlocked,
                    'has_apply_failed' => $hasApplyFailed,
                    'priority' => $this->upgradeQueuePriority($request, $hasApplyBlocked, $hasApplyFailed),
                    'show_route' => route('admin.super.upgrade-requests.show', $request),
                ];
            })
            ->sortBy([
                fn (array $item) => $item['priority'] ?? 999,
                fn (array $item) => $item['tenant'],
            ])
            ->values()
            ->all();

        $counts = [
            'pending' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_PENDING)->count(),
            'in_review' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_IN_REVIEW)->count(),
            'approved' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_APPROVED)->count(),
            'applied' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_APPLIED)->count(),
            'rejected' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_REJECTED)->count(),
            'cancelled' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_CANCELLED)->count(),
            'approved_but_unapplied' => TenantUpgradeRequest::query()->where('status', TenantUpgradeRequest::STATUS_APPROVED)->count(),
            'apply_blocked' => count($applyBlockedIds),
            'apply_failed' => count($applyFailedIds),
            'apply_blocked_or_failed' => $applyBlockedOrFailed,
            'by_type' => collect(TenantUpgradeRequest::requestTypeOptions())
                ->mapWithKeys(fn (string $label, string $key) => [
                    $key => TenantUpgradeRequest::query()->where('request_type', $key)->count(),
                ])->all(),
        ];
        $counts['open'] = $counts['pending'] + $counts['in_review'] + $counts['approved'];

        return [
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductDataHubSection(): array
    {
        try {
            $overview = $this->productHubOperationFlowService->buildOverview();
            $visibleSources = SupplierSource::query()
                ->with('supplier')
                ->visibleInProductDataHub()
                ->get();
            $visibleSourceIds = $visibleSources->pluck('id');
            $latestRunIds = ProductDataHubSyncRun::query()
                ->when(
                    $visibleSourceIds->isNotEmpty(),
                    fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->selectRaw('MAX(id) as id')
                ->groupBy('supplier_source_id')
                ->pluck('id');
            $latestRuns = ProductDataHubSyncRun::query()
                ->when(
                    $latestRunIds->isNotEmpty(),
                    fn ($query) => $query->whereIn('id', $latestRunIds),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->get()
                ->keyBy('supplier_source_id');

            $pendingCategoryMappings = SupplierCategoryMapping::query()
                ->when(
                    $visibleSourceIds->isNotEmpty(),
                    fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->where(function ($query) {
                    $query->whereNull('standard_category_id')
                        ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict']);
                })
                ->count();

            $syncErrors = $visibleSources->where('status', 'error')->count()
                + FeedSyncLog::query()
                    ->when(
                        $visibleSourceIds->isNotEmpty(),
                        fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                        fn ($query) => $query->whereRaw('1 = 0')
                    )
                    ->where('status', 'failed')
                    ->count();

            $supplierAccessFollowup = max(
                (int) TenantSupplierAccess::query()
                    ->active()
                    ->whereHas('supplier.sources', fn ($query) => $query->visibleInProductDataHub())
                    ->count(),
                TenantUpgradeRequest::query()
                    ->where('request_type', TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS)
                    ->whereIn('status', [TenantUpgradeRequest::STATUS_APPROVED, TenantUpgradeRequest::STATUS_APPLIED])
                    ->count()
            );

            $tenantImpactCount = TenantSupplierAccess::query()->active()->distinct('supplier_id')->count('supplier_id');

            $supplierRows = $visibleSources
                ->groupBy('supplier_id')
                ->map(function (Collection $sources, int|string $supplierId) use ($latestRuns): array {
                    /** @var Supplier|null $supplier */
                    $supplier = $sources->first()?->supplier;
                    $latestRun = $sources
                        ->map(fn (SupplierSource $source) => $latestRuns->get($source->id))
                        ->filter()
                        ->sortByDesc('finished_at')
                        ->first();
                    $reviewCount = (int) $sources->sum(function (SupplierSource $source) {
                        return SupplierCategoryMapping::query()
                            ->where('supplier_source_id', $source->id)
                            ->where(function ($query) {
                                $query->whereNull('standard_category_id')
                                    ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict']);
                            })
                            ->count();
                    });

                    $status = 'Güncel';
                    if ($sources->contains(fn (SupplierSource $source) => filled($source->last_error) || $source->status === 'error')) {
                        $status = 'Senkronizasyon Hatası';
                    } elseif ($reviewCount > 0) {
                        $status = 'İnceleme Var';
                    } elseif ($latestRun && in_array($latestRun->normalizedStatus(), [ProductDataHubSyncRun::STATUS_FAILED, ProductDataHubSyncRun::STATUS_STUCK], true)) {
                        $status = 'Kontrol Gerekir';
                    }

                    return [
                        'supplier_name' => $supplier?->name ?? ('Tedarikçi #' . $supplierId),
                        'last_sync' => $this->formatDateTime($latestRun?->finished_at ?? $sources->max('last_sync_at')),
                        'price_stock_status' => $status,
                        'status_tone' => match ($status) {
                            'Senkronizasyon Hatası' => 'red',
                            'İnceleme Var', 'Kontrol Gerekir', 'Kataloğa Yansıtma Bekliyor' => 'amber',
                            default => 'green',
                        },
                        'review_count' => $reviewCount,
                        'tenant_impact' => TenantSupplierAccess::query()->active()->where('supplier_id', $supplierId)->count(),
                        'action_label' => match ($status) {
                            'Senkronizasyon Hatası' => 'Kaynağı İncele',
                            'İnceleme Var' => 'İncelemeyi Aç',
                            default => 'Detaya Git',
                        },
                        'is_actionable' => true,
                        'route' => route('admin.super.product-data-hub.index'),
                    ];
                })
                ->sortByDesc('review_count')
                ->take(6)
                ->values()
                ->all();

            return [
                'counts' => [
                    'active_supplier_sources' => $visibleSources->count(),
                    'last_sync_successful' => $latestRuns->filter(fn (ProductDataHubSyncRun $run) => in_array($run->normalizedStatus(), [
                        ProductDataHubSyncRun::STATUS_COMPLETED,
                        ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS,
                    ], true))->count(),
                    'price_stock_current' => (int) data_get($overview, 'counts.auto_updated', 0),
                    'review_pending' => max((int) data_get($overview, 'counts.review_required', 0), $pendingCategoryMappings),
                    'projection_stale' => (int) data_get($overview, 'counts.projection_issues', 0),
                    'sync_errors' => $syncErrors,
                    'supplier_access_followup' => $supplierAccessFollowup,
                    'tenant_impact_sources' => $tenantImpactCount,
                ],
                'rows' => $supplierRows,
                'warnings' => $this->productDataHubDashboardWarnings($overview, $syncErrors, $pendingCategoryMappings, $supplierAccessFollowup),
                'live_readiness' => [
                    'checked_at' => now()->format('d.m.Y H:i'),
                    'sources' => [],
                    'counts' => [
                        'active_sources' => $visibleSources->count(),
                        'preview_ready' => 0,
                        'preview_manual' => 0,
                        'mapping_ready' => 0,
                        'mapping_missing' => 0,
                        'supplier_access_followup' => $supplierAccessFollowup,
                    ],
                    'truth_smoke' => [
                        'status' => 'unknown',
                        'status_label' => 'Bilinmiyor',
                        'rows' => [],
                        'warnings' => ['Dashboard isteğinde read-only canlı preview ve truth smoke çalıştırılmaz. Detay ekranından manuel doğrulama yapın.'],
                    ],
                    'risk_queue' => [
                        'review_pending' => max((int) data_get($overview, 'counts.review_required', 0), $pendingCategoryMappings),
                        'category_waiting' => $pendingCategoryMappings,
                        'price_control' => (int) data_get($overview, 'counts.price_changed', 0),
                        'stock_control' => (int) data_get($overview, 'counts.stock_changed', 0),
                        'projection_stale' => (int) data_get($overview, 'counts.projection_issues', 0),
                        'sync_errors' => $syncErrors,
                        'projection_pending' => (int) data_get($overview, 'counts.tenant_output_blocks', 0),
                        'tenant_impact_risk' => $tenantImpactCount,
                        'source_error' => $visibleSources->where('status', 'error')->count(),
                    ],
                    'warnings' => ['Dashboard isteğinde Product Data Hub canlı preview yerine hafif özet kullanıldı.'],
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                ...$this->fallbackContext()['product_data_hub'],
                'warnings' => ['Product Data Hub özeti hazırlanamadı: ' . $this->safeExceptionMessage($exception->getMessage())],
            ];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildSystemHealthSection(): array
    {
        return $this->systemHealthService->buildHealthContext();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildRecentOperationsSection(): array
    {
        return $this->operationAuditService->recentOperations(12);
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildSecurityWarningsSection(): array
    {
        $warnings = [
            [
                'key' => 'temporary_password_visibility',
                'title' => 'Geçici Panel Yetkilisi şifresi canlı ekranlarda gösterilmemeli',
                'tone' => $this->tenantShowHasTemporaryPasswordWarning() ? 'warning' : 'info',
                'description' => $this->tenantShowHasTemporaryPasswordWarning()
                    ? 'Abone Firma detay ekranında geçici Panel Yetkilisi şifresi görünürlüğü tespit edildi.'
                    : 'Bu risk için repo taramasında aktif görünür alan bulunmadı.',
            ],
            [
                'key' => 'secrets_masking',
                'title' => 'SMTP, API ve gizli alanlar maskeli kalmalı',
                'tone' => 'info',
                'description' => 'Operasyon paneli verisi hassas bilgi taşımaz; ayar ekranlarında maskeli görünürlük korunmalı.',
            ],
            [
                'key' => 'central_access',
                'title' => 'Super Admin yönetim adresleri merkezi erişim korumasında kalmalı',
                'tone' => 'info',
                'description' => 'Merkezi erişim koruması ve Super Admin guard’ları uçtan uca güvenlik testleriyle korunmalı.',
            ],
            [
                'key' => 'tenant_isolation',
                'title' => 'Abone firma izolasyonu uçtan uca testlerle doğrulanmalı',
                'tone' => 'info',
                'description' => 'Başvuru, talep ve erişim yüzeylerinde tenant isolation regresyonları düzenli çalışmalı.',
            ],
            [
                'key' => 'scheduler_backup_visibility',
                'title' => 'Yedekleme ve zamanlayıcı görünürlüğü tamamlanmalı',
                'tone' => 'warning',
                'description' => 'Teknik sağlık alanlarında çalışma zamanı görünürlüğü henüz kısmi sinyal seviyesinde.',
            ],
            [
                'key' => 'public_file_visibility',
                'title' => 'Public dosya erişimlerinde görünürlük ve erişim kontrolleri korunmalı',
                'tone' => 'info',
                'description' => 'Müşteri görünürlüğü olan dosyalar ve takip bağlantıları mevcut güvenlik testleriyle doğrulanmaya devam etmelidir.',
            ],
            [
                'key' => 'final_production_smoke',
                'title' => 'Canlıya Çıkış final smoke planı uygulanmalı',
                'tone' => 'warning',
                'description' => 'Merkezi alan adı, Abone Firma panel adresi, Product Data Hub, Talep Merkezi ve Sistem Sağlığı için son manuel smoke adımları canlıya çıkmadan tamamlanmalıdır.',
            ],
        ];

        foreach ($this->productionEnvironmentReadinessService->actionableWarnings() as $check) {
            $warnings[] = [
                'key' => 'production_env_' . $check['key'],
                'title' => $check['label'],
                'tone' => $check['status'] === 'blocked' ? 'warning' : 'info',
                'description' => $check['description'],
            ];
        }

        foreach ($this->notificationReadinessService->actionableWarnings() as $check) {
            $warnings[] = $check;
        }

        return $warnings;
    }

    protected function safeTenantRows(): Collection
    {
        return $this->measureSection('tenant_rows', function (): Collection {
            return $this->summaryService->tenantRows();
        }, collect());
    }

    /**
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    protected function safeSection(string $key, callable $callback, array $fallback): array
    {
        return $this->measureSection($key, function () use ($callback, $key, $fallback) {
            try {
                return $callback();
            } catch (\Throwable $exception) {
                $this->logSectionFailure($key, $exception);

                return $this->mergeFallbackMessage($key, $fallback);
            }
        }, $fallback);
    }

    protected function measureSection(string $key, callable $callback, mixed $fallback): mixed
    {
        $startedAt = microtime(true);

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->logSectionFailure($key, $exception);

            return $fallback;
        } finally {
            if ($this->shouldWriteDebugTiming()) {
                Log::info('super_admin_operation_dashboard.section_timing', [
                    'section' => $key,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function mergeFallbackMessage(string $key, array $fallback): array
    {
        $message = 'Bu bölüm için veri hazırlanamadı.';

        if (array_key_exists('message', $fallback)) {
            $fallback['message'] = $message;
        }

        if ($key === 'product_data_hub') {
            $fallback['warnings'] = ['Bu bölüm için veri hazırlanamadı.'];
        }

        if ($key === 'security_warnings') {
            $fallback = [[
                'key' => 'section_' . $key . '_failed',
                'title' => 'Operasyon paneli bölümü fallback modunda açıldı',
                'tone' => 'warning',
                'description' => $message,
            ]];
        }

        if ($key === 'recent_operations') {
            $fallback = [[
                'event_title' => 'Veri hazırlanamadı',
                'tenant' => 'Sistem',
                'subject' => 'Sistem',
                'actor' => 'Sistem',
                'occurred_at' => now()->format('d.m.Y H:i'),
                'tone' => 'amber',
                'summary' => $message,
                'route' => null,
            ]];
        }

        return $fallback;
    }

    protected function logSectionFailure(string $key, \Throwable $exception): void
    {
        Log::warning('super_admin_operation_dashboard.section_failed', [
            'section' => $key,
            'exception' => class_basename($exception),
            'message' => $this->safeExceptionMessage($exception->getMessage()),
        ]);
    }

    protected function shouldWriteDebugTiming(): bool
    {
        return app()->environment('local');
    }

    /**
     * @return array<string, int>
     */
    protected function emptyTenantReadinessCounts(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'trial' => 0,
            'restricted' => 0,
            'ready' => 0,
            'warning' => 0,
            'blocked' => 0,
            'demo_test' => 0,
            'domain_panel_missing' => 0,
            'smtp_whatsapp_missing' => 0,
            'product_data_hub_missing' => 0,
            'package_limit_warning' => 0,
        ];
    }

    /**
     * @return array<string, int|array<string,int>>
     */
    protected function emptyUpgradeCounts(): array
    {
        return [
            'pending' => 0,
            'in_review' => 0,
            'approved' => 0,
            'applied' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'approved_but_unapplied' => 0,
            'apply_blocked' => 0,
            'apply_failed' => 0,
            'apply_blocked_or_failed' => 0,
            'open' => 0,
            'by_type' => [],
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptySignupCounts(): array
    {
        return [
            'new' => 0,
            'contacted' => 0,
            'preview_opened' => 0,
            'converted' => 0,
            'rejected' => 0,
            'archived' => 0,
            'trial' => 0,
            'demo' => 0,
            'last_7_days' => 0,
            'last_30_days' => 0,
            'contacted_waiting_conversion' => 0,
            'preview_pending_conversion' => 0,
            'converted_onboarding_incomplete' => 0,
            'open' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptyProductDataHubCounts(): array
    {
        return [
            'active_supplier_sources' => 0,
            'last_sync_successful' => 0,
            'price_stock_current' => 0,
            'review_pending' => 0,
            'projection_stale' => 0,
            'sync_errors' => 0,
            'supplier_access_followup' => 0,
            'tenant_impact_sources' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function unknownHealthItem(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'unknown',
            'status_label' => 'Bilinmiyor',
            'description' => 'Bu bölüm için veri hazırlanamadı.',
            'checked_at' => now()->format('d.m.Y H:i'),
            'route' => null,
            'is_placeholder' => true,
            'details' => ['Bu bölüm için veri hazırlanamadı.'],
        ];
    }

    /**
     * @param array<string, mixed> $overview
     * @return array<int, string>
     */
    protected function productDataHubDashboardWarnings(array $overview, int $syncErrors, int $pendingCategoryMappings, int $supplierAccessFollowup): array
    {
        $warnings = [];

        if ($syncErrors > 0) {
            $warnings[] = 'Senkronizasyon hatası olan Product Data Hub kaynakları var.';
        }

        if ($pendingCategoryMappings > 0) {
            $warnings[] = 'İnceleme veya kategori eşleme bekleyen Product Data Hub kayıtları var.';
        }

        if ((int) data_get($overview, 'counts.projection_issues', 0) > 0) {
            $warnings[] = 'Kataloğa yansıtma sinyali eski görünüyor.';
        }

        if ($supplierAccessFollowup > 0) {
            $warnings[] = 'Tedarikçi erişimi sonrası manuel doğrulama gerektiren kaynaklar var.';
        }

        if ($warnings === []) {
            $warnings[] = 'Dashboard isteğinde Product Data Hub için hafif özet kullanıldı; detay ekranında manuel doğrulama önerilir.';
        }

        return $warnings;
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $signup
     * @param array<string, mixed> $upgrade
     * @param array<string, mixed> $productDataHub
     * @param array<string, array<string, mixed>> $systemHealth
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function buildActionQueueSection(
        Collection $rows,
        array $signup,
        array $upgrade,
        array $productDataHub,
        array $systemHealth,
    ): array {
        $critical = [];
        $today = [];
        $technical = [];

        $ownerMissing = $rows->filter(fn (array $row) => !$row['owner_exists'])->count();
        if ($ownerMissing > 0) {
            $critical[] = $this->actionItem('Panel Yetkilisi bilgisi eksik hesaplar', 'Canlıya çıkışı bloklayabilir.', $ownerMissing, 'critical', route('admin.super.tenants.index'), 'İncele', 'tenant_readiness', 10);
        }

        $panelMissing = $rows->filter(fn (array $row) => $row['has_panel_or_domain_gap'])->count();
        if ($panelMissing > 0) {
            $critical[] = $this->actionItem('Panel adresi veya alan adı eksik hesaplar', 'Panel ve portal görünürlüğü tamamlanmalı.', $panelMissing, 'critical', route('admin.super.tenants.index'), 'İncele', 'tenant_readiness', 9);
        }

        $smtpMissing = $rows->filter(fn (array $row) => !$row['smtp_ready'])->count();
        if ($smtpMissing > 0) {
            $critical[] = $this->actionItem('SMTP kurulumu eksik hesaplar', 'Bildirim akışı hazır değil.', $smtpMissing, 'warning', route('admin.super.tenants.index'), 'İncele', 'tenant_readiness', 8);
        }

        $approvedUnapplied = (int) data_get($upgrade, 'counts.approved_but_unapplied', 0);
        if ($approvedUnapplied > 0) {
            $critical[] = $this->actionItem('Onaylandı ama uygulanmamış talepler', 'Talep etkisi Abone Firma tarafına henüz yansımadı.', $approvedUnapplied, 'warning', route('admin.super.upgrade-requests.index', ['queue' => 'awaiting_apply']), 'Kuyruğu Aç', 'upgrade_request', 12);
        }

        $applyBlocked = (int) data_get($upgrade, 'counts.apply_blocked', 0);
        if ($applyBlocked > 0) {
            $critical[] = $this->actionItem('Güvenlik kontrolüne takılan talepler', 'Yeniden inceleme gerekebilir.', $applyBlocked, 'warning', route('admin.super.upgrade-requests.index'), 'İncele', 'upgrade_request', 11);
        }

        $applyFailed = (int) data_get($upgrade, 'counts.apply_failed', 0);
        if ($applyFailed > 0) {
            $critical[] = $this->actionItem('Uygulama hatası olan talepler', 'Detay ekranında güvenli hata özeti incelenmeli.', $applyFailed, 'critical', route('admin.super.upgrade-requests.index'), 'Detaya Git', 'upgrade_request', 13);
        }

        $projectionStale = (int) data_get($productDataHub, 'counts.projection_stale', 0);
        if ($projectionStale > 0) {
            $critical[] = $this->actionItem('Product Data Hub kataloğa yansıtma uyarıları', 'Katalog ve teklif tarafı eski kalabilir.', $projectionStale, 'warning', route('admin.super.product-data-hub.catalog-output'), 'Detaya Git', 'product_data_hub', 9);
        }

        $syncErrors = (int) data_get($productDataHub, 'counts.sync_errors', 0);
        if ($syncErrors > 0) {
            $critical[] = $this->actionItem('Senkronizasyon hatası olan kaynaklar', 'Tedarikçi veri akışında sorun görüldü.', $syncErrors, 'critical', route('admin.super.product-data-hub.index'), 'İncele', 'product_data_hub', 10);
        }

        $restricted = $rows->filter(fn (array $row) => in_array($row['subscription']['status'], ['expired', 'suspended'], true))->count();
        if ($restricted > 0) {
            $critical[] = $this->actionItem('Süresi dolmuş veya askıda Abone Firmalar', 'Erişim ve paket kararı bekliyor.', $restricted, 'critical', route('admin.super.tenants.index'), 'İncele', 'tenant_readiness', 7);
        }

        $trialExpiring = $rows->filter(function (array $row): bool {
            $daysRemaining = $row['subscription']['days_remaining'] ?? null;

            return $row['subscription']['status'] === 'trial'
                && $daysRemaining !== null
                && $daysRemaining >= 0
                && $daysRemaining <= 7;
        })->count();
        if ($trialExpiring > 0) {
            $today[] = $this->actionItem('Deneme süreci bitişi yaklaşanlar', 'Satış ve aktivasyon kararı bekliyor.', $trialExpiring, 'warning', route('admin.super.tenants.index'), 'Listele', 'tenant_readiness', 7);
        }

        $newSignups = (int) data_get($signup, 'counts.new', 0);
        if ($newSignups > 0) {
            $today[] = $this->actionItem('Yeni public başvurular', 'İlk geri dönüş bekleyen başvurular.', $newSignups, 'info', route('admin.super.signup-requests.index', ['status' => TenantSignupRequest::STATUS_NEW]), 'İncele', 'signup', 8);
        }

        $conversionWaiting = (int) data_get($signup, 'counts.contacted_waiting_conversion', 0);
        if ($conversionWaiting > 0) {
            $today[] = $this->actionItem('Dönüşüm bekleyen başvurular', 'Hazırlık tamamlanıp Abone Firmaya dönüştürülebilir.', $conversionWaiting, 'info', route('admin.super.signup-requests.index', ['status' => TenantSignupRequest::STATUS_CONTACTED]), 'İncele', 'signup', 9);
        }

        $previewPending = (int) data_get($signup, 'counts.preview_pending_conversion', 0);
        if ($previewPending > 0) {
            $today[] = $this->actionItem('Önizlemesi açılmış ama tamamlanmamış başvurular', 'Tenant create adımı veya karar bekleniyor.', $previewPending, 'warning', route('admin.super.signup-requests.index', ['status' => TenantSignupRequest::STATUS_CONTACTED]), 'Kuyruğu Aç', 'signup', 10);
        }

        $convertedOnboarding = (int) data_get($signup, 'counts.converted_onboarding_incomplete', 0);
        if ($convertedOnboarding > 0) {
            $today[] = $this->actionItem('Dönüştü ama onboarding eksik Abone Firmalar', 'Tenant açıldı, hazırlık adımları tamamlanmalı.', $convertedOnboarding, 'warning', route('admin.super.tenants.index'), 'Detaya Git', 'signup', 8);
        }

        $reviewPending = (int) data_get($productDataHub, 'counts.review_pending', 0);
        if ($reviewPending > 0) {
            $today[] = $this->actionItem('İnceleme bekleyen Product Data Hub kayıtları', 'Manuel karar isteyen veri kuyruğu.', $reviewPending, 'warning', route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue']), 'İncele', 'product_data_hub', 9);
        }

        $supplierAccessFollowup = (int) data_get($productDataHub, 'counts.supplier_access_followup', 0);
        if ($supplierAccessFollowup > 0) {
            $today[] = $this->actionItem(
                'Tedarikçi erişimi sonrası kontrol gerektiren kaynaklar',
                'Katalog ve teklif görünürlüğü ilk yansıtma sonrası doğrulanmalıdır.',
                $supplierAccessFollowup,
                'warning',
                route('admin.super.product-data-hub.catalog-output'),
                'Detaya Git',
                'product_data_hub',
                8
            );
        }

        $genericPending = (int) data_get($upgrade, 'counts.pending', 0);
        if ($genericPending > 0) {
            $today[] = $this->actionItem('Bekleyen Abone Firma talepleri', 'Super Admin incelemesi bekliyor.', $genericPending, 'info', route('admin.super.upgrade-requests.index', ['queue' => 'waiting']), 'Kuyruğu Aç', 'upgrade_request', 8);
        }

        $technical[] = $this->actionItem('Zamanlayıcı görünürlüğü eksik', (string) data_get($systemHealth, 'scheduler.description', 'Kontrol gerekli.'), 1, 'warning', null, 'Sistem Sağlığı', 'system_health', 6);
        $technical[] = $this->actionItem('Başarısız işler', (string) data_get($systemHealth, 'failed_jobs.description', 'Kontrol gerekli.'), (int) preg_replace('/\D+/', '', (string) data_get($systemHealth, 'failed_jobs.description', '0')) ?: 0, 'warning', null, 'Sistem Sağlığı', 'system_health', 8);
        $technical[] = $this->actionItem('Yedekleme tazeliği', (string) data_get($systemHealth, 'backup.description', 'Kontrol gerekli.'), 1, 'warning', null, 'Kontrol Et', 'system_health', 7);
        $technical[] = $this->actionItem('Depolama ve log görünürlüğü', 'Depolama ve log alanı için merkezi sinyal sınırlı seviyede.', 1, 'info', null, 'Sistem Sağlığı', 'system_health', 5);

        $notificationWarnings = $this->notificationReadinessService->actionableWarnings();
        $notificationLogFailures = collect($notificationWarnings)->firstWhere('key', 'notification_failed_logs');
        if (is_array($notificationLogFailures)) {
            $technical[] = $this->actionItem(
                'Bildirim loglarında başarısız kayıtlar',
                (string) ($notificationLogFailures['description'] ?? 'Bildirim logları kontrol edilmelidir.'),
                max(1, (int) data_get($this->notificationReadinessService->buildReadinessContext(), 'notification_logs.counts.failed', 0)),
                'warning',
                null,
                'Sistem Sağlığı',
                'system_health',
                7
            );
        }

        return [
            'critical' => $this->sortActionItems($critical),
            'today' => $this->sortActionItems($today),
            'technical' => $this->sortActionItems($technical),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    protected function tenantReadinessCounts(Collection $rows): array
    {
        $blocked = $rows->filter(fn (array $row) => $this->tenantReadinessStatus($row) === 'blocked')->count();
        $warning = $rows->filter(fn (array $row) => $this->tenantReadinessStatus($row) === 'warning')->count();
        $ready = $rows->filter(fn (array $row) => $this->tenantReadinessStatus($row) === 'ready')->count();

        return [
            'total' => $rows->count(),
            'active' => $rows->where('subscription.status', 'active')->count(),
            'trial' => $rows->where('subscription.status', 'trial')->count(),
            'restricted' => $rows->filter(fn (array $row) => in_array($row['subscription']['status'], ['expired', 'suspended'], true))->count(),
            'ready' => $ready,
            'warning' => $warning,
            'blocked' => $blocked,
            'demo_test' => $rows->where('is_demo_tenant', true)->count(),
            'domain_panel_missing' => $rows->where('has_panel_or_domain_gap', true)->count(),
            'smtp_whatsapp_missing' => $rows->filter(fn (array $row) => !($row['onboarding']['has_smtp_config'] ?? false) || !($row['onboarding']['has_whatsapp_config'] ?? false))->count(),
            'product_data_hub_missing' => $rows->filter(fn (array $row) => $this->productDataHubMissingForTenant($row))->count(),
            'package_limit_warning' => $rows->where('has_usage_warning', true)->count(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    protected function tenantMissingBadges(array $row): array
    {
        $badges = [];
        $onboarding = $row['onboarding'] ?? [];
        if (!$row['owner_exists']) {
            $badges[] = 'Panel Yetkilisi Eksik';
        }
        if ($row['has_panel_or_domain_gap']) {
            $badges[] = 'Panel Adresi Eksik';
        }
        if (!($onboarding['has_smtp_config'] ?? false)) {
            $badges[] = 'SMTP Bekliyor';
        }
        if (!($onboarding['has_whatsapp_config'] ?? false)) {
            $badges[] = 'WhatsApp Bekliyor';
        }
        if ($this->productDataHubMissingForTenant($row)) {
            $badges[] = 'Product Data Hub Eksik';
        }
        if ($row['has_usage_warning']) {
            $badges[] = 'Paket / Limit Uyarısı';
        }
        if ($row['subscription']['status'] === 'trial' && (($row['subscription']['days_remaining'] ?? 999) <= 7)) {
            $badges[] = 'Deneme Süreci Bitiyor';
        }

        return array_values(array_unique($badges));
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function tenantReadinessStatus(array $row): string
    {
        $subscriptionStatus = $row['subscription']['status'] ?? null;
        $onboarding = $row['onboarding'] ?? [];

        if (in_array($subscriptionStatus, ['expired', 'suspended', 'passive'], true)
            || !$row['owner_exists']
            || $row['has_panel_or_domain_gap']
            || !($onboarding['has_package'] ?? false)
        ) {
            return 'blocked';
        }

        if (!$row['onboarding_complete']
            || !$row['smtp_ready']
            || $row['has_usage_warning']
            || $this->productDataHubMissingForTenant($row)
        ) {
            return 'warning';
        }

        return 'ready';
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function productDataHubMissingForTenant(array $row): bool
    {
        /** @var TenantAccount $tenant */
        $tenant = $row['tenant'];
        $hasModule = $this->tenantAccessService->canAccessModule($tenant, 'product_data_hub')
            || $this->tenantAccessService->canAccessModule($tenant, 'supplier_feed');
        $hasSupplierAccess = $tenant->supplierAccesses()->active()->exists();
        $catalogCount = TenantCatalogProduct::query()->where('tenant_account_id', $tenant->id)->count();

        return $hasModule && (!$hasSupplierAccess || $catalogCount === 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function kpiCard(string $key, string $label, int $value, string $helper, string $tone): array
    {
        return compact('key', 'label', 'value', 'helper', 'tone');
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionItem(
        string $title,
        string $description,
        int $count,
        string $severity,
        ?string $route,
        string $label,
        string $source,
        int $priority,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'severity' => $severity,
            'severity_label' => $this->severityLabel($severity),
            'route' => $route,
            'action_label' => $label,
            'source' => $source,
            'priority' => $priority,
            'is_actionable' => filled($route),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function healthItem(string $label, string $status, string $statusLabel, string $description, bool $isPlaceholder, ?string $route = null): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel,
            'description' => $description,
            'route' => $route,
            'is_placeholder' => $isPlaceholder,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    protected function sortActionItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item): bool => ((int) ($item['count'] ?? 0)) > 0)
            ->sortBy([
                fn (array $item) => -1 * ((int) ($item['priority'] ?? 0)),
                fn (array $item) => match ($item['severity'] ?? 'info') {
                    'critical' => 0,
                    'warning' => 1,
                    default => 2,
                },
                fn (array $item) => -1 * ((int) ($item['count'] ?? 0)),
                fn (array $item) => $item['title'] ?? '',
            ])
            ->values()
            ->all();
    }

    protected function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Kritik',
            'warning' => 'Kontrol Gerekir',
            default => 'Bilgi',
        };
    }

    protected function signupQueuePriority(TenantSignupRequest $request, bool $hasPreviewOpened, bool $onboardingIncomplete): int
    {
        return match (true) {
            $request->status === TenantSignupRequest::STATUS_CONVERTED && $onboardingIncomplete => 1,
            $hasPreviewOpened && $request->status !== TenantSignupRequest::STATUS_CONVERTED => 2,
            $request->status === TenantSignupRequest::STATUS_CONTACTED => 3,
            $request->status === TenantSignupRequest::STATUS_NEW => 4,
            $request->status === TenantSignupRequest::STATUS_REJECTED => 5,
            $request->status === TenantSignupRequest::STATUS_ARCHIVED => 6,
            default => 7,
        };
    }

    protected function upgradeQueuePriority(TenantUpgradeRequest $request, bool $hasApplyBlocked, bool $hasApplyFailed): int
    {
        return match (true) {
            $hasApplyFailed => 1,
            $hasApplyBlocked => 2,
            $request->status === TenantUpgradeRequest::STATUS_APPROVED => 3,
            $request->status === TenantUpgradeRequest::STATUS_PENDING => 4,
            $request->status === TenantUpgradeRequest::STATUS_IN_REVIEW => 5,
            $request->status === TenantUpgradeRequest::STATUS_APPLIED => 6,
            default => 7,
        };
    }

    protected function formatDateTime(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('d.m.Y H:i');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('d.m.Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        return '-';
    }

    protected function tenantShowHasTemporaryPasswordWarning(): bool
    {
        $path = resource_path('views/super-admin/tenants/show.blade.php');
        if (!is_file($path)) {
            return false;
        }

        $content = @file_get_contents($path);

        return is_string($content)
            && (bool) preg_match('/\{\{\s*session\([\'"]owner_temporary_password[\'"]\)\s*\}\}/', $content);
    }

    protected function safeExceptionMessage(string $message): string
    {
        $safe = trim(strip_tags($message));

        foreach (['password', 'token', 'secret', 'smtp_password', 'api key', 'api_key'] as $needle) {
            if (Str::contains(Str::lower($safe), $needle)) {
                return 'Hassas detay gizlendi.';
            }
        }

        return Str::limit($safe, 180);
    }
}
