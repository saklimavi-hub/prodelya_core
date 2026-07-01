<?php

namespace App\Services\Tenant;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Services\ModuleFeatureCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantUpgradeRequestService
{
    public function __construct(
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantUsageService $tenantUsageService,
        protected TenantAccessService $tenantAccessService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRequest(array $data, TenantAccount $tenant, User $actor): TenantUpgradeRequest
    {
        $requestType = (string) ($data['request_type'] ?? '');
        $validated = $this->validateRequestPayload($requestType, $data, $tenant);
        $this->guardDuplicateOpenRequest($requestType, $validated, $tenant);

        $record = DB::transaction(function () use ($validated, $tenant, $actor, $requestType) {
            $request = TenantUpgradeRequest::query()->create([
                'tenant_account_id' => $tenant->id,
                'requested_by_user_id' => $actor->id,
                'request_type' => $requestType,
                'status' => TenantUpgradeRequest::STATUS_PENDING,
                'current_package_key' => $validated['current_package_key'] ?? $tenant->package_key,
                'requested_package_key' => $validated['requested_package_key'] ?? null,
                'requested_module_key' => $validated['requested_module_key'] ?? null,
                'requested_feature_key' => $validated['requested_feature_key'] ?? null,
                'requested_limit_key' => $validated['requested_limit_key'] ?? null,
                'current_limit_value' => $validated['current_limit_value'] ?? null,
                'requested_limit_value' => $validated['requested_limit_value'] ?? null,
                'requested_supplier_id' => $validated['requested_supplier_id'] ?? null,
                'requested_supplier_key' => $validated['requested_supplier_key'] ?? null,
                'requested_service_key' => $validated['requested_service_key'] ?? null,
                'requested_note' => $validated['requested_note'] ?? null,
                'source_type' => $validated['source_type'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'meta_json' => $validated['meta_json'] ?? [],
            ]);

            $this->logAudit('tenant_upgrade_request_created', $request, $actor, [], [
                'request_type' => $request->request_type,
                'status' => $request->status,
                'requested_package_key' => $request->requested_package_key,
                'requested_module_key' => $request->requested_module_key,
                'requested_feature_key' => $request->requested_feature_key,
                'requested_limit_key' => $request->requested_limit_key,
                'requested_limit_value' => $request->requested_limit_value,
                'requested_supplier_id' => $request->requested_supplier_id,
                'requested_service_key' => $request->requested_service_key,
                'requested_note_preview' => $this->safePreview($request->requested_note),
            ], 'Abone Firma talebi oluşturuldu.');

            return $request;
        });

        return $record->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validateRequestPayload(string $requestType, array $data, TenantAccount $tenant): array
    {
        $requestType = trim($requestType);

        if (!array_key_exists($requestType, TenantUpgradeRequest::requestTypeOptions())) {
            throw ValidationException::withMessages([
                'request_type' => 'Geçersiz talep tipi seçildi.',
            ]);
        }

        $payload = [
            'current_package_key' => $tenant->package_key,
            'source_type' => Arr::get($data, 'source_type'),
            'source_id' => Arr::get($data, 'source_id'),
            'meta_json' => Arr::get($data, 'meta_json', []),
        ];

        if ($requestType === TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE) {
            $requestedPackageKey = trim((string) Arr::get($data, 'requested_package_key', ''));

            if ($requestedPackageKey === '') {
                throw ValidationException::withMessages(['requested_package_key' => 'Talep edilen paket zorunludur.']);
            }

            if ($requestedPackageKey === (string) $tenant->package_key) {
                throw ValidationException::withMessages(['requested_package_key' => 'Mevcut paket için yeniden talep oluşturulamaz.']);
            }

            $package = Package::query()->where('key', $requestedPackageKey)->first();
            if (!$package || $package->status !== 'active') {
                throw ValidationException::withMessages(['requested_package_key' => 'Yalnız aktif paketler için upgrade talebi oluşturulabilir.']);
            }

            $payload['requested_package_key'] = $requestedPackageKey;
        }

        if ($requestType === TenantUpgradeRequest::TYPE_MODULE_ADDON) {
            $requestedModuleKey = $this->catalogService->normalizeModuleKey((string) Arr::get($data, 'requested_module_key', ''));
            $module = $this->catalogService->getModule($requestedModuleKey);

            if (!$module) {
                throw ValidationException::withMessages(['requested_module_key' => 'Talep edilen modül tanınmıyor.']);
            }

            if ((bool) ($module['is_core'] ?? false)) {
                throw ValidationException::withMessages(['requested_module_key' => 'Temel sistem modülleri için addon talebi oluşturulamaz.']);
            }

            if (in_array($module['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                throw ValidationException::withMessages(['requested_module_key' => 'Planlı veya pasif modüller için bu fazda addon talebi açılamaz.']);
            }

            if ($this->tenantAccessService->canAccessModule($tenant, $requestedModuleKey)) {
                throw ValidationException::withMessages(['requested_module_key' => 'Bu modül tenant için zaten aktif.']);
            }

            $payload['requested_module_key'] = $requestedModuleKey;
        }

        if ($requestType === TenantUpgradeRequest::TYPE_FEATURE_ADDON) {
            $requestedFeatureKey = $this->catalogService->normalizeFeatureKey((string) Arr::get($data, 'requested_feature_key', ''));
            $feature = $this->catalogService->getFeature($requestedFeatureKey);

            if (!$feature) {
                throw ValidationException::withMessages(['requested_feature_key' => 'Talep edilen özellik tanınmıyor.']);
            }

            if (in_array($feature['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                throw ValidationException::withMessages(['requested_feature_key' => 'Planlı veya pasif özellikler için bu fazda addon talebi açılamaz.']);
            }

            if ($this->tenantAccessService->canAccessFeature($tenant, $requestedFeatureKey)) {
                throw ValidationException::withMessages(['requested_feature_key' => 'Bu özellik tenant için zaten aktif.']);
            }

            $payload['requested_feature_key'] = $requestedFeatureKey;
        }

        if ($requestType === TenantUpgradeRequest::TYPE_LIMIT_INCREASE) {
            $limitKey = trim((string) Arr::get($data, 'requested_limit_key', ''));
            $requestedLimitValue = Arr::get($data, 'requested_limit_value');

            if ($limitKey === '') {
                throw ValidationException::withMessages(['requested_limit_key' => 'Talep edilen limit alanı zorunludur.']);
            }

            if (!is_numeric($requestedLimitValue) || (int) $requestedLimitValue < 1) {
                throw ValidationException::withMessages(['requested_limit_value' => 'Talep edilen limit değeri pozitif bir sayı olmalıdır.']);
            }

            $currentLimitValue = $this->tenantUsageService->getLimit($tenant, $limitKey);
            $normalizedCurrent = $currentLimitValue === null ? null : (int) $currentLimitValue;
            $normalizedRequested = (int) $requestedLimitValue;

            if ($normalizedCurrent === null) {
                throw ValidationException::withMessages(['requested_limit_key' => 'Bu limit tenant için zaten sınırsız.']);
            }

            if ($normalizedCurrent !== null && $normalizedRequested <= $normalizedCurrent) {
                throw ValidationException::withMessages(['requested_limit_value' => 'Yeni limit mevcut limitten büyük olmalıdır.']);
            }

            $payload['requested_limit_key'] = $limitKey;
            $payload['current_limit_value'] = $normalizedCurrent;
            $payload['requested_limit_value'] = $normalizedRequested;
        }

        if ($requestType === TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS) {
            $requestedSupplierId = Arr::get($data, 'requested_supplier_id');
            $requestedSupplierKey = trim((string) Arr::get($data, 'requested_supplier_key', ''));
            $supplier = null;

            if ($requestedSupplierId !== null && $requestedSupplierId !== '') {
                $supplier = Supplier::query()->find($requestedSupplierId);
            } elseif ($requestedSupplierKey !== '') {
                $supplier = Supplier::query()->where('code', $requestedSupplierKey)->first();
            }

            if (!$supplier) {
                throw ValidationException::withMessages(['requested_supplier_id' => 'Talep edilen tedarikçi bulunamadı.']);
            }

            $alreadyGranted = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $supplier->id)
                ->where('is_active', true)
                ->exists();

            if ($alreadyGranted) {
                throw ValidationException::withMessages(['requested_supplier_id' => 'Bu tedarikçi için erişim zaten aktif.']);
            }

            $payload['requested_supplier_id'] = $supplier->id;
            $payload['requested_supplier_key'] = $supplier->code;
        }

        if ($requestType === TenantUpgradeRequest::TYPE_SERVICE_REQUEST) {
            $serviceKey = trim((string) Arr::get($data, 'requested_service_key', ''));
            $requestedNote = $this->normalizeNote(Arr::get($data, 'requested_note'));

            if ($serviceKey === '' && $requestedNote === null) {
                throw ValidationException::withMessages([
                    'requested_service_key' => 'Ek hizmet talebinde hizmet anahtarı veya açıklama zorunludur.',
                ]);
            }

            $payload['requested_service_key'] = $serviceKey !== '' ? $serviceKey : null;
            $payload['requested_note'] = $requestedNote;
        }

        if ($requestType !== TenantUpgradeRequest::TYPE_SERVICE_REQUEST) {
            $payload['requested_note'] = $this->normalizeNote(Arr::get($data, 'requested_note'));
        }

        return $payload;
    }

    public function listForTenant(TenantAccount $tenant): Collection
    {
        return TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->latest()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, TenantUpgradeRequest>
     */
    public function listForSuperAdmin(array $filters = []): Collection
    {
        $query = TenantUpgradeRequest::query()
            ->with(['tenantAccount', 'requestedBy', 'reviewedBy', 'appliedBy'])
            ->latest();

        if (filled($filters['tenant_account_id'] ?? null)) {
            $query->where('tenant_account_id', (int) $filters['tenant_account_id']);
        }

        if (filled($filters['request_type'] ?? null)) {
            $query->where('request_type', (string) $filters['request_type']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->get();
    }

    public function markInReview(TenantUpgradeRequest $request, User $actor, ?string $note = null): TenantUpgradeRequest
    {
        if (!$request->canBeReviewed()) {
            throw ValidationException::withMessages(['status' => 'Bu talep artık incelemeye alınamaz.']);
        }

        $before = ['status' => $request->status, 'admin_note' => $request->admin_note];

        $request->forceFill([
            'status' => TenantUpgradeRequest::STATUS_IN_REVIEW,
            'admin_note' => $this->normalizeNote($note),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->logAudit('tenant_upgrade_request_in_review', $request, $actor, $before, [
            'status' => $request->status,
            'admin_note_preview' => $this->safePreview($request->admin_note),
        ], 'Abone Firma talebi incelemeye alındı.');

        return $request->fresh();
    }

    public function approve(TenantUpgradeRequest $request, User $actor, ?string $note = null): TenantUpgradeRequest
    {
        if (!in_array($request->status, [TenantUpgradeRequest::STATUS_PENDING, TenantUpgradeRequest::STATUS_IN_REVIEW], true)) {
            throw ValidationException::withMessages(['status' => 'Bu talep bu durumdan onaylanamaz.']);
        }

        $before = ['status' => $request->status, 'admin_note' => $request->admin_note];

        $request->forceFill([
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'admin_note' => $this->normalizeNote($note),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->logAudit('tenant_upgrade_request_approved', $request, $actor, $before, [
            'status' => $request->status,
            'admin_note_preview' => $this->safePreview($request->admin_note),
        ], 'Abone Firma talebi onaylandı.');

        return $request->fresh();
    }

    public function reject(TenantUpgradeRequest $request, User $actor, ?string $note = null): TenantUpgradeRequest
    {
        if ($request->isClosed()) {
            throw ValidationException::withMessages(['status' => 'Kapalı talepler yeniden reddedilemez.']);
        }

        $before = ['status' => $request->status, 'admin_note' => $request->admin_note];

        $request->forceFill([
            'status' => TenantUpgradeRequest::STATUS_REJECTED,
            'admin_note' => $this->normalizeNote($note),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->logAudit('tenant_upgrade_request_rejected', $request, $actor, $before, [
            'status' => $request->status,
            'admin_note_preview' => $this->safePreview($request->admin_note),
        ], 'Abone Firma talebi reddedildi.');

        return $request->fresh();
    }

    public function cancel(TenantUpgradeRequest $request, User $actor, ?string $note = null): TenantUpgradeRequest
    {
        if ($request->isClosed()) {
            throw ValidationException::withMessages(['status' => 'Kapalı talepler yeniden iptal edilemez.']);
        }

        $before = ['status' => $request->status, 'admin_note' => $request->admin_note];

        $request->forceFill([
            'status' => TenantUpgradeRequest::STATUS_CANCELLED,
            'admin_note' => $this->normalizeNote($note),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->logAudit('tenant_upgrade_request_cancelled', $request, $actor, $before, [
            'status' => $request->status,
            'admin_note_preview' => $this->safePreview($request->admin_note),
        ], 'Abone Firma talebi iptal edildi.');

        return $request->fresh();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function guardDuplicateOpenRequest(string $requestType, array $validated, TenantAccount $tenant): void
    {
        $query = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', $requestType)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ]);

        $message = 'Aynı içerik için açık bir talep zaten bulunuyor.';

        if ($requestType === TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE) {
            $query->where('requested_package_key', $validated['requested_package_key']);
            $field = 'requested_package_key';
        } elseif ($requestType === TenantUpgradeRequest::TYPE_MODULE_ADDON) {
            $query->where('requested_module_key', $validated['requested_module_key']);
            $field = 'requested_module_key';
        } elseif ($requestType === TenantUpgradeRequest::TYPE_FEATURE_ADDON) {
            $query->where('requested_feature_key', $validated['requested_feature_key']);
            $field = 'requested_feature_key';
        } elseif ($requestType === TenantUpgradeRequest::TYPE_LIMIT_INCREASE) {
            $query->where('requested_limit_key', $validated['requested_limit_key']);
            $field = 'requested_limit_key';
        } elseif ($requestType === TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS) {
            $query->where('requested_supplier_id', $validated['requested_supplier_id']);
            $field = 'requested_supplier_id';
        } else {
            $query->where('requested_service_key', $validated['requested_service_key'] ?? null)
                ->where('requested_note', $validated['requested_note'] ?? null);
            $field = 'requested_service_key';
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function normalizeNote(mixed $note): ?string
    {
        $normalized = trim((string) ($note ?? ''));

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 2000);
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
