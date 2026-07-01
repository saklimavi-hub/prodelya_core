<?php

namespace App\Services\SuperAdmin;

use App\Models\Package;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use App\Services\SuperAdminOperationAuditService;
use App\Services\Tenant\TenantUpgradeRequestService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TenantUpgradeRequestReviewService
{
    public function __construct(
        protected TenantUpgradeRequestService $requestService,
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected PackageCatalogService $packageCatalogService,
        protected ModuleFeatureCatalogService $catalogService,
        protected SuperAdminOperationAuditService $operationAuditService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildIndexContext(array $filters): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $query = $this->buildIndexQuery($normalizedFilters);
        $requests = $query->paginate(20)->withQueryString();

        $summaryQuery = TenantUpgradeRequest::query();
        $summaryCards = [
            ['label' => 'Açık Talep', 'value' => (clone $summaryQuery)->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])->count()],
            ['label' => 'Bekleyen', 'value' => (clone $summaryQuery)->where('status', TenantUpgradeRequest::STATUS_PENDING)->count()],
            ['label' => 'İncelemede', 'value' => (clone $summaryQuery)->where('status', TenantUpgradeRequest::STATUS_IN_REVIEW)->count()],
            ['label' => 'Onaylanan', 'value' => (clone $summaryQuery)->where('status', TenantUpgradeRequest::STATUS_APPROVED)->count()],
            ['label' => 'Reddedilen', 'value' => (clone $summaryQuery)->where('status', TenantUpgradeRequest::STATUS_REJECTED)->count()],
            ['label' => 'Uygulama Bekleyen', 'value' => (clone $summaryQuery)->where('status', TenantUpgradeRequest::STATUS_APPROVED)->count()],
        ];

        return [
            'requests' => $requests,
            'filters' => $normalizedFilters,
            'summaryCards' => $summaryCards,
            'sideSummary' => [
                'open_count' => $summaryCards[0]['value'],
                'pending_count' => $summaryCards[1]['value'],
                'review_count' => $summaryCards[2]['value'],
                'approved_count' => $summaryCards[3]['value'],
                'filtered_count' => $requests->total(),
            ],
            'requestTypeOptions' => TenantUpgradeRequest::requestTypeOptions(),
            'statusOptions' => TenantUpgradeRequest::statusOptions(),
            'tenants' => TenantAccount::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ];
    }

    public function buildShowContext(TenantUpgradeRequest $request): array
    {
        $request->loadMissing(['tenantAccount.package', 'requestedBy', 'reviewedBy', 'appliedBy']);

        return [
            'request' => $request,
            'currentState' => $this->buildCurrentState($request),
            'impactPreview' => $this->buildImpactPreview($request),
            'actionAvailability' => $this->buildActionAvailability($request),
            'applySummary' => data_get($request->meta_json, 'apply_summary'),
            'timeline' => $this->operationAuditService->tenantUpgradeRequestTimeline($request),
            'statusOptions' => TenantUpgradeRequest::statusOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCurrentState(TenantUpgradeRequest $request): array
    {
        $tenant = $request->tenantAccount;
        $current = [
            'headline' => $request->requestTypeLabel(),
            'rows' => [],
        ];

        if (!$tenant) {
            $current['rows'][] = ['label' => 'Abone Firma', 'value' => 'Kayıt bulunamadı'];

            return $current;
        }

        if ($request->isPackageUpgrade()) {
            $currentPackage = $this->packageCatalogService->getPackageByKey((string) $request->current_package_key);
            $requestedPackage = $this->packageCatalogService->getPackageByKey((string) $request->requested_package_key);
            $current['rows'] = [
                ['label' => 'Mevcut Paket', 'value' => $currentPackage?->name ?? ($request->current_package_key ?: '-')],
                ['label' => 'Talep Edilen Paket', 'value' => $requestedPackage?->name ?? ($request->requested_package_key ?: '-')],
                ['label' => 'Paket Durumu', 'value' => $requestedPackage?->safeStatusLabel() ?? 'Bulunamadı'],
                ['label' => 'Aylık Fiyat', 'value' => $requestedPackage?->formattedPrice('monthly') ?? 'Tanımlı değil'],
                ['label' => 'Yıllık Fiyat', 'value' => $requestedPackage?->formattedPrice('yearly') ?? 'Tanımlı değil'],
            ];
        } elseif ($request->isModuleAddon()) {
            $module = $this->catalogService->getModule((string) $request->requested_module_key);
            $moduleStatus = $this->tenantAccessService->moduleStatus($tenant, (string) $request->requested_module_key);
            $current['rows'] = [
                ['label' => 'Talep Edilen Modül', 'value' => $module['label'] ?? ($request->requested_module_key ?: '-')],
                ['label' => 'Modül Durumu', 'value' => $this->moduleStatusLabel($module['status'] ?? null)],
                ['label' => 'Mevcut Erişim', 'value' => ($moduleStatus['enabled'] ?? false) ? 'Erişim var' : 'Erişim yok'],
                ['label' => 'Açılma Yolu', 'value' => $this->moduleActivationHint($module)],
            ];
        } elseif ($request->isFeatureAddon()) {
            $feature = $this->catalogService->getFeature((string) $request->requested_feature_key);
            $featureStatus = $this->tenantAccessService->featureStatus($tenant, (string) $request->requested_feature_key);
            $current['rows'] = [
                ['label' => 'Talep Edilen Özellik', 'value' => $feature['label'] ?? ($request->requested_feature_key ?: '-')],
                ['label' => 'Bağlı Modül', 'value' => $this->moduleLabel($featureStatus['module_key'] ?? null)],
                ['label' => 'Mevcut Erişim', 'value' => ($featureStatus['enabled'] ?? false) ? 'Erişim var' : 'Erişim yok'],
                ['label' => 'Özellik Durumu', 'value' => $this->moduleStatusLabel($feature['status'] ?? null)],
            ];
        } elseif ($request->isLimitIncrease()) {
            $usage = $this->tenantUsageService->getUsageForKey($tenant, (string) $request->requested_limit_key);
            $current['rows'] = [
                ['label' => 'Limit Türü', 'value' => $usage['label'] ?? ($request->requested_limit_key ?: '-')],
                ['label' => 'Mevcut Limit', 'value' => $usage['limit'] === null ? 'Limitsiz' : (string) $usage['limit']],
                ['label' => 'Talep Edilen Limit', 'value' => $request->requested_limit_value !== null ? (string) $request->requested_limit_value : '-'],
                ['label' => 'Mevcut Kullanım', 'value' => (string) ($usage['current'] ?? 0)],
                ['label' => 'Kullanım Yüzdesi', 'value' => $usage['percentage'] === null ? 'Limitsiz' : ((string) $usage['percentage'] . '%')],
            ];
        } elseif ($request->isSupplierAccess()) {
            $supplier = Supplier::query()->find($request->requested_supplier_id);
            $access = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $request->requested_supplier_id)
                ->first();
            $current['rows'] = [
                ['label' => 'Tedarikçi', 'value' => $supplier?->name ?? ($request->requested_supplier_key ?: '-')],
                ['label' => 'Kod', 'value' => $supplier?->code ?? ($request->requested_supplier_key ?: '-')],
                ['label' => 'Mevcut Erişim', 'value' => $access?->getStatusLabel() ?? 'Erişim yok'],
                ['label' => 'Katalog Etkisi', 'value' => $access?->visible_in_catalog ? 'Katalog açık' : 'Katalog kapalı'],
                ['label' => 'Teklif Etkisi', 'value' => $access?->can_use_in_quotes ? 'Teklifte açık' : 'Teklifte kapalı'],
            ];
        } else {
            $current['rows'] = [
                ['label' => 'Hizmet Anahtarı', 'value' => $request->requested_service_key ?: 'Özel servis notu'],
                ['label' => 'Talep Notu', 'value' => $request->requested_note ?: 'Açıklama girilmedi'],
            ];
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildImpactPreview(TenantUpgradeRequest $request): array
    {
        $tenant = $request->tenantAccount;
        $impacts = [];
        $warnings = [];

        if (!$tenant) {
            return [
                'impacts' => [],
                'warnings' => ['Talep artık bir Abone Firma kaydıyla eşleşmiyor.'],
            ];
        }

        if ($request->isPackageUpgrade()) {
            $currentPackage = $this->packageCatalogService->getPackageByKey((string) $request->current_package_key);
            $requestedPackage = $this->packageCatalogService->getPackageByKey((string) $request->requested_package_key);

            if (!$requestedPackage || !$requestedPackage->isActive()) {
                $warnings[] = 'Talep edilen paket artık aktif değil; uygulama aşamasında yeniden kontrol gerekir.';
            } else {
                $impacts[] = 'Paket değişirse modül ve limit erişimleri paket varsayılanlarına göre yeniden değerlendirilir.';
                $impacts[] = 'Yeni paketin limit seti ve modül seti tenant menüsünü etkileyebilir.';
                $changedLimits = $this->limitDiffSummary($currentPackage, $requestedPackage);
                if ($changedLimits !== null) {
                    $impacts[] = $changedLimits;
                }
            }
        } elseif ($request->isModuleAddon()) {
            $moduleStatus = $this->tenantAccessService->moduleStatus($tenant, (string) $request->requested_module_key);
            if ($moduleStatus['enabled'] ?? false) {
                $warnings[] = 'Tenant bu modüle sonradan erişim kazanmış görünüyor; talep geçerliliği kontrol edilmeli.';
            }
            $impacts[] = 'Modül açılırsa tenant menüsünde yeni ekranlar ve route erişimleri görünür hale gelebilir.';
        } elseif ($request->isFeatureAddon()) {
            $featureStatus = $this->tenantAccessService->featureStatus($tenant, (string) $request->requested_feature_key);
            if ($featureStatus['enabled'] ?? false) {
                $warnings[] = 'Talep edilen özellik şu anda tenant için zaten açık görünüyor.';
            }
            $impacts[] = 'Özellik açılırsa ilgili modül içinde ek işlem butonları ve route izinleri etkinleşebilir.';
        } elseif ($request->isLimitIncrease()) {
            $usage = $this->tenantUsageService->getUsageForKey($tenant, (string) $request->requested_limit_key);
            if (($usage['limit'] ?? null) === null) {
                $warnings[] = 'İlgili limit şu anda sınırsız; talep gereksiz hale gelmiş olabilir.';
            }
            $impacts[] = 'Limit artışı usage warning ve exceeded durumlarını aşağı çekebilir.';
            $impacts[] = 'Yeni limit değeri uygulanana kadar mevcut kullanım statüsü değişmeyecektir.';
        } elseif ($request->isSupplierAccess()) {
            $access = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $request->requested_supplier_id)
                ->active()
                ->exists();
            if ($access) {
                $warnings[] = 'Tenant ilgili tedarikçiye şu anda zaten erişiyor; talep artık geçersiz olabilir.';
            }
            $impacts[] = 'Tedarikçi erişimi açılırsa Product Data Hub katalog ve teklif akışları etkilenebilir.';
        } else {
            $impacts[] = 'Ek hizmet talebi manuel operasyon ve dış süreç planlaması gerektirir.';
        }

        return [
            'impacts' => $impacts,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    public function buildActionAvailability(TenantUpgradeRequest $request): array
    {
        $canInReview = in_array($request->status, [
            TenantUpgradeRequest::STATUS_PENDING,
            TenantUpgradeRequest::STATUS_IN_REVIEW,
        ], true);
        $canApprove = in_array($request->status, [
            TenantUpgradeRequest::STATUS_PENDING,
            TenantUpgradeRequest::STATUS_IN_REVIEW,
        ], true);
        $canReject = in_array($request->status, [
            TenantUpgradeRequest::STATUS_PENDING,
            TenantUpgradeRequest::STATUS_IN_REVIEW,
            TenantUpgradeRequest::STATUS_APPROVED,
        ], true);
        $canCancel = in_array($request->status, [
            TenantUpgradeRequest::STATUS_PENDING,
            TenantUpgradeRequest::STATUS_IN_REVIEW,
        ], true);

        return [
            'can_in_review' => $canInReview,
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'can_cancel' => $canCancel,
            'can_apply' => $request->isApproved(),
            'is_closed' => $request->isClosed(),
            'apply_waiting_note' => $request->isApproved()
                ? 'Bu talep onaylandı. Uygula dediğinizde ilgili Abone Firma erişimleri değişecektir.'
                : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildIndexQuery(array $filters): Builder
    {
        return TenantUpgradeRequest::query()
            ->with(['tenantAccount', 'requestedBy', 'reviewedBy'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->whereHas('tenantAccount', function (Builder $tenantQuery) use ($search): void {
                        $tenantQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('slug', 'like', '%' . $search . '%');
                    })->orWhereHas('requestedBy', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })->orWhere('requested_note', 'like', '%' . $search . '%')
                        ->orWhere('admin_note', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['request_type'] !== '', fn (Builder $query) => $query->where('request_type', $filters['request_type']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['tenant_account_id'] !== null, fn (Builder $query) => $query->where('tenant_account_id', $filters['tenant_account_id']))
            ->when($filters['date_from'] !== null, fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->when($filters['queue'] !== '', function (Builder $query) use ($filters): void {
                if ($filters['queue'] === 'open') {
                    $query->whereIn('status', [
                        TenantUpgradeRequest::STATUS_PENDING,
                        TenantUpgradeRequest::STATUS_IN_REVIEW,
                        TenantUpgradeRequest::STATUS_APPROVED,
                    ]);
                } elseif ($filters['queue'] === 'waiting') {
                    $query->whereIn('status', [
                        TenantUpgradeRequest::STATUS_PENDING,
                        TenantUpgradeRequest::STATUS_IN_REVIEW,
                    ]);
                } elseif ($filters['queue'] === 'awaiting_apply') {
                    $query->where('status', TenantUpgradeRequest::STATUS_APPROVED);
                }
            })
            ->latest();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $dateFrom = filled($filters['date_from'] ?? null) ? Carbon::parse((string) $filters['date_from'])->toDateString() : null;
        $dateTo = filled($filters['date_to'] ?? null) ? Carbon::parse((string) $filters['date_to'])->toDateString() : null;

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'request_type' => trim((string) ($filters['request_type'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'tenant_account_id' => filled($filters['tenant_account_id'] ?? null) ? (int) $filters['tenant_account_id'] : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'queue' => trim((string) ($filters['queue'] ?? '')),
        ];
    }

    private function moduleStatusLabel(?string $status): string
    {
        return match ($status) {
            'core' => 'Temel sistem modülü',
            'active' => 'Aktif',
            'planned' => 'Yakında',
            'deprecated' => 'Kaldırılıyor',
            'passive' => 'Pasif',
            default => 'Bilinmiyor',
        };
    }

    private function moduleActivationHint(?array $module): string
    {
        if (!$module) {
            return 'Kontrol gerekli';
        }

        if (($module['requires_package'] ?? false) === true) {
            return 'Paket veya override ile açılabilir';
        }

        return 'Super Admin override ile açılabilir';
    }

    private function moduleLabel(?string $moduleKey): string
    {
        if ($moduleKey === null || $moduleKey === '') {
            return 'Bağlı modül yok';
        }

        return $this->catalogService->getModule($moduleKey)['label'] ?? $moduleKey;
    }

    private function limitDiffSummary(?Package $currentPackage, ?Package $requestedPackage): ?string
    {
        if (!$currentPackage || !$requestedPackage) {
            return null;
        }

        $current = collect($this->packageCatalogService->packageLimits($currentPackage))->keyBy('limit_key');
        $requested = collect($this->packageCatalogService->packageLimits($requestedPackage))->keyBy('limit_key');
        $changed = $requested->filter(function (array $item, string $key) use ($current): bool {
            $currentItem = $current->get($key);

            return !$currentItem || ($currentItem['limit_value'] ?? null) !== ($item['limit_value'] ?? null) || ($currentItem['is_unlimited'] ?? false) !== ($item['is_unlimited'] ?? false);
        });

        if ($changed->isEmpty()) {
            return null;
        }

        return 'Paket limit farkı: ' . $changed->take(3)->map(function (array $item): string {
            $value = ($item['is_unlimited'] ?? false) ? 'limitsiz' : (string) ($item['limit_value'] ?? '-');

            return ($item['limit_key'] ?? 'limit') . ' → ' . $value;
        })->implode(' · ');
    }
}
