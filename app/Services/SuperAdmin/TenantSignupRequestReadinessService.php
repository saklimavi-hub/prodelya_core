<?php

namespace App\Services\SuperAdmin;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantSignupRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantSignupRequestReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(TenantSignupRequest $request): array
    {
        $request->loadMissing(['requestedPackage', 'convertedTenant']);

        $suggestedTenantName = trim((string) $request->company_name);
        $suggestedSlug = Str::slug($suggestedTenantName);
        $suggestedPanelSubdomain = $suggestedSlug;
        $normalizedCompanyName = $this->normalizeCompanyName($suggestedTenantName);

        $package = $request->requestedPackage;
        if (!$package && filled($request->requested_package_key)) {
            $package = Package::query()->where('key', $request->requested_package_key)->first();
        }

        $ownerEmail = trim((string) $request->email);
        $ownerUser = $ownerEmail !== ''
            ? User::query()->where('email', $ownerEmail)->first()
            : null;

        $duplicateCompanyMatches = $normalizedCompanyName === ''
            ? collect()
            : TenantAccount::query()
                ->select(['id', 'name', 'slug', 'panel_subdomain', 'status'])
                ->get()
                ->filter(fn (TenantAccount $tenant) => $this->normalizeCompanyName((string) $tenant->name) === $normalizedCompanyName)
                ->values();

        $duplicateSubdomainMatches = $suggestedPanelSubdomain === ''
            ? collect()
            : TenantAccount::query()
                ->select(['id', 'name', 'slug', 'panel_subdomain', 'status'])
                ->where('panel_subdomain', $suggestedPanelSubdomain)
                ->get();

        $blockers = [];
        $warnings = [];
        $conversionNotes = [];

        if ($request->status === TenantSignupRequest::STATUS_CONVERTED || filled($request->converted_tenant_account_id)) {
            $blockers[] = 'Başvuru zaten Abone Firma’ya dönüştürülmüş.';
        } elseif (in_array($request->status, [TenantSignupRequest::STATUS_REJECTED, TenantSignupRequest::STATUS_ARCHIVED], true)) {
            $blockers[] = 'Reddedilmiş veya arşivlenmiş başvuru doğrudan dönüştürülemez.';
        }

        if ($suggestedPanelSubdomain === '') {
            $blockers[] = 'Panel adresi için kullanılacak kısa kod üretilemedi.';
        } elseif ($duplicateSubdomainMatches->isNotEmpty()) {
            $blockers[] = 'Önerilen panel adresi başka bir Abone Firma tarafından kullanılıyor.';
        }

        if (!$package) {
            $blockers[] = 'Başvurudaki paket tercihi bulunamadı.';
        } elseif ($package->status !== 'active') {
            $blockers[] = 'Seçilen paket aktif değil.';
        } elseif (!(bool) ($package->is_public ?? false)) {
            $warnings[] = 'Seçilen paket public başvuru listesinde görünmüyor; manuel kontrol önerilir.';
        }

        $ownerEmailStatus = [
            'status' => 'ready',
            'label' => 'Hazır',
            'message' => 'Firma yetkilisi e-postası create akışına taşınabilir.',
            'match' => null,
        ];

        if ($ownerEmail === '') {
            $blockers[] = 'Firma yetkilisi e-postası eksik.';
            $ownerEmailStatus = [
                'status' => 'missing',
                'label' => 'Eksik',
                'message' => 'Firma yetkilisi oluşturmak için e-posta gerekli.',
                'match' => null,
            ];
        } elseif ($ownerUser instanceof User) {
            $isPlatformAdmin = (bool) $ownerUser->is_platform_admin;
            $tenantAssignments = $ownerUser->userRoles()->with('tenant')->get();

            if ($isPlatformAdmin) {
                $blockers[] = 'Bu e-posta bir platform yöneticisine ait.';
                $ownerEmailStatus = [
                    'status' => 'conflict',
                    'label' => 'Çakışma',
                    'message' => 'Platform yöneticisi e-postası firma yetkilisi olarak bağlanamaz.',
                    'match' => [
                        'user_id' => $ownerUser->id,
                        'email' => $ownerUser->email,
                        'type' => 'platform_admin',
                    ],
                ];
            } elseif ($tenantAssignments->isNotEmpty()) {
                $blockers[] = 'Bu e-posta zaten başka bir tenant kullanıcısına bağlı.';
                $ownerEmailStatus = [
                    'status' => 'conflict',
                    'label' => 'Çakışma',
                    'message' => 'Firma yetkilisi e-postası mevcut tenant kullanıcısıyla çakışıyor.',
                    'match' => [
                        'user_id' => $ownerUser->id,
                        'email' => $ownerUser->email,
                        'type' => 'tenant_user',
                        'tenants' => $tenantAssignments
                            ->map(fn ($role) => $role->tenant?->name)
                            ->filter()
                            ->values()
                            ->all(),
                    ],
                ];
            } else {
                $warnings[] = 'E-posta sistemde mevcut kullanıcıya ait; create akışında mevcut kullanıcı bağlanır.';
                $ownerEmailStatus = [
                    'status' => 'warning',
                    'label' => 'Uyarı',
                    'message' => 'E-posta sistemde mevcut kullanıcıya ait; create akışında yeni kullanıcı açılmaz.',
                    'match' => [
                        'user_id' => $ownerUser->id,
                        'email' => $ownerUser->email,
                        'type' => 'existing_user',
                    ],
                ];
            }
        }

        if ($duplicateCompanyMatches->isNotEmpty()) {
            $warnings[] = 'Aynı veya çok benzer firma adıyla kayıtlı Abone Firma bulundu.';
        }

        $requestedModulesSummary = collect($request->requested_modules_json ?? [])
            ->map(fn ($moduleKey) => (string) $moduleKey)
            ->filter()
            ->values()
            ->all();

        if ($requestedModulesSummary !== []) {
            $conversionNotes[] = 'Seçilen modüller başvuru tercihi olarak taşınır; otomatik modül açılışı yapılmaz.';
        }

        if ($request->request_type === TenantSignupRequest::TYPE_TRIAL) {
            $trialDays = max(1, (int) ($package?->trial_days ?: 30));
            $conversionNotes[] = 'Deneme başvurusu için önerilen tenant durumu trial, önerilen deneme süresi ' . $trialDays . ' gündür.';
        }

        if ($request->request_type === TenantSignupRequest::TYPE_DEMO) {
            $conversionNotes[] = 'Demo başvurusu create akışında otomatik trial başlatmaz; son karar Super Admin’dedir.';
        }

        $packageStatus = [
            'status' => !$package ? 'missing' : ($package->status === 'active' ? ((bool) ($package->is_public ?? false) ? 'ready' : 'warning') : 'conflict'),
            'label' => !$package ? 'Eksik' : ($package->status === 'active' ? ((bool) ($package->is_public ?? false) ? 'Hazır' : 'Uyarı') : 'Çakışma'),
            'message' => !$package
                ? 'Paket tercihi create ekranına güvenli şekilde taşınamaz.'
                : ($package->status === 'active'
                    ? ((bool) ($package->is_public ?? false)
                        ? 'Seçilen paket aktif ve başvuru akışı için kullanılabilir.'
                        : 'Paket aktif ama public başvuru yüzünde görünmüyor; manuel kontrol önerilir.')
                    : 'Seçilen paket aktif değil.'),
            'package_name' => $package?->name ?? ($request->requested_package_key ?: 'Belirtilmedi'),
        ];

        $cta = $this->ctaState($request, $blockers, $warnings);

        $severity = !empty($blockers)
            ? 'blocker'
            : (!empty($warnings) ? 'warning' : 'ready');

        return [
            'can_convert' => empty($blockers),
            'severity' => $severity,
            'warnings' => array_values(array_unique($warnings)),
            'blockers' => array_values(array_unique($blockers)),
            'suggested_tenant_name' => $suggestedTenantName,
            'suggested_slug' => $suggestedSlug,
            'suggested_panel_subdomain' => $suggestedPanelSubdomain,
            'package_status' => $packageStatus,
            'owner_email_status' => $ownerEmailStatus,
            'duplicate_company_matches' => $duplicateCompanyMatches
                ->map(fn (TenantAccount $tenant) => $this->tenantMatchPayload($tenant))
                ->all(),
            'duplicate_subdomain_matches' => $duplicateSubdomainMatches
                ->map(fn (TenantAccount $tenant) => $this->tenantMatchPayload($tenant))
                ->all(),
            'requested_modules_summary' => $requestedModulesSummary,
            'conversion_notes' => $conversionNotes,
            'status_label' => TenantSignupRequest::statusOptions()[$request->status] ?? $request->status,
            'request_type_label' => TenantSignupRequest::typeOptions()[$request->request_type] ?? $request->request_type,
            'suggested_status' => $request->request_type === TenantSignupRequest::TYPE_TRIAL ? 'trial' : null,
            'trial_days' => max(1, (int) ($package?->trial_days ?: 30)),
            'cta' => $cta,
            'summary_badge' => $this->summaryBadge($request, $blockers, $warnings, $packageStatus, $ownerEmailStatus, $duplicateCompanyMatches),
        ];
    }

    private function ctaState(TenantSignupRequest $request, array $blockers, array $warnings): array
    {
        if ($request->convertedTenant) {
            return [
                'state' => 'converted',
                'label' => 'Abone Firma Aç',
                'enabled' => true,
                'reasons' => [],
            ];
        }

        if ($blockers !== []) {
            return [
                'state' => 'blocked',
                'label' => 'Dönüştürülemez',
                'enabled' => false,
                'reasons' => $blockers,
            ];
        }

        if ($warnings !== []) {
            return [
                'state' => 'warning',
                'label' => 'Uyarıları Kontrol Et ve Dönüştür',
                'enabled' => true,
                'reasons' => $warnings,
            ];
        }

        return [
            'state' => 'ready',
            'label' => 'Abone Firmaya Dönüştür',
            'enabled' => true,
            'reasons' => [],
        ];
    }

    private function summaryBadge(
        TenantSignupRequest $request,
        array $blockers,
        array $warnings,
        array $packageStatus,
        array $ownerEmailStatus,
        Collection $duplicateCompanyMatches
    ): array {
        if ($request->convertedTenant || $request->status === TenantSignupRequest::STATUS_CONVERTED) {
            return ['label' => 'Dönüştürüldü', 'tone' => 'green'];
        }

        if ($packageStatus['status'] === 'missing' || $packageStatus['status'] === 'conflict') {
            return ['label' => 'Paket Eksik', 'tone' => 'red'];
        }

        if ($ownerEmailStatus['status'] === 'conflict') {
            return ['label' => 'E-posta Çakışıyor', 'tone' => 'red'];
        }

        if ($blockers !== []) {
            return ['label' => 'Dönüştürülemez', 'tone' => 'red'];
        }

        if ($duplicateCompanyMatches->isNotEmpty()) {
            return ['label' => 'Firma Benzer', 'tone' => 'amber'];
        }

        if ($warnings !== []) {
            return ['label' => 'Uyarı', 'tone' => 'amber'];
        }

        return ['label' => 'Hazır', 'tone' => 'green'];
    }

    private function normalizeCompanyName(string $value): string
    {
        $ascii = Str::ascii($value);
        $normalized = Str::of($ascii)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        return trim($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantMatchPayload(TenantAccount $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'panel_subdomain' => $tenant->panel_subdomain,
            'status' => $tenant->status,
        ];
    }
}
