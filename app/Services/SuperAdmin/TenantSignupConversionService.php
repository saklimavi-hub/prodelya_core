<?php

namespace App\Services\SuperAdmin;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSignupRequest;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SuperAdminOperationAuditService;
use App\Services\TenantCompanyProfileService;
use App\Services\TenantOnboardingStatusService;
use App\Services\TenantAccessService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantSignupConversionService
{
    public function __construct(
        private readonly TenantSignupRequestReadinessService $readinessService,
        private readonly SuperAdminOperationAuditService $operationAuditService,
        private readonly TenantCompanyProfileService $tenantCompanyProfileService,
        private readonly TenantOnboardingStatusService $tenantOnboardingStatusService,
        private readonly TenantAccessService $tenantAccessService,
    ) {
    }

    public function resolveSignupRequestForPrefill(?int $signupRequestId, User $actor): ?TenantSignupRequest
    {
        if (!$signupRequestId) {
            return null;
        }

        $signupRequest = TenantSignupRequest::query()
            ->with(['requestedPackage', 'convertedTenant'])
            ->find($signupRequestId);

        if (!$signupRequest) {
            throw ValidationException::withMessages([
                'signup_request_id' => 'Başvuru kaydı bulunamadı.',
            ]);
        }

        $this->ensureCanStartConversion($signupRequest, $actor);

        $this->operationAuditService->logSignupRequestConversionPrefillOpened(
            $signupRequest,
            $actor,
            $this->safeAuditContext($signupRequest)
        );

        return $signupRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConversionContext(TenantSignupRequest $request): array
    {
        $readiness = $this->readinessService->evaluate($request);

        return [
            'signupRequest' => $request,
            'readiness' => $readiness,
            'prefill' => $this->buildCreatePrefill($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPreviewContext(TenantSignupRequest $request): array
    {
        $request->loadMissing(['requestedPackage.modules', 'requestedPackage.features', 'requestedPackage.limits', 'convertedTenant']);

        $readiness = $this->readinessService->evaluate($request);
        $prefill = $this->buildCreatePrefill($request);
        $package = $request->requestedPackage;

        if (!$package && filled($request->requested_package_key)) {
            $package = Package::query()
                ->with(['modules', 'features', 'limits'])
                ->where('key', $request->requested_package_key)
                ->first();
        }

        $trialDays = (int) ($readiness['trial_days'] ?? max(1, (int) ($package?->trial_days ?: 30)));
        $trialEndsAt = $request->request_type === TenantSignupRequest::TYPE_TRIAL
            ? now()->addDays($trialDays)->format('d.m.Y')
            : null;
        $ownerEmailStatus = $readiness['owner_email_status'] ?? [
            'status' => 'ready',
            'label' => 'Hazır',
            'message' => 'Firma yetkilisi e-postası create akışına taşınabilir.',
        ];

        $packageLimits = collect($package?->limits ?? [])
            ->filter(fn ($limit) => (bool) ($limit->is_enabled ?? true))
            ->map(fn ($limit) => [
                'key' => $limit->limit_key,
                'label' => str_replace('_', ' ', (string) $limit->limit_key),
                'value' => $limit->limit_value,
            ])
            ->values()
            ->all();

        return [
            'signupRequest' => $request,
            'readiness' => $readiness,
            'prefill' => $prefill,
            'package_summary' => [
                'name' => $package?->name ?? ($request->requested_package_key ?: 'Belirtilmedi'),
                'key' => $package?->key ?? $request->requested_package_key,
                'status_label' => $package?->safeStatusLabel() ?? ($readiness['package_status']['label'] ?? 'Belirtilmedi'),
                'trial_days' => $trialDays,
                'module_count' => count($package?->modules ?? []),
                'feature_count' => count($package?->features ?? []),
                'limit_count' => count($packageLimits),
                'limits' => $packageLimits,
            ],
            'owner_summary' => [
                'name' => $request->contact_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'status' => $ownerEmailStatus,
                'will_create_new_user' => ($ownerEmailStatus['status'] ?? 'ready') === 'ready',
                'existing_user_warning' => ($ownerEmailStatus['status'] ?? null) === 'warning'
                    ? 'Sistemde kayıtlı kullanıcı var, tenant yetkilisi olarak bağlama sonraki fazda netleştirilecek.'
                    : null,
            ],
            'tenant_summary' => [
                'name' => $prefill['name'] ?? $request->company_name,
                'legal_name' => $prefill['legal_name'] ?? $request->company_name,
                'slug' => $prefill['slug'] ?? null,
                'panel_subdomain' => $prefill['panel_subdomain'] ?? null,
                'status' => $prefill['status'] ?? null,
                'status_label' => ($prefill['status'] ?? null) === 'trial'
                    ? 'Trial'
                    : 'Super Admin kararına bağlı',
                'trial_days' => $request->request_type === TenantSignupRequest::TYPE_TRIAL ? $trialDays : null,
                'trial_ends_at' => $trialEndsAt,
                'package_name' => $package?->name ?? ($request->requested_package_key ?: 'Belirtilmedi'),
            ],
            'requested_modules_summary' => $readiness['requested_modules_summary'] ?? [],
            'conversion_notes' => $readiness['conversion_notes'] ?? [],
            'next_action' => [
                'state' => $readiness['cta']['state'] ?? 'ready',
                'label' => match ($readiness['cta']['state'] ?? 'ready') {
                    'converted' => 'Abone Firma Aç',
                    'blocked' => 'Devam Edilemez',
                    'warning' => 'Uyarılarla Devam Et',
                    default => 'Tenant Create Formuna Devam Et',
                },
                'enabled' => (bool) ($readiness['cta']['enabled'] ?? false) && (($readiness['cta']['state'] ?? null) !== 'converted'),
                'continue_url' => route('admin.super.tenants.create', ['signup_request_id' => $request->id]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSuccessContext(TenantSignupRequest $request): array
    {
        $request->loadMissing(['requestedPackage.modules', 'requestedPackage.features', 'requestedPackage.limits', 'convertedTenant.package']);

        $tenant = $request->convertedTenant;
        if (!$tenant) {
            throw ValidationException::withMessages([
                'signup_request_id' => 'Başvuru henüz Abone Firma’ya dönüştürülmedi.',
            ]);
        }

        $readiness = $this->readinessService->evaluate($request);
        $package = $tenant->package ?: $request->requestedPackage;
        if (!$package && filled($tenant->package_key)) {
            $package = Package::query()
                ->with(['modules', 'features', 'limits'])
                ->where('key', $tenant->package_key)
                ->first();
        }

        $onboardingStatus = $this->tenantOnboardingStatusService->forTenant($tenant);
        $ownerRoleId = Role::query()->where('key', 'tenant_owner')->where('is_active', true)->value('id');
        $ownerAssignment = $ownerRoleId
            ? UserRole::query()
                ->with('user')
                ->where('tenant_account_id', $tenant->id)
                ->where('role_id', $ownerRoleId)
                ->latest('id')
                ->first()
            : null;
        $owner = $ownerAssignment?->user;

        $checklist = [
            ['label' => 'Abone Firma hesabı', 'state' => 'Hazır', 'tone' => 'green'],
            ['label' => 'Panel yetkilisi', 'state' => ($onboardingStatus['has_owner'] ?? false) ? 'Hazır' : 'Eksik', 'tone' => ($onboardingStatus['has_owner'] ?? false) ? 'green' : 'amber'],
            ['label' => 'tenant_owner rolü', 'state' => ($onboardingStatus['has_active_owner'] ?? false) ? 'Hazır' : 'Eksik', 'tone' => ($onboardingStatus['has_active_owner'] ?? false) ? 'green' : 'amber'],
            ['label' => 'Paket ilişkisi', 'state' => ($onboardingStatus['has_package'] ?? false) ? 'Hazır' : 'Eksik', 'tone' => ($onboardingStatus['has_package'] ?? false) ? 'green' : 'amber'],
            ['label' => 'Varsayılan ayarlar', 'state' => ($onboardingStatus['has_tenant_settings_defaults'] ?? false) ? 'Hazır' : 'Kurulum Bekliyor', 'tone' => ($onboardingStatus['has_tenant_settings_defaults'] ?? false) ? 'green' : 'amber'],
            ['label' => 'Baskı ayarları', 'state' => ($onboardingStatus['has_print_settings'] ?? false) ? 'Hazır' : 'Kurulum Bekliyor', 'tone' => ($onboardingStatus['has_print_settings'] ?? false) ? 'green' : 'amber'],
            ['label' => 'Bildirim şablonları', 'state' => ($onboardingStatus['has_notification_templates'] ?? false) ? 'Hazır' : 'Kurulum Bekliyor', 'tone' => ($onboardingStatus['has_notification_templates'] ?? false) ? 'green' : 'amber'],
            ['label' => 'Firma profili', 'state' => ($onboardingStatus['has_company_profile'] ?? false) ? 'Hazır' : 'Eksik', 'tone' => ($onboardingStatus['has_company_profile'] ?? false) ? 'green' : 'amber'],
            ['label' => 'SMTP kurulumu', 'state' => ($onboardingStatus['has_smtp_config'] ?? false) ? 'Hazır' : 'Kontrol Gerekir', 'tone' => ($onboardingStatus['has_smtp_config'] ?? false) ? 'green' : 'blue'],
            ['label' => 'WhatsApp kurulumu', 'state' => ($onboardingStatus['has_whatsapp_config'] ?? false) ? 'Hazır' : 'Kontrol Gerekir', 'tone' => ($onboardingStatus['has_whatsapp_config'] ?? false) ? 'green' : 'blue'],
            ['label' => 'Product Data Hub erişimi', 'state' => $this->tenantAccessService->canAccessModule($tenant, 'product_data_hub') ? 'Hazır' : 'Kontrol Gerekir', 'tone' => $this->tenantAccessService->canAccessModule($tenant, 'product_data_hub') ? 'green' : 'blue'],
            ['label' => 'Tedarikçi erişimi', 'state' => $tenant->supplierAccesses()->exists() ? 'Hazır' : 'Kurulum Bekliyor', 'tone' => $tenant->supplierAccesses()->exists() ? 'green' : 'amber'],
            ['label' => 'Panel adresi', 'state' => ($onboardingStatus['has_panel_domain'] ?? false) ? 'Hazır' : 'Eksik', 'tone' => ($onboardingStatus['has_panel_domain'] ?? false) ? 'green' : 'amber'],
        ];

        $trialStartsAt = TenantSetting::getValue($tenant->id, 'subscription_trial_starts_at');
        $trialEndsAt = TenantSetting::getValue($tenant->id, 'subscription_trial_ends_at');

        return [
            'signupRequest' => $request,
            'converted_tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'panel_subdomain' => $tenant->panel_subdomain,
                'panel_url' => $this->tenantPanelUrl($tenant),
                'status' => $tenant->status,
                'status_label' => match ($tenant->status) {
                    'active' => 'Aktif',
                    'suspended' => 'Askıya Alındı',
                    'inactive' => 'Pasif',
                    default => 'Trial',
                },
            ],
            'owner_summary' => [
                'name' => $owner?->name ?: $request->contact_name,
                'email' => $owner?->email ?: $request->email,
                'phone' => $owner?->phone ?: $request->phone,
                'exists' => $owner !== null,
            ],
            'package_summary' => [
                'name' => $package?->name ?? ($tenant->package_key ?: 'Belirtilmedi'),
                'key' => $package?->key ?? $tenant->package_key,
            ],
            'trial_summary' => [
                'starts_at' => $trialStartsAt,
                'ends_at' => $trialEndsAt,
                'days' => $package?->trial_days ?: ($readiness['trial_days'] ?? null),
            ],
            'requested_modules_summary' => $readiness['requested_modules_summary'] ?? [],
            'onboarding_checklist' => $checklist,
            'warnings' => $readiness['warnings'] ?? [],
            'conversion_notes' => $readiness['conversion_notes'] ?? [],
            'converted_at' => data_get($request->meta_json, 'converted_at'),
            'conversion_audit_reference' => 'signup_request_conversion_completed',
            'next_actions' => [
                'tenant_show' => route('admin.super.tenants.show', $tenant),
                'tenant_edit' => route('admin.super.tenants.edit', $tenant),
                'tenant_prepare_defaults' => route('admin.super.tenants.prepare-defaults', $tenant),
                'owner_create' => route('admin.super.tenants.owner.create', $tenant),
                'signup_show' => route('admin.super.signup-requests.show', $request),
                'success_show' => route('admin.super.signup-requests.conversion-success', $request),
                'tenant_panel' => $this->tenantPanelUrl($tenant),
            ],
        ];
    }

    public function logPreviewOpened(TenantSignupRequest $request, User $actor): void
    {
        $this->operationAuditService->logSignupRequestConversionPreviewOpened(
            $request,
            $actor,
            $this->safeAuditContext($request)
        );
    }

    public function logSuccessViewed(TenantSignupRequest $request, User $actor): void
    {
        $this->operationAuditService->logSignupRequestConversionSuccessViewed(
            $request,
            $actor,
            $this->safeAuditContext($request)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCreatePrefill(TenantSignupRequest $request): array
    {
        $readiness = $this->readinessService->evaluate($request);
        $package = $request->requestedPackage;

        if (!$package && filled($request->requested_package_key)) {
            $package = Package::query()->where('key', $request->requested_package_key)->first();
        }

        return [
            'signup_request_id' => $request->id,
            'name' => $request->company_name,
            'legal_name' => $request->company_name,
            'slug' => $readiness['suggested_slug'] ?? Str::slug($request->company_name),
            'panel_subdomain' => $readiness['suggested_panel_subdomain'] ?? Str::slug($request->company_name),
            'owner_name' => $request->contact_name,
            'owner_email' => $request->email,
            'owner_phone' => $request->phone,
            'status' => $request->request_type === TenantSignupRequest::TYPE_TRIAL ? 'trial' : 'active',
            'package_key' => $package?->key,
            'package_warning' => !$package
                ? 'Başvurudaki paket tercihi aktif paketler arasında bulunamadı. Kaydetmeden önce paketi kontrol edin.'
                : ($package->status !== 'active'
                    ? 'Başvurudaki paket aktif değil. Kaydetmeden önce paketi değiştirin.'
                    : null),
            'requested_modules' => $request->requested_modules_json ?? [],
            'expected_user_count' => $request->expected_user_count,
            'city' => $request->city,
            'sector' => $request->sector,
            'demo_topic' => $request->demo_topic,
            'note' => $request->note,
            'request_type_label' => TenantSignupRequest::typeOptions()[$request->request_type] ?? $request->request_type,
            'conversion_notes' => $readiness['conversion_notes'] ?? [],
        ];
    }

    public function ensureCanStartConversion(TenantSignupRequest $request, ?User $actor = null): void
    {
        $readiness = $this->readinessService->evaluate($request);

        if ($readiness['can_convert'] ?? false) {
            return;
        }

        if ($actor instanceof User) {
            $this->operationAuditService->logSignupRequestConversionBlocked(
                $request,
                $actor,
                $this->safeAuditContext($request),
                $readiness['blockers'] ?? ['Başvuru dönüşüm için hazır değil.']
            );
        }

        throw ValidationException::withMessages([
            'signup_request_id' => $readiness['blockers'][0] ?? 'Başvuru dönüşüm için hazır değil.',
        ]);
    }

    public function lockSignupRequest(?int $signupRequestId): ?TenantSignupRequest
    {
        if (!$signupRequestId) {
            return null;
        }

        return TenantSignupRequest::query()
            ->with(['requestedPackage', 'convertedTenant'])
            ->lockForUpdate()
            ->find($signupRequestId);
    }

    /**
     * @param array<string, mixed> $tenantInput
     */
    public function ensureCanCompleteConversion(TenantSignupRequest $request, array $tenantInput, User $actor): void
    {
        $baseReadiness = $this->readinessService->evaluate($request);
        $errors = [];

        if ($request->status === TenantSignupRequest::STATUS_CONVERTED || filled($request->converted_tenant_account_id)) {
            $errors['signup_request_id'] = 'Bu başvuru zaten Abone Firma’ya dönüştürülmüş.';
            throw ValidationException::withMessages($errors);
        }

        if (in_array($request->status, [TenantSignupRequest::STATUS_REJECTED, TenantSignupRequest::STATUS_ARCHIVED], true)) {
            $errors['signup_request_id'] = $request->status === TenantSignupRequest::STATUS_REJECTED
                ? 'Bu başvuru reddedildiği için dönüştürülemez.'
                : 'Bu başvuru arşivlendiği için dönüştürülemez.';
        }

        $packageKey = trim((string) ($tenantInput['package_key'] ?? ''));
        $package = $packageKey !== ''
            ? Package::query()->where('key', $packageKey)->first()
            : null;

        if (!$package || $package->status !== 'active') {
            $errors['package_key'] = 'Paket pasif veya bulunamadı.';
        }

        $panelSubdomain = trim((string) ($tenantInput['panel_subdomain'] ?? ''));
        if ($panelSubdomain !== '') {
            $subdomainExists = TenantAccount::query()
                ->where('panel_subdomain', $panelSubdomain)
                ->exists();

            if ($subdomainExists) {
                $errors['panel_subdomain'] = 'Panel adresi başka bir Abone Firma tarafından kullanılıyor.';
            }
        }

        $ownerEmail = strtolower(trim((string) ($tenantInput['owner_email'] ?? '')));
        $ownerCreateIntent = filled($tenantInput['owner_name'] ?? null)
            || filled($ownerEmail)
            || filled($tenantInput['owner_phone'] ?? null)
            || filled($tenantInput['owner_password'] ?? null);

        if ($ownerCreateIntent) {
            $existingUser = $ownerEmail !== ''
                ? User::query()->where('email', $ownerEmail)->first()
                : null;

            if ($existingUser?->isPlatformAdmin()) {
                $errors['owner_email'] = 'Firma yetkilisi e-postası sistemde platform yöneticisine bağlı.';
            } elseif ($existingUser && $existingUser->userRoles()->exists()) {
                $errors['owner_email'] = 'Firma yetkilisi e-postası sistemde başka bir kullanıcıya bağlı.';
            }
        }

        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @param array<string, string|array<int, string>> $errors
     */
    public function reportCompletionFailure(?int $signupRequestId, User $actor, array $errors, array $tenantInput = []): void
    {
        if (!$signupRequestId) {
            return;
        }

        $request = TenantSignupRequest::query()
            ->with(['requestedPackage', 'convertedTenant'])
            ->find($signupRequestId);

        if (!$request) {
            return;
        }

        $reasonBucket = collect($errors)
            ->flatten()
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        $context = $this->safeAuditContext($request, [
            'requested_package_key' => trim((string) ($tenantInput['package_key'] ?? '')),
            'requested_panel_subdomain' => trim((string) ($tenantInput['panel_subdomain'] ?? '')),
            'request_status' => $request->status,
        ]);

        if ($request->status === TenantSignupRequest::STATUS_CONVERTED || filled($request->converted_tenant_account_id)) {
            $this->operationAuditService->logSignupRequestConversionReplayBlocked(
                $request,
                $actor,
                $context,
                $reasonBucket !== [] ? $reasonBucket : ['Bu başvuru zaten Abone Firma’ya dönüştürülmüş.']
            );

            return;
        }

        $this->operationAuditService->logSignupRequestConversionBlocked(
            $request,
            $actor,
            $context,
            $reasonBucket !== [] ? $reasonBucket : ['Başvuru dönüşümü guard kuralları nedeniyle tamamlanamadı.']
        );
    }

    public function markConverted(TenantSignupRequest $request, TenantAccount $tenant, User $actor): void
    {
        $request->refresh();

        if ($request->status === TenantSignupRequest::STATUS_CONVERTED || filled($request->converted_tenant_account_id)) {
            $this->operationAuditService->logSignupRequestConversionReplayBlocked(
                $request,
                $actor,
                $this->safeAuditContext($request),
                ['Bu başvuru zaten Abone Firma’ya dönüştürülmüş.']
            );

            throw ValidationException::withMessages([
                'signup_request_id' => 'Bu başvuru daha önce Abone Firma’ya dönüştürüldü.',
            ]);
        }

        $this->tenantCompanyProfileService->updateProfile($tenant, [
            'display_name' => $tenant->name,
            'legal_name' => $tenant->legal_name ?: $request->company_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'country' => 'Türkiye',
        ]);

        TenantSetting::setValue($tenant->id, 'company_city', (string) ($request->city ?: ''), 'string');
        TenantSetting::setValue($tenant->id, 'sales_lead_signup_request_id', $request->id, 'integer');
        TenantSetting::setValue($tenant->id, 'sales_lead_request_type', $request->request_type, 'string');
        TenantSetting::setValue($tenant->id, 'sales_lead_sector', (string) ($request->sector ?: ''), 'string');
        TenantSetting::setValue($tenant->id, 'sales_lead_expected_user_count', (int) ($request->expected_user_count ?: 0), 'integer');
        TenantSetting::setValue($tenant->id, 'sales_lead_requested_modules', $request->requested_modules_json ?? [], 'array');
        TenantSetting::setValue($tenant->id, 'sales_lead_demo_topic', (string) ($request->demo_topic ?: ''), 'string');
        TenantSetting::setValue($tenant->id, 'sales_lead_note', (string) ($request->note ?: ''), 'string');
        TenantSetting::setValue($tenant->id, 'sales_lead_source', (string) ($request->source ?: 'public_landing'), 'string');

        $meta = $request->meta_json ?? [];
        $meta['converted_at'] = now()->toDateTimeString();
        $meta['converted_by_user_id'] = $actor->id;
        $meta['conversion_mode'] = 'prefill_tenant_create';
        $meta['conversion_summary'] = [
            'tenant_account_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'panel_subdomain' => $tenant->panel_subdomain,
            'package_key' => $tenant->package_key,
        ];

        $request->update([
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $tenant->id,
            'meta_json' => $meta,
        ]);

        $this->operationAuditService->logSignupRequestConverted($request->fresh(), $tenant, $actor);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function safeAuditContext(TenantSignupRequest $request, array $extra = []): array
    {
        return array_merge([
            'signup_request_id' => $request->id,
            'request_type' => $request->request_type,
            'status' => $request->status,
            'requested_package_key' => $request->requested_package_key,
            'has_requested_modules' => !empty($request->requested_modules_json),
            'has_owner_email' => filled($request->email),
            'converted_tenant_account_id' => $request->converted_tenant_account_id,
        ], $extra);
    }

    private function tenantPanelUrl(TenantAccount $tenant): string
    {
        $host = trim((string) config('prodelya_domains.panel_domain'));
        $scheme = config('prodelya_domains.force_https')
            ? 'https'
            : ((string) parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http');
        $host = $host !== '' ? $host : ((string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'prodelya_core.test');

        return $scheme . '://' . $tenant->panel_subdomain . '.' . $host . '/admin';
    }
}
