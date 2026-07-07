<?php

namespace App\Services\SuperAdmin;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use App\Services\Tenant\TenantUpgradeRequestService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantUpgradeRequestApplyService
{
    public function __construct(
        protected TenantUpgradeRequestService $requestService,
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected PackageCatalogService $packageCatalogService,
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantSupplierCurrentAccountSyncService $tenantSupplierCurrentAccountSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function apply(TenantUpgradeRequest $request, User $actor, array $context = []): TenantUpgradeRequest
    {
        $applyNote = $this->normalizeNote($context['apply_note'] ?? null);

        try {
            $applied = DB::transaction(function () use ($request, $actor, $applyNote): TenantUpgradeRequest {
                /** @var TenantUpgradeRequest $lockedRequest */
                $lockedRequest = TenantUpgradeRequest::query()
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $tenant = TenantAccount::query()
                    ->whereKey($lockedRequest->tenant_account_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedRequest->setRelation('tenantAccount', $tenant);
                $lockedRequest->loadMissing(['requestedBy', 'reviewedBy', 'appliedBy']);

                $validated = $this->validateCanApply($lockedRequest, $tenant, $applyNote);

                if ($lockedRequest->isPackageUpgrade()) {
                    $summary = $this->applyPackageUpgrade($lockedRequest, $tenant);
                } elseif ($lockedRequest->isModuleAddon()) {
                    $summary = $this->applyModuleAddon($lockedRequest, $tenant);
                } elseif ($lockedRequest->isFeatureAddon()) {
                    $summary = $this->applyFeatureAddon($lockedRequest, $tenant);
                } elseif ($lockedRequest->isLimitIncrease()) {
                    $summary = $this->applyLimitIncrease($lockedRequest, $tenant);
                } elseif ($lockedRequest->isSupplierAccess()) {
                    $summary = $this->applySupplierAccess($lockedRequest, $tenant);
                } else {
                    $summary = $this->applyServiceRequest($lockedRequest, $tenant, $applyNote);
                }

                $meta = is_array($lockedRequest->meta_json) ? $lockedRequest->meta_json : [];
                $meta['apply_summary'] = $summary;

                $adminNote = $applyNote ?? $lockedRequest->admin_note;

                $before = [
                    'status' => $lockedRequest->status,
                    'admin_note' => $lockedRequest->admin_note,
                    'meta_json' => $lockedRequest->meta_json,
                ];

                $lockedRequest->forceFill([
                    'status' => TenantUpgradeRequest::STATUS_APPLIED,
                    'admin_note' => $adminNote,
                    'applied_by_user_id' => $actor->id,
                    'applied_at' => now(),
                    'meta_json' => $meta,
                ])->save();

                $this->logAudit('tenant_upgrade_request_applied', $lockedRequest, $actor, $before, [
                    'status' => $lockedRequest->status,
                    'apply_summary' => $summary,
                    'admin_note_preview' => $this->safePreview($adminNote),
                ], 'Abone Firma talebi güvenli şekilde uygulandı.');

                return $lockedRequest->fresh(['tenantAccount', 'requestedBy', 'reviewedBy', 'appliedBy']);
            });
        } catch (ValidationException $exception) {
            $this->logApplyBlocked($request->fresh(), $actor, $exception->errors(), $applyNote);
            throw $exception;
        } catch (Throwable $throwable) {
            $this->reportApplyFailure($request->fresh(), $actor, $throwable, $applyNote);
            throw $throwable;
        }

        return $applied;
    }

    public function validateCanApply(TenantUpgradeRequest $request, TenantAccount $tenant, ?string $applyNote = null): array
    {
        if ($request->status !== TenantUpgradeRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Yalnız onaylanan talepler uygulanabilir.',
            ]);
        }

        if ($request->isApplied() || $request->isRejected() || $request->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'Kapalı veya uygulanmış talepler yeniden çalıştırılamaz.',
            ]);
        }

        if ($request->tenant_account_id !== $tenant->id) {
            throw ValidationException::withMessages([
                'tenant' => 'Talep artık geçerli bir Abone Firma kaydıyla eşleşmiyor.',
            ]);
        }

        if ($request->isServiceRequest() && $applyNote === null) {
            throw ValidationException::withMessages([
                'apply_note' => 'Ek hizmet talebini uygularken uygulama notu zorunludur.',
            ]);
        }

        $payload = [
            'request_type' => $request->request_type,
            'requested_package_key' => $request->requested_package_key,
            'requested_module_key' => $request->requested_module_key,
            'requested_feature_key' => $request->requested_feature_key,
            'requested_limit_key' => $request->requested_limit_key,
            'requested_limit_value' => $request->requested_limit_value,
            'requested_supplier_id' => $request->requested_supplier_id,
            'requested_supplier_key' => $request->requested_supplier_key,
            'requested_service_key' => $request->requested_service_key,
            'requested_note' => $request->requested_note,
            'source_type' => $request->source_type,
            'source_id' => $request->source_id,
            'meta_json' => $request->meta_json ?? [],
        ];

        return $this->requestService->validateRequestPayload($request->request_type, $payload, $tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyPackageUpgrade(TenantUpgradeRequest $request, TenantAccount $tenant): array
    {
        $requestedPackage = Package::query()
            ->where('key', $request->requested_package_key)
            ->where('status', 'active')
            ->first();

        if (!$requestedPackage) {
            throw ValidationException::withMessages([
                'requested_package_key' => 'Talep edilen paket artık aktif değil.',
            ]);
        }

        $oldPackageKey = $tenant->package_key;
        $oldPackageLabel = $this->packageCatalogService->getPackageByKey((string) $oldPackageKey)?->name ?? ($oldPackageKey ?: '-');

        $tenant->update([
            'package_key' => $requestedPackage->key,
        ]);

        return [
            'request_type' => $request->request_type,
            'old_package_key' => $oldPackageKey,
            'old_package_label' => $oldPackageLabel,
            'new_package_key' => $requestedPackage->key,
            'new_package_label' => $requestedPackage->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyModuleAddon(TenantUpgradeRequest $request, TenantAccount $tenant): array
    {
        $moduleKey = (string) $request->requested_module_key;
        $module = $this->catalogService->getModule($moduleKey);

        if (!$module || ($module['is_core'] ?? false) || !in_array($module['status'] ?? 'passive', ['active'], true)) {
            throw ValidationException::withMessages([
                'requested_module_key' => 'Talep edilen modül artık güvenli şekilde açılamıyor.',
            ]);
        }

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => $moduleKey,
                'feature_key' => null,
            ],
            [
                'is_enabled' => true,
                'limit_value' => null,
                'meta' => [
                    'updated_via' => 'generic_upgrade_request_apply',
                    'request_id' => $request->id,
                ],
            ]
        );

        return [
            'request_type' => $request->request_type,
            'module_key' => $moduleKey,
            'module_label' => $module['label'] ?? $moduleKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyFeatureAddon(TenantUpgradeRequest $request, TenantAccount $tenant): array
    {
        $featureKey = (string) $request->requested_feature_key;
        $feature = $this->catalogService->getFeature($featureKey);
        $moduleKey = $this->resolveModuleKeyForFeature($featureKey);

        if (!$feature || !$moduleKey || !in_array($feature['status'] ?? 'passive', ['active'], true)) {
            throw ValidationException::withMessages([
                'requested_feature_key' => 'Talep edilen özellik artık güvenli şekilde açılamıyor.',
            ]);
        }

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => $moduleKey,
                'feature_key' => $featureKey,
            ],
            [
                'is_enabled' => true,
                'limit_value' => null,
                'meta' => [
                    'updated_via' => 'generic_upgrade_request_apply',
                    'request_id' => $request->id,
                ],
            ]
        );

        return [
            'request_type' => $request->request_type,
            'feature_key' => $featureKey,
            'feature_label' => $feature['label'] ?? $featureKey,
            'module_key' => $moduleKey,
            'module_label' => $this->catalogService->getModule($moduleKey)['label'] ?? $moduleKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyLimitIncrease(TenantUpgradeRequest $request, TenantAccount $tenant): array
    {
        $limitKey = (string) $request->requested_limit_key;
        $beforeUsage = $this->tenantUsageService->getUsageForKey($tenant, $limitKey);
        $currentLimit = $this->tenantUsageService->getLimit($tenant, $limitKey);

        if ($currentLimit === null) {
            throw ValidationException::withMessages([
                'requested_limit_key' => 'Bu limit şu anda sınırsız; yeni override uygulanamaz.',
            ]);
        }

        $requested = (int) $request->requested_limit_value;

        if ($requested <= (int) $currentLimit) {
            throw ValidationException::withMessages([
                'requested_limit_value' => 'Talep edilen limit artık mevcut efektif limitten büyük değil.',
            ]);
        }

        TenantSetting::setValue($tenant->id, 'limit_' . $limitKey, $requested, 'integer');

        return [
            'request_type' => $request->request_type,
            'limit_key' => $limitKey,
            'limit_label' => $beforeUsage['label'] ?? $limitKey,
            'old_limit' => (int) $currentLimit,
            'new_limit' => $requested,
            'current_usage' => (int) ($beforeUsage['current'] ?? 0),
            'usage_percentage_before' => $beforeUsage['percentage'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applySupplierAccess(TenantUpgradeRequest $request, TenantAccount $tenant): array
    {
        $supplier = null;

        if ($request->requested_supplier_id) {
            $supplier = Supplier::query()->find($request->requested_supplier_id);
        }

        if (!$supplier && filled($request->requested_supplier_key)) {
            $supplier = Supplier::query()->where('code', $request->requested_supplier_key)->first();
        }

        if (!$supplier) {
            throw ValidationException::withMessages([
                'requested_supplier_id' => 'Talep edilen tedarikçi bulunamadı.',
            ]);
        }

        if (TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'requested_supplier_id' => 'Tenant bu tedarikçiye zaten erişiyor.',
            ]);
        }

        $access = TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'granted_at' => now(),
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'price_multiplier' => null,
                'safe_stock_quantity' => null,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'access_settings' => [
                    'supplier_feed_enabled' => false,
                ],
                'meta' => [
                    'updated_via' => 'generic_upgrade_request_apply',
                    'request_id' => $request->id,
                ],
            ]
        );

        if ($access->is_active) {
            $this->tenantSupplierCurrentAccountSyncService->syncForTenantSupplierAccess($tenant, $supplier);
        }

        return [
            'request_type' => $request->request_type,
            'supplier_id' => $supplier->id,
            'supplier_key' => $supplier->code,
            'supplier_name' => $supplier->name,
            'catalog_visibility' => 'Açık',
            'quote_visibility' => 'Açık',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyServiceRequest(TenantUpgradeRequest $request, TenantAccount $tenant, ?string $applyNote): array
    {
        if ($applyNote === null) {
            throw ValidationException::withMessages([
                'apply_note' => 'Ek hizmet talebi için uygulama notu zorunludur.',
            ]);
        }

        return [
            'request_type' => $request->request_type,
            'service_key' => $request->requested_service_key,
            'service_note_preview' => $this->safePreview($applyNote),
            'service_result' => 'Manuel hizmet tamamlandı olarak kapatıldı.',
            'system_mutation' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public function reportApplyFailure(TenantUpgradeRequest $request, User $actor, Throwable $throwable, ?string $applyNote = null): void
    {
        $this->logAudit('tenant_upgrade_request_apply_failed', $request, $actor, [
            'status' => $request->status,
        ], [
            'status' => $request->status,
            'message' => mb_substr($throwable->getMessage(), 0, 240),
            'apply_note_preview' => $this->safePreview($applyNote),
        ], 'Abone Firma talebi uygulanırken beklenmeyen hata oluştu.');
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public function logApplyBlocked(TenantUpgradeRequest $request, User $actor, array $errors, ?string $applyNote = null): void
    {
        $flattened = collect($errors)->flatten()->filter()->values()->all();

        $this->logAudit('tenant_upgrade_request_apply_blocked', $request, $actor, [
            'status' => $request->status,
        ], [
            'status' => $request->status,
            'reasons' => $flattened,
            'apply_note_preview' => $this->safePreview($applyNote),
        ], 'Abone Firma talebi uygulama kontrolü tarafından engellendi.');
    }

    private function resolveModuleKeyForFeature(string $featureKey): ?string
    {
        $normalizedFeatureKey = $this->catalogService->normalizeFeatureKey($featureKey);

        foreach ($this->catalogService->features() as $moduleKey => $moduleFeatures) {
            if (array_key_exists($normalizedFeatureKey, $moduleFeatures)) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function normalizeNote(mixed $note): ?string
    {
        $normalized = trim((string) ($note ?? ''));

        return $normalized === '' ? null : mb_substr($normalized, 0, 2000);
    }

    private function safePreview(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = trim(strip_tags($value));

        return $sanitized === '' ? null : mb_substr($sanitized, 0, 180);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function logAudit(string $action, TenantUpgradeRequest $request, User $actor, array $oldValues, array $newValues, string $note): void
    {
        AuditLog::log([
            'tenant_account_id' => $request->tenant_account_id,
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $request->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'notes' => $note,
        ]);
    }
}
