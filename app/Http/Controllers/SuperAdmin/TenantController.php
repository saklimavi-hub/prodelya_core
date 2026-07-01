<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSignupRequest;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantCatalogProductVariant;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use App\Services\ModuleFeatureCatalogService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\PackageCatalogService;
use App\Services\SuperAdminOperationAuditService;
use App\Services\SuperAdmin\TenantSignupConversionService;
use App\Services\TenantAccessService;
use App\Services\TenantBillingLedgerService;
use App\Services\TenantCompanyProfileService;
use App\Services\TenantOnboardingDefaultsService;
use App\Services\TenantOnboardingStatusService;
use App\Services\TenantSubscriptionStatusService;
use App\Services\TenantUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantController extends Controller
{
    private const SUBSCRIPTION_TRIAL_START_SETTING = 'subscription_trial_starts_at';
    private const SUBSCRIPTION_TRIAL_END_SETTING = 'subscription_trial_ends_at';
    private const SUBSCRIPTION_PACKAGE_START_SETTING = 'subscription_package_starts_at';
    private const SUBSCRIPTION_PACKAGE_END_SETTING = 'subscription_package_ends_at';
    private const SUBSCRIPTION_STATUS_NOTE_SETTING = 'subscription_status_note';
    private const SUBSCRIPTION_SUSPENDED_REASON_SETTING = 'subscription_suspended_reason';
    private const SUBSCRIPTION_STATUS_UPDATED_AT_SETTING = 'subscription_status_updated_at';
    private const SUBSCRIPTION_LIFECYCLE_STATE_SETTING = 'subscription_lifecycle_state';
    private const DOMAIN_PANEL_STATUS_SETTING = 'domain_panel_status';
    private const DOMAIN_CUSTOM_STATUS_SETTING = 'domain_custom_status';
    private const DOMAIN_CUSTOM_SSL_STATUS_SETTING = 'domain_custom_ssl_status';
    private const DOMAIN_PORTAL_STATUS_SETTING = 'domain_portal_status';
    private const DOMAIN_PORTAL_SSL_STATUS_SETTING = 'domain_portal_ssl_status';
    private const DOMAIN_OPERATIONS_NOTE_SETTING = 'domain_operations_note';

    private const LIMIT_KEYS = [
        'users' => 'Kullanicilar',
        'current_accounts' => 'Cari Kartlar',
        'companies' => 'Firmalar',
        'products' => 'Urunler',
        'supplier_feeds' => 'Tedarikci Feed',
        'orders' => 'Siparisler',
        'storage_mb' => 'Depolama',
        'custom_domains' => 'Ozel Domainler',
        'api_tokens' => 'API Token',
    ];

    private const RESERVED_TENANT_IDENTIFIERS = [
        'admin',
        'app',
        'api',
        'www',
        'mail',
        'support',
        'super-admin',
        'superadmin',
        'demo',
    ];

    public function __construct(
        protected ModuleFeatureCatalogService $catalogService,
        protected PackageCatalogService $packageCatalogService,
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
        protected AdminMenuService $adminMenuService,
        protected SuperAdminOperationAuditService $operationAuditService,
        protected TenantSignupConversionService $tenantSignupConversionService,
        protected TenantBillingLedgerService $tenantBillingLedgerService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
        protected TenantOnboardingDefaultsService $tenantOnboardingDefaultsService,
        protected TenantOnboardingStatusService $tenantOnboardingStatusService,
        protected TenantNotificationSettingsService $tenantNotificationSettingsService,
    ) {
    }

    public function index(Request $request): View
    {
        $search = Str::lower(trim((string) $request->input('search')));
        $status = trim((string) $request->input('status'));
        $package = trim((string) $request->input('package'));
        $usageWarningOnly = $request->boolean('usage_warning');
        $onboardingOnly = $request->boolean('onboarding_incomplete');
        $domainGapOnly = $request->boolean('domain_missing');
        $endingSoonOnly = $request->boolean('ending_soon');

        $tenants = TenantAccount::query()
            ->with(['modules', 'package'])
            ->orderBy('name')
            ->get()
            ->map(fn (TenantAccount $tenant) => $this->decorateTenantListRow($tenant))
            ->filter(function (TenantAccount $tenant) use (
                $search,
                $status,
                $package,
                $usageWarningOnly,
                $onboardingOnly,
                $domainGapOnly,
                $endingSoonOnly
            ): bool {
                if ($search !== '') {
                    $haystack = Str::lower(implode(' ', array_filter([
                        $tenant->name,
                        $tenant->legal_name,
                        $tenant->panel_subdomain,
                        $tenant->custom_domain,
                        $tenant->portal_domain,
                        $tenant->getAttribute('owner_name'),
                        $tenant->getAttribute('package_label'),
                    ])));

                    if (!str_contains($haystack, $search)) {
                        return false;
                    }
                }

                if ($status !== '' && $tenant->getAttribute('subscription_status') !== $status) {
                    return false;
                }

                if ($package !== '' && ($tenant->package_key ?: 'core') !== $package) {
                    return false;
                }

                if ($usageWarningOnly && !$tenant->getAttribute('has_usage_warning')) {
                    return false;
                }

                if ($onboardingOnly && $tenant->getAttribute('onboarding_ready')) {
                    return false;
                }

                if ($domainGapOnly && !$tenant->getAttribute('has_domain_gap')) {
                    return false;
                }

                if ($endingSoonOnly && !$tenant->getAttribute('is_ending_soon')) {
                    return false;
                }

                return true;
            })
            ->values();

        return view('super-admin.tenants.index', [
            'tenants' => $tenants,
            'packages' => Package::query()
                ->whereNotIn('status', ['archived'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'package' => $package,
                'usage_warning' => $usageWarningOnly,
                'onboarding_incomplete' => $onboardingOnly,
                'domain_missing' => $domainGapOnly,
                'ending_soon' => $endingSoonOnly,
            ],
            'statusOptions' => array_merge(
                ['' => 'Tüm durumlar'],
                [
                    'active' => 'Aktif',
                    'trial' => 'Deneme',
                    'expired' => 'Süresi Dolmuş',
                    'suspended' => 'Askıda',
                    'passive' => 'Pasif',
                ]
            ),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $packages = $this->packageCatalogService->activePackages()
            ->loadMissing(['modules', 'features', 'limits']);
        $signupRequest = null;
        $prefill = [];

        if ($request->filled('signup_request_id')) {
            $platformAdmin = $request->user();
            abort_unless($platformAdmin instanceof User, 403);

            try {
                $signupRequest = $this->tenantSignupConversionService->resolveSignupRequestForPrefill(
                    (int) $request->input('signup_request_id'),
                    $platformAdmin
                );
                $prefill = $this->tenantSignupConversionService->buildCreatePrefill($signupRequest);
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $message = $messages['signup_request_id'][0] ?? 'Başvuru dönüşüm için hazır değil.';

                if ($request->filled('signup_request_id') && TenantSignupRequest::query()->whereKey((int) $request->input('signup_request_id'))->exists()) {
                    return redirect()
                        ->route('admin.super.signup-requests.show', (int) $request->input('signup_request_id'))
                        ->with('error', $message);
                }

                return redirect()
                    ->route('admin.super.signup-requests.index')
                    ->with('error', $message);
            }
        }

        return view('super-admin.tenants.create', [
            'packages' => $packages,
            'packageSummaries' => $packages->mapWithKeys(function (Package $package): array {
                return [
                    $package->key => [
                        'name' => $package->name,
                        'description' => $package->description,
                        'trial_days' => $package->trial_days,
                        'monthly_price' => $package->formattedPrice('monthly'),
                        'yearly_price' => $package->formattedPrice('yearly'),
                        'module_count' => count($this->packageCatalogService->packageModules($package)),
                        'feature_count' => count($this->packageCatalogService->packageFeatures($package)),
                        'limit_count' => count($this->packageCatalogService->packageLimits($package)),
                    ],
                ];
            })->all(),
            'defaultValues' => [
                'name' => $prefill['name'] ?? '',
                'legal_name' => $prefill['legal_name'] ?? '',
                'slug' => $prefill['slug'] ?? '',
                'panel_subdomain' => $prefill['panel_subdomain'] ?? '',
                'custom_domain' => '',
                'portal_domain' => '',
                'status' => $prefill['status'] ?? 'active',
                'package_key' => $prefill['package_key'] ?? '',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_mode' => 'create',
                'owner_name' => $prefill['owner_name'] ?? '',
                'owner_email' => $prefill['owner_email'] ?? '',
                'owner_phone' => $prefill['owner_phone'] ?? '',
            ],
            'statusOptions' => $this->statusOptions(),
            'localeOptions' => $this->localeOptions(),
            'timezoneOptions' => $this->timezoneOptions(),
            'centralPreviewHost' => $this->centralPreviewHost(),
            'localHostPreviewNote' => $this->localHostPreviewNote(),
            'signupRequest' => $signupRequest,
            'signupRequestPrefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $normalized = $this->normalizeTenantCreateInput($request);
        $validated = $this->validateTenantCreateInput($normalized);
        $platformAdmin = $request->user();

        abort_unless($platformAdmin instanceof User, 403);

        $generatedPassword = null;
        $ownerSummary = null;
        $onboardingResult = null;
        $signupRequestId = $validated['signup_request_id'] ?? null;

        try {
            $tenant = DB::transaction(function () use (
                $validated,
                $signupRequestId,
                $platformAdmin,
                &$generatedPassword,
                &$ownerSummary,
                &$onboardingResult
            ): TenantAccount {
                $signupRequest = $this->tenantSignupConversionService->lockSignupRequest($signupRequestId);

                if ($signupRequest instanceof TenantSignupRequest) {
                    $this->tenantSignupConversionService->ensureCanCompleteConversion($signupRequest, $validated, $platformAdmin);
                }

                $tenant = TenantAccount::query()->create([
                    'name' => $validated['name'],
                    'legal_name' => $validated['legal_name'] ?: null,
                    'slug' => $validated['slug'],
                    'panel_subdomain' => $validated['panel_subdomain'],
                    'custom_domain' => $validated['custom_domain'] ?: null,
                    'portal_domain' => $validated['portal_domain'] ?: null,
                    'status' => $this->normalizeStorageStatus((string) $validated['status']),
                    'package_key' => $validated['package_key'],
                    'default_locale' => $validated['default_locale'],
                    'default_currency' => $validated['default_currency'],
                    'timezone' => $validated['timezone'],
                    'number_format_locale' => $this->numberFormatLocaleFor($validated['default_locale']),
                ]);

                $this->persistInitialSubscriptionLifecycleSettings($tenant, $validated);

                if ($this->hasOwnerCreateIntent($validated)) {
                    $ownerProvision = $this->provisionOwnerForTenant($tenant, $validated);
                    $generatedPassword = $ownerProvision['generated_password'];
                    $ownerSummary = $ownerProvision['summary'];
                }

                $onboardingResult = $this->tenantOnboardingDefaultsService->prepareDefaults($tenant, $platformAdmin);

                if ($signupRequest instanceof TenantSignupRequest) {
                    $this->tenantSignupConversionService->markConverted($signupRequest, $tenant, $platformAdmin);
                }

                return $tenant;
            });
        } catch (ValidationException $exception) {
            $this->tenantSignupConversionService->reportCompletionFailure(
                $signupRequestId,
                $platformAdmin,
                $exception->errors(),
                $validated
            );

            throw $exception;
        }

        $signupRequest = $signupRequestId
            ? TenantSignupRequest::query()->find($signupRequestId)
            : null;

        $successMessage = $signupRequest
            ? ($ownerSummary
                ? 'Başvuru Abone Firma’ya dönüştürüldü, owner kullanıcı ve başlangıç ayarları hazırlandı.'
                : 'Başvuru Abone Firma’ya dönüştürüldü. Owner bilgisi eksikse onboarding ekranında görünür.')
            : ($ownerSummary
            ? 'Abone Firma, owner kullanıcı ve başlangıç ayarları hazırlandı.'
            : 'Abone Firma oluşturuldu. Owner kullanıcı eksikse onboarding durumu ekranda görünecektir.');

        $redirect = $signupRequest
            ? redirect()
                ->route('admin.super.signup-requests.conversion-success', $signupRequest)
                ->with('success', 'Abone Firma oluşturuldu. Dönüşüm özeti ve onboarding görünürlüğü hazır.')
            : redirect()
                ->route('admin.super.tenants.show', $tenant)
                ->with('success', $successMessage);

        if ($generatedPassword !== null && !$signupRequest) {
            $redirect->with('owner_temporary_password', $generatedPassword);
        }

        if ($ownerSummary !== null) {
            $redirect->with('owner_create_summary', $ownerSummary);
        }

        if ($onboardingResult !== null) {
            $redirect->with('onboarding_defaults_summary', $onboardingResult->summary());
        }

        return $redirect;
    }

    public function show(TenantAccount $tenant): View
    {
        return view('super-admin.tenants.show', $this->detailPayload($tenant));
    }

    public function edit(TenantAccount $tenant): View
    {
        return view('super-admin.tenants.edit', $this->detailPayload($tenant));
    }

    public function createOwner(TenantAccount $tenant): View
    {
        return view('super-admin.tenants.owner-create', $this->detailPayload($tenant) + [
            'defaultOwnerValues' => [
                'role' => 'tenant_owner',
                'is_active' => true,
                'send_invite' => false,
            ],
        ]);
    }

    public function storeOwner(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $role = $this->ensureDefaultRole('tenant_owner');
        $normalized = $this->normalizeOwnerInput($request);
        $validated = $this->validateOwnerInput($normalized, $tenant);
        $generatedPassword = null;

        DB::transaction(function () use ($tenant, $validated, $role, &$generatedPassword): void {
            if ($this->ownerAssignmentQuery($tenant)->exists()) {
                validator([], [])->after(function ($validator): void {
                    $validator->errors()->add('owner', 'Bu tenant için owner kullanıcı zaten oluşturulmuş.');
                })->validate();
            }

            $password = $validated['password'] ?: Str::password(16);
            $generatedPassword = $validated['password'] ? null : $password;

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
                'password' => $password,
                'is_platform_admin' => false,
            ]);

            UserRole::query()->create([
                'user_id' => $user->id,
                'tenant_account_id' => $tenant->id,
                'role_id' => $role->id,
            ]);
        });

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma owner kullanıcısı oluşturuldu.')
            ->with('owner_temporary_password', $generatedPassword);
    }

    public function prepareDefaults(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $platformAdmin = $request->user();

        abort_unless($platformAdmin instanceof User, 403);

        $result = $this->tenantOnboardingDefaultsService->prepareDefaults($tenant, $platformAdmin);

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma başlangıç ayarları hazırlandı.')
            ->with('onboarding_defaults_summary', $result->summary());
    }

    public function update(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $normalized = $this->normalizeTenantUpdateInput($request, $tenant);
        $validated = $this->validateTenantUpdateInput($normalized, $tenant);
        $before = $this->tenantAuditSnapshot($tenant);

        $packageKey = trim((string) ($validated['package_key'] ?? ''));

        if ($packageKey !== '') {
            $package = Package::query()->where('key', $packageKey)->first();

            if (!$package) {
                return back()
                    ->withInput()
                    ->withErrors(['package_key' => 'Secilen paket bulunamadi.']);
            }

            if ($package->status !== 'active') {
                return back()
                    ->withInput()
                    ->withErrors(['package_key' => 'Pasif, planlanan veya arsiv paketler tenant atamasi icin kullanilamaz.']);
            }
        }

        DB::transaction(function () use ($tenant, $validated, $packageKey): void {
            $tenant->update([
                'name' => $validated['name'],
                'legal_name' => $validated['legal_name'] ?: null,
                'panel_subdomain' => $validated['panel_subdomain'],
                'custom_domain' => $validated['custom_domain'] ?: null,
                'portal_domain' => $validated['portal_domain'] ?: null,
                'package_key' => $packageKey !== '' ? $packageKey : null,
                'status' => $this->normalizeStorageStatus((string) $validated['status']),
                'default_locale' => $validated['default_locale'],
                'default_currency' => $validated['default_currency'],
                'timezone' => $validated['timezone'],
                'number_format_locale' => $this->numberFormatLocaleFor($validated['default_locale']),
            ]);

            $this->tenantCompanyProfileService->updateProfile($tenant, [
                'display_name' => $validated['company_display_name'] ?: $validated['name'],
                'legal_name' => $validated['company_legal_name'] ?: $validated['legal_name'],
                'tax_office' => $validated['company_tax_office'],
                'tax_number' => $validated['company_tax_number'],
                'phone' => $validated['company_phone'],
                'email' => $validated['company_email'],
                'address' => $validated['company_address'],
                'city' => $validated['company_city'],
                'district' => $validated['company_district'],
                'country' => $validated['company_country'],
                'postal_code' => $validated['company_postal_code'],
            ]);

            $this->persistSubscriptionLifecycleSettings($tenant, $validated);
        });

        $tenant->refresh();
        $this->operationAuditService->logTenantUpdated(
            tenant: $tenant,
            actor: $request->user(),
            before: $before,
            after: $this->tenantAuditSnapshot($tenant)
        );

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma ayarları, panel bilgileri ve paket durumu güncellendi.');
    }

    public function updateModules(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $rules = [];

        foreach (array_keys($this->catalogService->modules()) as $moduleKey) {
            $rules["overrides.$moduleKey"] = ['nullable', Rule::in(['default', 'enabled', 'disabled'])];
        }

        $validated = $request->validate($rules);
        $payload = $validated['overrides'] ?? [];

        DB::transaction(function () use ($tenant, $payload): void {
            foreach ($payload as $moduleKey => $state) {
                $normalizedKey = $this->catalogService->normalizeModuleKey($moduleKey);
                $module = $this->catalogService->getModule($normalizedKey);

                if (!$module || ($module['is_core'] ?? false) || in_array($module['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                    TenantModule::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('module_key', $normalizedKey)
                        ->whereNull('feature_key')
                        ->delete();
                    continue;
                }

                if ($state === 'default') {
                    TenantModule::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('module_key', $normalizedKey)
                        ->whereNull('feature_key')
                        ->delete();
                    continue;
                }

                TenantModule::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'module_key' => $normalizedKey,
                        'feature_key' => null,
                    ],
                    [
                        'is_enabled' => $state === 'enabled',
                        'limit_value' => null,
                        'meta' => [
                            'updated_via' => 'super_admin_tenant_package_override',
                        ],
                    ]
                );
            }
        });

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma modül override ayarları güncellendi.');
    }

    public function updateFeatures(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $rules = [];

        foreach ($this->catalogService->features() as $moduleKey => $features) {
            foreach (array_keys($features) as $featureKey) {
                $rules["overrides.$featureKey"] = ['nullable', Rule::in(['default', 'enabled', 'disabled'])];
            }
        }

        $validated = $request->validate($rules);
        $payload = $validated['overrides'] ?? [];

        DB::transaction(function () use ($tenant, $payload): void {
            foreach ($payload as $featureKey => $state) {
                $normalizedFeatureKey = $this->catalogService->normalizeFeatureKey($featureKey);
                $feature = $this->catalogService->getFeature($normalizedFeatureKey);
                $moduleKey = $this->resolveModuleKeyForFeature($normalizedFeatureKey);

                if (!$feature || !$moduleKey || in_array($feature['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                    TenantModule::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('feature_key', $normalizedFeatureKey)
                        ->delete();
                    continue;
                }

                if ($state === 'default') {
                    TenantModule::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('module_key', $moduleKey)
                        ->where('feature_key', $normalizedFeatureKey)
                        ->delete();
                    continue;
                }

                TenantModule::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'module_key' => $moduleKey,
                        'feature_key' => $normalizedFeatureKey,
                    ],
                    [
                        'is_enabled' => $state === 'enabled',
                        'limit_value' => null,
                        'meta' => [
                            'updated_via' => 'super_admin_tenant_package_override',
                        ],
                    ]
                );
            }
        });

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma feature override ayarları güncellendi.');
    }

    public function updateLimits(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $rules = [];

        foreach (array_keys(self::LIMIT_KEYS) as $limitKey) {
            $rules["limits.$limitKey.mode"] = ['nullable', Rule::in(['default', 'value', 'unlimited'])];
            $rules["limits.$limitKey.value"] = ['nullable', 'integer', 'min:0'];
        }

        $validated = $request->validate($rules);
        $payload = $validated['limits'] ?? [];

        DB::transaction(function () use ($tenant, $payload): void {
            foreach ($payload as $limitKey => $row) {
                $mode = $row['mode'] ?? 'default';
                $settingKey = 'limit_' . $limitKey;

                if ($mode === 'default') {
                    TenantSetting::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('key', $settingKey)
                        ->delete();
                    continue;
                }

                if ($mode === 'unlimited') {
                    TenantSetting::setValue($tenant->id, $settingKey, 'unlimited', 'string');
                    continue;
                }

                TenantSetting::setValue($tenant->id, $settingKey, (int) ($row['value'] ?? 0), 'integer');
            }
        });

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Abone Firma limit override ayarları güncellendi.');
    }

    private function detailPayload(TenantAccount $tenant): array
    {
        $tenant->loadMissing(['modules', 'package']);

        $packages = Package::query()
            ->whereNotIn('status', ['archived'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $package = $tenant->package;
        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $moduleRows = $this->moduleRows($tenant, $package);
        $featureRows = $this->featureRows($tenant, $package);
        $limitRows = $this->limitRows($tenant, $package);
        $companyProfile = $this->tenantCompanyProfileService->getProfile($tenant);
        $settings = $tenant->settings()->pluck('value', 'key')->toArray();
        $summary = $this->tenantAccessService->effectiveAccessSummary($tenant);
        $tenantMenuItems = $this->adminMenuService->tenantMenu($tenant, auth()->user());
        $ownerAssignment = $this->ownerAssignmentQuery($tenant)->first();
        $ownerUser = $ownerAssignment?->user;
        $ownerRole = $ownerAssignment?->role;
        $teamAssignments = UserRole::query()
            ->with(['user', 'role'])
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->get();
        $onboardingStatus = $this->tenantOnboardingStatusService->forTenant($tenant);
        $isDemoTenant = $this->isDemoTenant($tenant);
        $tenantAdminPreviewUrl = 'http://' . $tenant->panel_subdomain . '.' . $this->centralPreviewHost() . '/admin';
        $tenantPortalPreviewUrl = filled($tenant->portal_domain)
            ? 'http://' . $tenant->portal_domain . '/musteri-giris'
            : (filled($tenant->panel_subdomain) ? 'http://' . $tenant->panel_subdomain . '.' . $this->centralPreviewHost() . '/musteri-giris' : null);
        $activeModuleCount = count(array_filter($summary['modules'] ?? [], fn (array $row) => (bool) ($row['enabled'] ?? false)));
        $hasSupplierAccess = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->exists();
        $hasCatalogProjection = $tenant->catalogProducts()->exists()
            || TenantCatalogProductVariant::query()->where('tenant_account_id', $tenant->id)->exists();
        $hasFinanceAccess = $ownerAssignment !== null
            && $this->tenantAccessService->canAccessModule($tenant, 'finance')
            && $this->tenantAccessService->canAccessFeature($tenant, 'finance_summary', 'finance');
        $loginPanelReady = $this->subscriptionStatusService->canAccessAdmin($tenant)
            && filled($tenant->panel_subdomain)
            && $ownerAssignment !== null;
        $usageSnapshot = $this->tenantUsageService->getUsageSnapshot($tenant);
        $usageWarnings = $this->tenantUsageService->warningItems($tenant);
        $enabledModules = collect($moduleRows)
            ->filter(fn (array $row) => (bool) ($row['effective_enabled'] ?? false) && !($row['is_core'] ?? false));
        $disabledModules = collect($moduleRows)
            ->filter(fn (array $row) => !($row['effective_enabled'] ?? false) && !($row['is_core'] ?? false));
        $overriddenModules = collect($moduleRows)->filter(fn (array $row) => $row['override_state'] !== 'default');
        $overriddenFeatures = collect($featureRows)->filter(fn (array $row) => $row['override_state'] !== 'default');
        $createdAt = $tenant->created_at?->format('d.m.Y H:i');
        $trialEndsAt = $tenant->getAttribute('trial_ends_at');
        $endDate = $tenant->getAttribute('end_date') ?: $tenant->getAttribute('expires_at');
        $lifecycleSettings = $this->subscriptionLifecycleSettings($tenant, $subscription);
        $domainLifecycleSettings = $this->domainLifecycleSettings($tenant);
        $hasFirstCatalogProduct = $tenant->catalogProducts()->exists();
        $settingsUpdatedAt = $tenant->settings()->latest('updated_at')->value('updated_at');
        $portalEnabled = $this->tenantAccessService->canAccessModule($tenant, 'customer_portal');
        $settingsCount = $tenant->settings()->count();
        $printSettingsCount = $tenant->printSettings()->count();
        $notificationCenterEnabled = $this->tenantAccessService->canAccessModule($tenant, 'notification_center');
        $notificationSummary = $this->tenantNotificationSettingsService->readinessSummary($tenant);
        $storageReadiness = $this->buildStorageReadinessSummary($tenant, $settings);
        $billingSummary = $this->tenantBillingLedgerService->summary($tenant);
        $teamSummary = [
            'total_users' => $teamAssignments->pluck('user_id')->unique()->count(),
            'active_users' => $teamAssignments->pluck('user_id')->unique()->count(),
            'owner_ready' => (bool) ($onboardingStatus['has_active_owner'] ?? false),
            'has_finance_user' => (bool) ($onboardingStatus['has_finance_user'] ?? false),
            'has_operations_user' => (bool) ($onboardingStatus['has_operations_user'] ?? false),
            'last_user_created_at' => optional($teamAssignments->sortByDesc(fn (UserRole $assignment) => $assignment->user?->created_at)->first()?->user?->created_at)?->format('d.m.Y H:i') ?: 'Takip edilmiyor',
        ];
        $teamSummary['status_label'] = $teamSummary['owner_ready'] && $teamSummary['active_users'] > 0 ? 'Hazır' : 'Eksik';
        $tenantSettingsPreviewUrl = $loginPanelReady ? $tenantAdminPreviewUrl . '/settings' : null;
        $tenantCompanyProfilePreviewUrl = $loginPanelReady ? $tenantAdminPreviewUrl . '/settings/company-profile' : null;
        $tenantPrintSettingsPreviewUrl = $loginPanelReady && $this->tenantAccessService->canAccessModule($tenant, 'print_settings')
            ? $tenantAdminPreviewUrl . '/settings/print-settings'
            : null;
        $tenantSmtpPreviewUrl = $loginPanelReady && $notificationCenterEnabled
            ? $tenantAdminPreviewUrl . '/settings/notifications/smtp'
            : null;
        $tenantWhatsappPreviewUrl = $loginPanelReady && $notificationCenterEnabled
            ? $tenantAdminPreviewUrl . '/settings/notifications/whatsapp'
            : null;
        $tenantUsersPreviewUrl = $loginPanelReady ? $tenantAdminPreviewUrl . '/users' : null;
        $opsCenterSections = [
            [
                'id' => 'ops-genel',
                'title' => 'Genel ve Firma',
                'subtitle' => 'Abone Firma kimliği, panel erişimi ve owner readiness aynı blokta görünür.',
                'items' => [
                    ['label' => 'Firma Görünen Adı', 'value' => $companyProfile['display_name'] ?: $tenant->name],
                    ['label' => 'Yasal Ünvan', 'value' => $companyProfile['legal_name'] ?: 'Ayar gerekiyor'],
                    ['label' => 'Owner', 'value' => $ownerUser?->name ?: 'Owner eksik'],
                    ['label' => 'Ekip', 'value' => $teamSummary['active_users'] > 0 ? $teamSummary['active_users'] . ' aktif kullanıcı' : 'Aktif kullanıcı yok'],
                ],
                'actions' => array_values(array_filter([
                    $tenantCompanyProfilePreviewUrl ? ['label' => 'Firma Bilgilerini Aç', 'url' => $tenantCompanyProfilePreviewUrl, 'new_tab' => true] : null,
                    $tenantUsersPreviewUrl ? ['label' => 'Kullanıcıları Aç', 'url' => $tenantUsersPreviewUrl, 'new_tab' => true] : null,
                ])),
            ],
            [
                'id' => 'ops-domain',
                'title' => 'Domain ve Panel',
                'subtitle' => 'Panel alt alanı, özel domain ve portal domaini tek bakışta takip edilir.',
                'items' => [
                    ['label' => 'Panel Alt Alanı', 'value' => $tenant->panel_subdomain ?: 'Tanımlı değil'],
                    ['label' => 'Panel Adresi', 'value' => $tenantAdminPreviewUrl],
                    ['label' => 'Özel Domain', 'value' => $tenant->custom_domain ?: 'Tanımlı değil'],
                    ['label' => 'Portal Domaini', 'value' => $tenant->portal_domain ?: 'Tanımlı değil'],
                ],
                'actions' => array_values(array_filter([
                    $loginPanelReady ? ['label' => 'Paneli Aç', 'url' => $tenantAdminPreviewUrl, 'new_tab' => true] : null,
                    $tenantPortalPreviewUrl ? ['label' => 'Portali Aç', 'url' => $tenantPortalPreviewUrl, 'new_tab' => true] : null,
                ])),
            ],
            [
                'id' => 'ops-iletisim',
                'title' => 'İletişim ve Belge',
                'subtitle' => 'SMTP, WhatsApp, baskı ayarları ve tenant settings tek operasyon kartında toplanır.',
                'items' => [
                    ['label' => 'SMTP', 'value' => $notificationSummary['smtp']['status_label']],
                    ['label' => 'WhatsApp', 'value' => $notificationSummary['whatsapp']['status_label']],
                    ['label' => 'Baskı Ayarları', 'value' => $printSettingsCount > 0 ? $printSettingsCount . ' kayıt' : 'Ayar gerekiyor'],
                    ['label' => 'Tenant Settings', 'value' => ($onboardingStatus['has_tenant_settings_defaults'] ?? false) ? 'Hazır' : 'Eksik'],
                ],
                'actions' => array_values(array_filter([
                    $tenantSettingsPreviewUrl ? ['label' => 'Ayarları Aç', 'url' => $tenantSettingsPreviewUrl, 'new_tab' => true] : null,
                    $tenantPrintSettingsPreviewUrl ? ['label' => 'Baskı Ayarları', 'url' => $tenantPrintSettingsPreviewUrl, 'new_tab' => true] : null,
                    $tenantSmtpPreviewUrl ? ['label' => 'SMTP', 'url' => $tenantSmtpPreviewUrl, 'new_tab' => true] : null,
                    $tenantWhatsappPreviewUrl ? ['label' => 'WhatsApp', 'url' => $tenantWhatsappPreviewUrl, 'new_tab' => true] : null,
                ])),
            ],
            [
                'id' => 'ops-abonelik',
                'title' => 'Abonelik ve Kullanım',
                'subtitle' => 'Lifecycle, paket, limit ve operasyon uyarıları karar verirken birlikte okunur.',
                'items' => [
                    ['label' => 'Lifecycle', 'value' => $lifecycleSettings['effective_state_label']],
                    ['label' => 'Paket', 'value' => $package?->name ?? ($tenant->package_key ?: 'Core')],
                    ['label' => 'Kalan Gün', 'value' => $subscription['days_remaining'] ?? 'Takip edilmiyor'],
                    ['label' => 'Limit Uyarısı', 'value' => empty($usageWarnings) ? 'Yok' : count($usageWarnings) . ' uyarı'],
                ],
                'actions' => [
                    ['label' => 'Aboneliği Düzenle', 'url' => route('admin.super.tenants.edit', $tenant) . '#abonelik-durumu', 'new_tab' => false],
                    ['label' => 'Modül / Limit Yönet', 'url' => route('admin.super.tenants.edit', $tenant) . '#modul-limit-yonetimi', 'new_tab' => false],
                ],
            ],
            [
                'id' => 'ops-cari',
                'title' => 'SaaS Cari',
                'subtitle' => 'Paket bedeli, ek hizmet ve tahsilat hareketleri tenant müşteri carisinden ayrı izlenir.',
                'items' => [
                    ['label' => 'Cari Bakiye', 'value' => \App\Services\MoneyFormatter::format((float) ($billingSummary['balance'] ?? 0))],
                    ['label' => 'Toplam Borç', 'value' => \App\Services\MoneyFormatter::format((float) ($billingSummary['total_debit'] ?? 0))],
                    ['label' => 'Toplam Alacak', 'value' => \App\Services\MoneyFormatter::format((float) ($billingSummary['total_credit'] ?? 0))],
                    ['label' => 'Hareket Sayısı', 'value' => (string) ($billingSummary['entry_count'] ?? 0)],
                ],
                'actions' => [
                    ['label' => 'SaaS Cariyi Aç', 'url' => route('admin.super.tenants.billing.index', $tenant), 'new_tab' => false],
                ],
            ],
        ];
        $quickActions = collect([
            [
                'label' => 'Düzenle',
                'url' => route('admin.super.tenants.edit', $tenant),
                'style' => 'primary',
                'is_available' => true,
            ],
            [
                'label' => 'Paket Değiştir',
                'url' => route('admin.super.tenants.edit', $tenant) . '#paket-bolumu',
                'style' => 'light',
                'is_available' => true,
            ],
            [
                'label' => $subscription['status'] === 'suspended' ? 'Askıdan Çıkar / Aktifleştir' : 'Aboneliği Düzenle',
                'url' => route('admin.super.tenants.edit', $tenant) . '#abonelik-durumu',
                'style' => 'light',
                'is_available' => true,
            ],
            [
                'label' => 'Modül / Limit Yönet',
                'url' => route('admin.super.tenants.edit', $tenant) . '#modul-limit-yonetimi',
                'style' => 'light',
                'is_available' => true,
            ],
            [
                'label' => 'Paneli Aç',
                'url' => $loginPanelReady ? $tenantAdminPreviewUrl : null,
                'style' => 'light',
                'is_available' => $loginPanelReady,
                'opens_in_new_tab' => true,
            ],
            [
                'label' => 'Ayarları Gör',
                'url' => $loginPanelReady ? $tenantAdminPreviewUrl . '/settings' : null,
                'style' => 'light',
                'is_available' => $loginPanelReady,
                'opens_in_new_tab' => true,
            ],
            [
                'label' => 'Portali Aç',
                'url' => $portalEnabled && $tenantPortalPreviewUrl ? $tenantPortalPreviewUrl : null,
                'style' => 'light',
                'is_available' => $portalEnabled && filled($tenantPortalPreviewUrl),
                'opens_in_new_tab' => true,
            ],
            [
                'label' => 'Katalog Durumunu Gör',
                'url' => $loginPanelReady ? $tenantAdminPreviewUrl . '/catalog' : null,
                'style' => 'light',
                'is_available' => $loginPanelReady,
                'opens_in_new_tab' => true,
            ],
            [
                'label' => 'Kullanıcıları Gör',
                'url' => $loginPanelReady ? $tenantAdminPreviewUrl . '/users' : null,
                'style' => 'light',
                'is_available' => $loginPanelReady,
                'opens_in_new_tab' => true,
            ],
            [
                'label' => 'SaaS Cari',
                'url' => route('admin.super.tenants.billing.index', $tenant),
                'style' => 'light',
                'is_available' => true,
            ],
            [
                'label' => 'Bildirimleri Gör',
                'url' => $loginPanelReady && $notificationCenterEnabled ? $tenantAdminPreviewUrl . '/notifications' : null,
                'style' => 'light',
                'is_available' => $loginPanelReady && $notificationCenterEnabled,
                'opens_in_new_tab' => true,
            ],
        ])->filter(fn (array $action) => $action['is_available'])->values()->all();

        $tenantOperationalChecklist = $this->buildTenantLiveReadinessChecklist(
            tenant: $tenant,
            subscription: $subscription,
            onboardingStatus: $onboardingStatus,
            ownerAssignment: $ownerAssignment,
            package: $package,
            activeModuleCount: $activeModuleCount,
            hasSupplierAccess: $hasSupplierAccess,
            hasCatalogProjection: $hasCatalogProjection,
            hasFirstCatalogProduct: $hasFirstCatalogProduct,
            notificationSummary: $notificationSummary,
            loginPanelReady: $loginPanelReady,
            hasFinanceAccess: $hasFinanceAccess,
            teamSummary: $teamSummary,
            usageWarnings: $usageWarnings,
            storageReadiness: $storageReadiness,
            isDemoTenant: $isDemoTenant,
            tenantAdminPreviewUrl: $tenantAdminPreviewUrl
        );

        return [
            'tenant' => $tenant,
            'isDemoTenant' => $isDemoTenant,
            'packages' => $packages,
            'subscription' => $subscription,
            'packageRecord' => $package,
            'unknownPackageKey' => filled($tenant->package_key) && !$package,
            'tenantHasUsers' => UserRole::query()->where('tenant_account_id', $tenant->id)->exists(),
            'ownerAssignment' => $ownerAssignment,
            'ownerUser' => $ownerUser,
            'ownerRole' => $ownerRole,
            'ownerExists' => $ownerAssignment !== null,
            'onboardingStatus' => $onboardingStatus,
            'usageSnapshot' => $usageSnapshot,
            'usageWarnings' => $usageWarnings,
            'moduleSummary' => [
                'core' => collect($moduleRows)->filter(fn (array $row) => (bool) ($row['is_core'] ?? false))->values()->all(),
                'enabled_optional' => $enabledModules->filter(fn (array $row) => !($row['is_core'] ?? false))->values()->all(),
                'disabled_optional' => $disabledModules->values()->all(),
                'overridden_modules_count' => $overriddenModules->count(),
                'overridden_features_count' => $overriddenFeatures->count(),
            ],
            'createdAtLabel' => $createdAt ?: '-',
            'trialEndsAtLabel' => $lifecycleSettings['trial_ends_at_label'] ?: (filled($trialEndsAt) ? (string) $trialEndsAt : null),
            'packageEndDateLabel' => $lifecycleSettings['package_ends_at_label'] ?: (filled($endDate) ? (string) $endDate : null),
            'lifecycleSettings' => $lifecycleSettings,
            'domainLifecycleSettings' => $domainLifecycleSettings,
            'domainStatusOptions' => $this->domainStatusOptions(),
            'sslStatusOptions' => $this->sslStatusOptions(),
            'paymentArchitectureNote' => 'Online ödeme Super Admin tarafında ortak provider omurgası olarak kurulacak; tenant tarafında ise modül olarak açılacaktır.',
            'hasFirstCatalogProduct' => $hasFirstCatalogProduct,
            'quickActions' => $quickActions,
            'companyProfile' => $companyProfile,
            'tenantSettingsSummary' => [
                'settings_count' => $settingsCount,
                'settings_updated_at' => $settingsUpdatedAt ? \Illuminate\Support\Carbon::parse($settingsUpdatedAt)->format('d.m.Y H:i') : 'Takip edilmiyor',
                'smtp_ready' => (bool) ($onboardingStatus['has_smtp_config'] ?? false),
                'whatsapp_ready' => (bool) ($onboardingStatus['has_whatsapp_config'] ?? false),
                'country_code' => TenantSetting::getValue($tenant->id, 'whatsapp_default_country_code', '+90'),
                'settings_ready' => (bool) ($onboardingStatus['has_tenant_settings_defaults'] ?? false),
            ],
            'notificationSummary' => $notificationSummary,
            'billingSummary' => $billingSummary,
            'opsCenterSections' => $opsCenterSections,
            'printSettingsCount' => $printSettingsCount,
            'storageReadiness' => $storageReadiness,
            'centralPreviewHost' => $this->centralPreviewHost(),
            'tenantAdminPreviewUrl' => $tenantAdminPreviewUrl,
            'tenantPortalPreviewUrl' => $tenantPortalPreviewUrl,
            'portalEnabled' => $portalEnabled,
            'tenantPanelPreviewHost' => $tenant->panel_subdomain !== '' ? $tenant->panel_subdomain . '.' . $this->centralPreviewHost() : null,
            'tenantCustomDomainPreview' => filled($tenant->custom_domain) ? 'http://' . $tenant->custom_domain . '/admin' : null,
            'tenantPortalDomainPreview' => filled($tenant->portal_domain) ? 'http://' . $tenant->portal_domain . '/musteri-giris' : null,
            'localHostPreviewNote' => $this->localHostPreviewNote(),
            'moduleRows' => $moduleRows,
            'featureRows' => $featureRows,
            'limitRows' => $limitRows,
            'effectiveAccessSummary' => $summary,
            'tenantOperationalChecklist' => $tenantOperationalChecklist,
            'tenantAuditTimeline' => $this->operationAuditService->tenantTimeline($tenant),
            'tenantMenuLabels' => collect($tenantMenuItems)
                ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
                ->filter()
                ->values()
                ->all(),
            'teamSummary' => $teamSummary,
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $onboardingStatus
     * @param array<string, mixed> $notificationSummary
     * @param array<string, mixed> $teamSummary
     * @param array<int, array<string, mixed>> $usageWarnings
     * @param array<string, mixed> $storageReadiness
     * @return array<int, array<string, mixed>>
     */
    private function buildTenantLiveReadinessChecklist(
        TenantAccount $tenant,
        array $subscription,
        array $onboardingStatus,
        ?UserRole $ownerAssignment,
        ?Package $package,
        int $activeModuleCount,
        bool $hasSupplierAccess,
        bool $hasCatalogProjection,
        bool $hasFirstCatalogProduct,
        array $notificationSummary,
        bool $loginPanelReady,
        bool $hasFinanceAccess,
        array $teamSummary,
        array $usageWarnings,
        array $storageReadiness,
        bool $isDemoTenant,
        string $tenantAdminPreviewUrl
    ): array {
        return [
            $this->readinessItem('Abone Firma kaydı', 'Hazır', 'Temel Abone Firma kaydı ve kimliği oluşturulmuş.'),
            $this->readinessItem(
                'Owner / Yönetici kullanıcı',
                ($onboardingStatus['has_active_owner'] ?? false) ? 'Hazır' : 'Eksik',
                ($onboardingStatus['has_active_owner'] ?? false) ? 'Aktif owner kullanıcı hazır.' : 'Owner kullanıcı eksik veya pasif.',
                $ownerAssignment === null ? [
                    'action_url' => route('admin.super.tenants.owner.create', $tenant),
                    'action_label' => 'Owner Oluştur',
                ] : []
            ),
            $this->readinessItem(
                'Aktif kullanıcı',
                ($onboardingStatus['has_active_user'] ?? false) ? 'Hazır' : 'Eksik',
                ($onboardingStatus['has_active_user'] ?? false)
                    ? $teamSummary['active_users'] . ' aktif ekip kullanıcısı hazır.'
                    : 'Henüz aktif ekip kullanıcısı görünmüyor.'
            ),
            $this->readinessItem(
                'Paket seçimi',
                filled($tenant->package_key) ? 'Hazır' : 'Eksik',
                filled($tenant->package_key) ? ($package?->name ?? $tenant->package_key) . ' paketi atanmış.' : 'Paket ataması eksik.'
            ),
            $this->readinessItem(
                'Tenant ayarları varsayılanları',
                ($onboardingStatus['has_tenant_settings_defaults'] ?? false) ? 'Hazır' : 'Eksik',
                ($onboardingStatus['has_tenant_settings_defaults'] ?? false)
                    ? 'Varsayılan tenant ayarları hazır.'
                    : 'Tenant varsayılan ayarları eksik.'
            ),
            $this->readinessItem(
                'Modül erişimi',
                $activeModuleCount > 0 ? 'Hazır' : 'Eksik',
                $activeModuleCount > 0 ? $activeModuleCount . ' aktif modül/özellik erişimi hesaplandı.' : 'Aktif modül erişimi görünmüyor.'
            ),
            $this->readinessItem(
                'Limit durumu',
                empty($usageWarnings) ? 'Hazır' : 'Kontrol Edilmeli',
                empty($usageWarnings)
                    ? 'Kritik limit uyarısı görünmüyor.'
                    : 'Uyarı veren limitler: ' . collect($usageWarnings)->pluck('label')->implode(', ')
            ),
            $this->readinessItem(
                'Panel adresi',
                filled($tenant->panel_subdomain) ? 'Hazır' : 'Eksik',
                filled($tenant->panel_subdomain)
                    ? $tenant->panel_subdomain . '.' . $this->centralPreviewHost()
                    : 'Panel kısa adresi eksik.'
            ),
            $this->readinessItem(
                'Firma bilgileri',
                ($onboardingStatus['has_company_profile'] ?? false) ? 'Hazır' : 'Eksik',
                ($onboardingStatus['has_company_profile'] ?? false)
                    ? 'Firma iletişim ve profil alanları görünüyor.'
                    : 'Firma profili veya iletişim alanları eksik.'
            ),
            $this->readinessItem(
                'SMTP durumu',
                ($onboardingStatus['has_smtp_config'] ?? false) ? 'Hazır' : 'Kontrol Edilmeli',
                'SMTP: ' . ($notificationSummary['smtp']['status_label'] ?? 'Kontrol Edilmeli') . ' / Son test: ' . ($notificationSummary['smtp']['last_test_status'] ?? 'Henüz test yok')
            ),
            $this->readinessItem(
                'WhatsApp durumu',
                ($onboardingStatus['has_whatsapp_config'] ?? false) ? 'Hazır' : 'Opsiyonel',
                'WhatsApp: ' . ($notificationSummary['whatsapp']['status_label'] ?? 'Kontrol Edilmeli') . ' / Test telefon: ' . ($notificationSummary['whatsapp']['test_phone_masked'] ?? 'Tanımlı değil')
            ),
            $this->readinessItem(
                'Hazır tedarikçi erişimi',
                $hasSupplierAccess ? 'Hazır' : 'Eksik',
                $hasSupplierAccess ? 'Hazır tedarikçi erişimi var' : 'Hazır tedarikçi erişimi yok'
            ),
            $this->readinessItem(
                'Katalog projection',
                $hasCatalogProjection ? 'Hazır' : 'Eksik',
                $hasCatalogProjection ? 'Katalog projection hazır' : 'Katalog projection yapılmamış'
            ),
            $this->readinessItem(
                'Katalog / Product Hub erişimi',
                $hasSupplierAccess && ($hasCatalogProjection || $hasFirstCatalogProduct) ? 'Hazır' : 'Kontrol Edilmeli',
                $hasSupplierAccess && ($hasCatalogProjection || $hasFirstCatalogProduct)
                    ? 'Tedarikçi erişimi ve temel katalog görünürlüğü hazır.'
                    : 'Tedarikçi erişimi veya katalog görünürlüğü kontrol edilmelidir.'
            ),
            $this->readinessItem(
                'İlk firma / cari',
                ($onboardingStatus['has_first_company_current_account'] ?? false) ? 'Hazır' : 'Kontrol Edilmeli',
                ($onboardingStatus['has_first_company_current_account'] ?? false)
                    ? 'İlk firma ve cari hazır.'
                    : 'İlk firma / cari açılışı operasyon öncesi kontrol edilmelidir.'
            ),
            $this->readinessItem(
                'İlk teklif / sipariş',
                'Opsiyonel',
                'İlk teklif ve sipariş akışı canlı açılış sonrası operasyonel smoke ile doğrulanır.'
            ),
            $this->readinessItem(
                'Public tracking güvenliği',
                'Kontrol Edilmeli',
                'Public tracking ve iş formu güvenliği son full smoke / security testleri ile doğrulanır.'
            ),
            $this->readinessItem(
                'Finans yetki güvenliği',
                $hasFinanceAccess ? 'Hazır' : 'Kontrol Edilmeli',
                $hasFinanceAccess
                    ? 'Finans modülü ve owner erişimi görünür; yetki sınırları testlerle korunur.'
                    : 'Finans erişimi sınırlı veya opsiyonel. Yetki sınırları kontrol edilmelidir.'
            ),
            $this->readinessItem(
                'Storage / upload durumu',
                $storageReadiness['status'],
                $storageReadiness['message']
            ),
            $this->readinessItem(
                'Lifecycle durumu',
                in_array($subscription['status'], ['active', 'trial'], true) ? 'Hazır' : 'Eksik',
                'Durum: ' . $subscription['label'] . ' / ' . ($subscription['message'] ?? 'Lifecycle kontrolü gerekli.')
            ),
            $this->readinessItem(
                'Demo / gerçek ayrımı',
                $isDemoTenant ? 'Demo/Test' : 'Canlı Adayı',
                $isDemoTenant
                    ? 'Bu kayıt demo/test tenant olarak izleniyor; canlı tenantlarla karıştırılmamalı.'
                    : 'Bu kayıt demo değil; canlı aday değerlendirmesinde tutulabilir.'
            ),
            $this->readinessItem(
                'Panel girişi',
                $loginPanelReady ? 'Hazır' : 'Kontrol Edilmeli',
                $loginPanelReady
                    ? 'Tenant paneli açılabiliyor.'
                    : 'Panel girişi için aktif tenant, panel adresi ve owner gerekir',
                $loginPanelReady ? [
                    'action_url' => $tenantAdminPreviewUrl,
                    'action_label' => 'Abone Firma Paneline Gir',
                ] : []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildStorageReadinessSummary(TenantAccount $tenant, array $settings): array
    {
        $publicDiskConfigured = filled(config('filesystems.disks.public.root'));
        $publicLinkConfigured = array_key_exists(public_path('storage'), (array) config('filesystems.links', []));
        $workFolderReady = (bool) ($this->tenantOnboardingStatusService->forTenant($tenant)['has_work_folder_root'] ?? false);
        $storageDisk = (string) ($settings['storage_disk'] ?? config('filesystems.default', 'local'));

        if (!$publicDiskConfigured || !$publicLinkConfigured) {
            return [
                'status' => 'Eksik',
                'message' => 'Public disk veya storage link tanımı eksik görünüyor.',
                'disk' => $storageDisk,
            ];
        }

        if (!$workFolderReady) {
            return [
                'status' => 'Kontrol Edilmeli',
                'message' => 'Storage tanımı var; çalışma klasörü kökü ve upload akışı deploy öncesi ayrıca doğrulanmalı.',
                'disk' => $storageDisk,
            ];
        }

        return [
            'status' => 'Kontrol Edilmeli',
            'message' => 'Public disk tanımlı ve çalışma klasörü kökü hazır; yazılabilirlik ve public link davranışı canlı öncesi smoke ile doğrulanmalı.',
            'disk' => $storageDisk,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function readinessItem(string $label, string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'label' => $label,
            'status' => $status,
            'is_ready' => $status === 'Hazır',
            'message' => $message,
        ], $extra);
    }

    private function decorateTenantListRow(TenantAccount $tenant): TenantAccount
    {
        $tenant->setAttribute('is_demo_tenant', $this->isDemoTenant($tenant));
        $tenant->setAttribute(
            'admin_preview_url',
            filled($tenant->panel_subdomain)
                ? 'http://' . $tenant->panel_subdomain . '.' . $this->centralPreviewHost() . '/admin'
                : null
        );

        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $onboardingStatus = $this->tenantOnboardingStatusService->forTenant($tenant);
        $usageWarnings = $this->tenantUsageService->warningItems($tenant);
        $ownerAssignment = $this->ownerAssignmentQuery($tenant)->first();
        $activeModuleCount = count(array_filter(
            $this->tenantAccessService->effectiveAccessSummary($tenant)['modules'] ?? [],
            fn (array $row) => (bool) ($row['enabled'] ?? false)
        ));
        $onboardingReady = collect($onboardingStatus)
            ->filter(fn ($value, string $key) => str_starts_with($key, 'has_'))
            ->every(fn ($value) => $value === true);
        $missingOnboardingCount = collect($onboardingStatus)
            ->filter(fn ($value, string $key) => str_starts_with($key, 'has_') && $value === false)
            ->count();

        $tenant->setAttribute('subscription_status', $subscription['status']);
        $tenant->setAttribute('subscription_label', $subscription['label']);
        $tenant->setAttribute('subscription_message', $subscription['message']);
        $tenant->setAttribute('subscription_severity', $subscription['severity']);
        $tenant->setAttribute('days_remaining', $subscription['days_remaining']);
        $tenant->setAttribute('trial_ends_at_label', $subscription['trial_ends_at']?->format('d.m.Y'));
        $tenant->setAttribute('package_ends_at_label', $subscription['package_ends_at']?->format('d.m.Y'));
        $tenant->setAttribute('warning_label', $subscription['warning_label']);
        $tenant->setAttribute('is_ending_soon', in_array($subscription['warning_label'], ['Bugün bitiyor', '7 gün içinde bitecek'], true));
        $tenant->setAttribute('package_label', $tenant->package?->name ?? ($tenant->package_key ?: 'Core'));
        $tenant->setAttribute('owner_name', $ownerAssignment?->user?->name ?: 'Owner eksik');
        $tenant->setAttribute('owner_email', $ownerAssignment?->user?->email);
        $tenant->setAttribute('owner_last_login_at', $ownerAssignment?->user?->last_login_at);
        $tenant->setAttribute('has_usage_warning', !empty($usageWarnings));
        $tenant->setAttribute('usage_warning_count', count($usageWarnings));
        $tenant->setAttribute('active_module_count', $activeModuleCount);
        $tenant->setAttribute('onboarding_ready', $onboardingReady);
        $tenant->setAttribute('missing_onboarding_count', $missingOnboardingCount);
        $tenant->setAttribute(
            'onboarding_label',
            $onboardingReady ? 'Hazır' : 'Eksik: ' . $missingOnboardingCount
        );
        $tenant->setAttribute(
            'has_domain_gap',
            blank($tenant->panel_subdomain) || (blank($tenant->custom_domain) && blank($tenant->portal_domain))
        );
        $tenant->setAttribute(
            'usage_summary',
            empty($usageWarnings)
                ? 'Normal'
                : collect($usageWarnings)->pluck('label')->take(2)->implode(', ')
        );
        $tenant->setAttribute(
            'last_activity_label',
            $ownerAssignment?->user?->last_login_at?->format('d.m.Y H:i')
                ?: $tenant->created_at?->format('d.m.Y H:i')
                ?: '-'
        );

        return $tenant;
    }

    private function isDemoTenant(TenantAccount $tenant): bool
    {
        $panelSubdomain = strtolower(trim((string) $tenant->panel_subdomain));
        $slug = strtolower(trim((string) $tenant->slug));
        $packageKey = strtolower(trim((string) ($tenant->package_key ?? '')));

        return $panelSubdomain === 'demo'
            || $slug === 'demo'
            || $slug === 'demo-sirketi'
            || $packageKey === 'demo';
    }

    private function moduleRows(TenantAccount $tenant, ?Package $package): array
    {
        $tenant->loadMissing('modules');

        $rows = [];

        foreach ($this->catalogService->moduleOptionsForAdmin() as $module) {
            $moduleKey = $module['key'];
            $status = $module['status'] ?? 'passive';
            $overrideRecord = $tenant->modules->first(fn (TenantModule $record) => $record->module_key === $moduleKey && blank($record->feature_key));
            $packageEnabled = $package ? $this->packageCatalogService->hasModule($package, $moduleKey) : false;
            $effective = $this->tenantAccessService->moduleStatus($tenant, $moduleKey);

            $rows[] = [
                'key' => $moduleKey,
                'label' => $module['label'],
                'category' => $module['category'] ?? '-',
                'status' => $status,
                'is_core' => (bool) ($module['is_core'] ?? false),
                'package_enabled' => $packageEnabled,
                'override_state' => $overrideRecord ? ($overrideRecord->is_enabled ? 'enabled' : 'disabled') : 'default',
                'effective_enabled' => (bool) ($effective['enabled'] ?? false),
                'effective_reason' => $effective['reason'] ?? '-',
                'is_locked' => (bool) ($module['is_core'] ?? false) || in_array($status, ['planned', 'passive', 'deprecated'], true),
            ];
        }

        return $rows;
    }

    private function featureRows(TenantAccount $tenant, ?Package $package): array
    {
        $tenant->loadMissing('modules');

        $rows = [];

        foreach ($this->catalogService->features() as $moduleKey => $features) {
            $moduleMeta = $this->catalogService->getModule($moduleKey);

            foreach ($features as $featureKey => $feature) {
                $status = $feature['status'] ?? 'passive';
                if ($status === 'deprecated') {
                    continue;
                }

                $overrideRecord = $tenant->modules->first(function (TenantModule $record) use ($moduleKey, $featureKey) {
                    return $record->module_key === $moduleKey && $record->feature_key === $featureKey;
                });

                $packageEnabled = $package ? $this->packageCatalogService->hasFeature($package, $featureKey, $moduleKey) : false;
                $effective = $this->tenantAccessService->featureStatus($tenant, $featureKey, $moduleKey);

                $rows[] = [
                    'module_key' => $moduleKey,
                    'module_label' => $moduleMeta['label'] ?? $moduleKey,
                    'feature_key' => $featureKey,
                    'feature_label' => $feature['label'] ?? $featureKey,
                    'status' => $status,
                    'package_enabled' => $packageEnabled,
                    'override_state' => $overrideRecord ? ($overrideRecord->is_enabled ? 'enabled' : 'disabled') : 'default',
                    'effective_enabled' => (bool) ($effective['enabled'] ?? false),
                    'effective_reason' => $effective['reason'] ?? '-',
                    'is_locked' => in_array($status, ['planned', 'passive', 'deprecated'], true),
                ];
            }
        }

        return $rows;
    }

    private function limitRows(TenantAccount $tenant, ?Package $package): array
    {
        $rows = [];

        foreach (self::LIMIT_KEYS as $limitKey => $label) {
            $packageLimit = $package ? $this->packageCatalogService->getLimit($package, $limitKey) : null;
            $overrideSetting = TenantSetting::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('key', 'limit_' . $limitKey)
                ->first();
            $usage = $this->tenantUsageService->getUsageForKey($tenant, $limitKey);

            $mode = 'default';
            $overrideValue = null;

            if ($overrideSetting) {
                if ($overrideSetting->value === 'unlimited') {
                    $mode = 'unlimited';
                } else {
                    $mode = 'value';
                    $overrideValue = (int) $overrideSetting->casted_value;
                }
            }

            $rows[] = [
                'key' => $limitKey,
                'label' => $label,
                'package_limit' => $packageLimit,
                'override_mode' => $mode,
                'override_value' => $overrideValue,
                'effective_limit' => $usage['limit'],
                'effective_status' => $usage['status'],
                'current_usage' => $usage['current'],
                'percentage' => $usage['percentage'],
            ];
        }

        return $rows;
    }

    private function resolveModuleKeyForFeature(string $featureKey): ?string
    {
        foreach ($this->catalogService->features() as $moduleKey => $moduleFeatures) {
            if (array_key_exists($featureKey, $moduleFeatures)) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function normalizeTenantCreateInput(Request $request): array
    {
        $ownerEmail = strtolower(trim((string) $request->input('owner_email')));

        return [
            'signup_request_id' => $request->filled('signup_request_id') ? (int) $request->input('signup_request_id') : null,
            'name' => trim((string) $request->input('name')),
            'legal_name' => trim((string) $request->input('legal_name')),
            'slug' => Str::slug((string) $request->input('slug')),
            'panel_subdomain' => Str::slug((string) ($request->input('panel_subdomain') ?: $request->input('slug'))),
            'custom_domain' => $this->normalizeDomain((string) $request->input('custom_domain')),
            'portal_domain' => $this->normalizeDomain((string) $request->input('portal_domain')),
            'status' => strtolower(trim((string) $request->input('status', 'active'))),
            'package_key' => trim((string) $request->input('package_key')),
            'default_locale' => strtolower(trim((string) $request->input('default_locale', 'tr'))),
            'default_currency' => strtoupper(trim((string) $request->input('default_currency', 'TL'))),
            'timezone' => trim((string) $request->input('timezone', 'Europe/Istanbul')),
            'owner_name' => trim((string) $request->input('owner_name')),
            'owner_email' => $ownerEmail,
            'owner_phone' => trim((string) $request->input('owner_phone')),
            'owner_password' => (string) $request->input('owner_password'),
        ];
    }

    private function normalizeOwnerInput(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name')),
            'email' => strtolower(trim((string) $request->input('email'))),
            'phone' => trim((string) $request->input('phone')),
            'password' => (string) $request->input('password'),
            'send_invite' => $request->boolean('send_invite'),
            'role' => trim((string) $request->input('role', 'tenant_owner')),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function normalizeTenantUpdateInput(Request $request, TenantAccount $tenant): array
    {
        $profile = $this->tenantCompanyProfileService->getProfile($tenant);

        return [
            'name' => trim((string) $request->input('name', $tenant->name)),
            'legal_name' => trim((string) $request->input('legal_name', $tenant->legal_name)),
            'panel_subdomain' => Str::slug((string) $request->input('panel_subdomain', $tenant->panel_subdomain)),
            'custom_domain' => $this->normalizeDomain((string) $request->input('custom_domain', $tenant->custom_domain)),
            'portal_domain' => $this->normalizeDomain((string) $request->input('portal_domain', $tenant->portal_domain)),
            'package_key' => trim((string) $request->input('package_key', $tenant->package_key)),
            'status' => strtolower(trim((string) $request->input('status', $tenant->status))),
            'subscription_trial_starts_at' => trim((string) $request->input('subscription_trial_starts_at', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_START_SETTING, ''))),
            'subscription_trial_ends_at' => trim((string) $request->input('subscription_trial_ends_at', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_END_SETTING, ''))),
            'subscription_package_starts_at' => trim((string) $request->input('subscription_package_starts_at', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_START_SETTING, ''))),
            'subscription_package_ends_at' => trim((string) $request->input('subscription_package_ends_at', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_END_SETTING, ''))),
            'subscription_status_note' => trim((string) $request->input('subscription_status_note', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_STATUS_NOTE_SETTING, ''))),
            'subscription_suspended_reason' => trim((string) $request->input('subscription_suspended_reason', TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_SUSPENDED_REASON_SETTING, ''))),
            'domain_panel_status' => trim((string) $request->input('domain_panel_status', TenantSetting::getValue($tenant->id, self::DOMAIN_PANEL_STATUS_SETTING, 'draft'))),
            'domain_custom_status' => trim((string) $request->input('domain_custom_status', TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_STATUS_SETTING, 'draft'))),
            'domain_custom_ssl_status' => trim((string) $request->input('domain_custom_ssl_status', TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_SSL_STATUS_SETTING, 'not_started'))),
            'domain_portal_status' => trim((string) $request->input('domain_portal_status', TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_STATUS_SETTING, 'draft'))),
            'domain_portal_ssl_status' => trim((string) $request->input('domain_portal_ssl_status', TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_SSL_STATUS_SETTING, 'not_started'))),
            'domain_operations_note' => trim((string) $request->input('domain_operations_note', TenantSetting::getValue($tenant->id, self::DOMAIN_OPERATIONS_NOTE_SETTING, ''))),
            'default_locale' => strtolower(trim((string) $request->input('default_locale', $tenant->default_locale ?: 'tr'))),
            'default_currency' => strtoupper(trim((string) $request->input('default_currency', $tenant->default_currency ?: 'TL'))),
            'timezone' => trim((string) $request->input('timezone', $tenant->timezone ?: 'Europe/Istanbul')),
            'company_display_name' => trim((string) $request->input('company_display_name', $profile['display_name'] ?? $tenant->name)),
            'company_legal_name' => trim((string) $request->input('company_legal_name', $profile['legal_name'] ?? $tenant->legal_name)),
            'company_tax_office' => trim((string) $request->input('company_tax_office', $profile['tax_office'] ?? '')),
            'company_tax_number' => trim((string) $request->input('company_tax_number', $profile['tax_number'] ?? '')),
            'company_phone' => trim((string) $request->input('company_phone', $profile['phone'] ?? '')),
            'company_email' => strtolower(trim((string) $request->input('company_email', $profile['email'] ?? ''))),
            'company_address' => trim((string) $request->input('company_address', $profile['address'] ?? '')),
            'company_city' => trim((string) $request->input('company_city', $profile['city'] ?? '')),
            'company_district' => trim((string) $request->input('company_district', $profile['district'] ?? '')),
            'company_country' => trim((string) $request->input('company_country', $profile['country'] ?? 'Türkiye')),
            'company_postal_code' => trim((string) $request->input('company_postal_code', $profile['postal_code'] ?? '')),
        ];
    }

    private function validateTenantUpdateInput(array $input, TenantAccount $tenant): array
    {
        $validator = validator($input, [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'panel_subdomain' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenant_accounts', 'panel_subdomain')->ignore($tenant->id)],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'portal_domain' => ['nullable', 'string', 'max:255'],
            'package_key' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'trial', 'inactive', 'suspended', 'passive'])],
            'subscription_trial_starts_at' => ['nullable', 'date_format:Y-m-d'],
            'subscription_trial_ends_at' => ['nullable', 'date_format:Y-m-d'],
            'subscription_package_starts_at' => ['nullable', 'date_format:Y-m-d'],
            'subscription_package_ends_at' => ['nullable', 'date_format:Y-m-d'],
            'subscription_status_note' => ['nullable', 'string', 'max:1000'],
            'subscription_suspended_reason' => ['nullable', 'string', 'max:500'],
            'domain_panel_status' => ['required', Rule::in(array_keys($this->domainStatusOptions()))],
            'domain_custom_status' => ['required', Rule::in(array_keys($this->domainStatusOptions()))],
            'domain_custom_ssl_status' => ['required', Rule::in(array_keys($this->sslStatusOptions()))],
            'domain_portal_status' => ['required', Rule::in(array_keys($this->domainStatusOptions()))],
            'domain_portal_ssl_status' => ['required', Rule::in(array_keys($this->sslStatusOptions()))],
            'domain_operations_note' => ['nullable', 'string', 'max:1000'],
            'default_locale' => ['required', Rule::in(array_keys($this->localeOptions()))],
            'default_currency' => ['required', 'string', 'max:3'],
            'timezone' => ['required', 'timezone'],
            'company_display_name' => ['required', 'string', 'max:255'],
            'company_legal_name' => ['nullable', 'string', 'max:255'],
            'company_tax_office' => ['nullable', 'string', 'max:100'],
            'company_tax_number' => ['nullable', 'string', 'max:50'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email:rfc', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'company_city' => ['nullable', 'string', 'max:100'],
            'company_district' => ['nullable', 'string', 'max:100'],
            'company_country' => ['nullable', 'string', 'max:100'],
            'company_postal_code' => ['nullable', 'string', 'max:50'],
        ]);

        $validator->after(function ($validator) use ($input, $tenant): void {
            $panelSubdomain = trim((string) ($input['panel_subdomain'] ?? ''));
            if ($panelSubdomain !== '' && in_array($panelSubdomain, self::RESERVED_TENANT_IDENTIFIERS, true)) {
                $validator->errors()->add('panel_subdomain', 'Bu alan için ayrılmış bir değer kullanılamaz.');
            }

            $centralHost = $this->centralPreviewHost();
            foreach (['custom_domain', 'portal_domain'] as $field) {
                $value = trim((string) ($input[$field] ?? ''));

                if ($value === '') {
                    continue;
                }

                if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($value, '.')) {
                    $validator->errors()->add($field, 'Geçerli bir domain girin.');
                    continue;
                }

                if ($value === $centralHost) {
                    $validator->errors()->add($field, 'Merkez panel host adı tenant domain alanında kullanılamaz.');
                }

                $exists = TenantAccount::query()
                    ->where('id', '!=', $tenant->id)
                    ->where(function ($query) use ($value) {
                        $query->where('custom_domain', $value)
                            ->orWhere('portal_domain', $value);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add($field, 'Bu domain başka bir Abone Firma tarafından kullanılıyor.');
                }
            }

            if (
                filled($input['custom_domain'] ?? null)
                && filled($input['portal_domain'] ?? null)
                && ($input['custom_domain'] === $input['portal_domain'])
            ) {
                $validator->errors()->add('portal_domain', 'Portal domaini, özel domain ile aynı olamaz.');
            }

            if (
                filled($input['subscription_trial_starts_at'] ?? null)
                && filled($input['subscription_trial_ends_at'] ?? null)
                && $input['subscription_trial_starts_at'] > $input['subscription_trial_ends_at']
            ) {
                $validator->errors()->add('subscription_trial_ends_at', 'Deneme bitiş tarihi, başlangıç tarihinden önce olamaz.');
            }

            if (
                filled($input['subscription_package_starts_at'] ?? null)
                && filled($input['subscription_package_ends_at'] ?? null)
                && $input['subscription_package_starts_at'] > $input['subscription_package_ends_at']
            ) {
                $validator->errors()->add('subscription_package_ends_at', 'Paket bitiş tarihi, başlangıç tarihinden önce olamaz.');
            }

            if (($input['status'] ?? '') === 'suspended' && !filled($input['subscription_suspended_reason'] ?? null)) {
                $validator->errors()->add('subscription_suspended_reason', 'Askıya alma nedeni girilmelidir.');
            }
        });

        return $validator->validate();
    }

    private function validateOwnerInput(array $input, TenantAccount $tenant): array
    {
        $validator = validator($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['tenant_owner'])],
            'send_invite' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($input, $tenant): void {
            if ($this->ownerAssignmentQuery($tenant)->exists()) {
                $validator->errors()->add('owner', 'Bu tenant için owner kullanıcı zaten oluşturulmuş.');
            }

            $platformAdminEmailExists = User::query()
                ->where('email', $input['email'] ?? '')
                ->where('is_platform_admin', true)
                ->exists();

            if ($platformAdminEmailExists) {
                $validator->errors()->add('email', 'Platform admin e-posta adresi tenant owner için kullanılamaz.');
            }
        });

        return $validator->validate();
    }

    private function validateTenantCreateInput(array $input): array
    {
        $validator = validator($input, [
            'signup_request_id' => ['nullable', 'integer', Rule::exists('tenant_signup_requests', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenant_accounts', 'slug')],
            'panel_subdomain' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenant_accounts', 'panel_subdomain')],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'portal_domain' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
            'package_key' => [
                'required',
                'string',
                Rule::exists('packages', 'key')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'default_locale' => ['required', Rule::in(array_keys($this->localeOptions()))],
            'default_currency' => ['required', 'string', 'max:3'],
            'timezone' => ['required', 'timezone'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email:rfc', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
            'owner_password' => ['nullable', 'string', 'min:8', 'max:255'],
        ], [
            'package_key.exists' => 'Yalnız aktif paketler seçilebilir.',
        ]);

        $validator->after(function ($validator) use ($input): void {
            foreach (['slug', 'panel_subdomain'] as $field) {
                $value = trim((string) ($input[$field] ?? ''));

                if ($value !== '' && in_array($value, self::RESERVED_TENANT_IDENTIFIERS, true)) {
                    $validator->errors()->add($field, 'Bu alan için ayrılmış bir değer kullanılamaz.');
                }
            }

            foreach (['custom_domain', 'portal_domain'] as $field) {
                $value = trim((string) ($input[$field] ?? ''));

                if ($value === '') {
                    continue;
                }

                if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($value, '.')) {
                    $validator->errors()->add($field, 'Geçerli bir domain girin.');
                    continue;
                }

                $exists = TenantAccount::query()
                    ->where(function ($query) use ($value) {
                        $query->where('custom_domain', $value)
                            ->orWhere('portal_domain', $value);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add($field, 'Bu domain başka bir tenant tarafından kullanılıyor.');
                }
            }

            if (
                filled($input['custom_domain'] ?? null)
                && filled($input['portal_domain'] ?? null)
                && ($input['custom_domain'] === $input['portal_domain'])
            ) {
                $validator->errors()->add('portal_domain', 'Portal domain, custom domain ile aynı olamaz.');
            }

            if (!$this->hasOwnerCreateIntent($input)) {
                return;
            }

            if (!filled($input['owner_name'] ?? null)) {
                $validator->errors()->add('owner_name', 'Owner adı zorunludur.');
            }

            if (!filled($input['owner_email'] ?? null)) {
                $validator->errors()->add('owner_email', 'Owner e-posta zorunludur.');
                return;
            }

            $existingUser = User::query()
                ->where('email', $input['owner_email'])
                ->first();

            if ($existingUser?->isPlatformAdmin()) {
                $validator->errors()->add('owner_email', 'Platform admin e-posta adresi owner için kullanılamaz.');
                return;
            }

            if ($existingUser && $existingUser->userRoles()->exists()) {
                $validator->errors()->add('owner_email', 'Bu e-posta başka bir tenant kullanıcısına bağlı. Bu fazda mevcut tenant kullanıcısı owner olarak bağlanamaz.');
            }
        });

        return $validator->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantAuditSnapshot(TenantAccount $tenant): array
    {
        $tenant->loadMissing('settings');

        return [
            'panel_subdomain' => $tenant->panel_subdomain,
            'custom_domain' => $tenant->custom_domain,
            'portal_domain' => $tenant->portal_domain,
            'status' => $tenant->status,
            'package_key' => $tenant->package_key,
            'subscription_trial_starts_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_START_SETTING),
            'subscription_trial_ends_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_END_SETTING),
            'subscription_package_starts_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_START_SETTING),
            'subscription_package_ends_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_END_SETTING),
            'subscription_status_note' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_STATUS_NOTE_SETTING),
            'subscription_suspended_reason' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_SUSPENDED_REASON_SETTING),
            'domain_panel_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PANEL_STATUS_SETTING),
            'domain_custom_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_STATUS_SETTING),
            'domain_custom_ssl_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_SSL_STATUS_SETTING),
            'domain_portal_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_STATUS_SETTING),
            'domain_portal_ssl_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_SSL_STATUS_SETTING),
            'domain_operations_note' => TenantSetting::getValue($tenant->id, self::DOMAIN_OPERATIONS_NOTE_SETTING),
        ];
    }

    private function hasOwnerCreateIntent(array $input): bool
    {
        return filled($input['owner_name'] ?? null)
            || filled($input['owner_email'] ?? null)
            || filled($input['owner_phone'] ?? null)
            || filled($input['owner_password'] ?? null);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{generated_password:?string,summary:string}
     */
    private function provisionOwnerForTenant(TenantAccount $tenant, array $validated): array
    {
        $role = $this->ensureDefaultRole('tenant_owner');
        $existingUser = User::query()
            ->where('email', $validated['owner_email'])
            ->first();

        $generatedPassword = null;

        if ($existingUser) {
            $existingUser->forceFill([
                'name' => $validated['owner_name'],
                'phone' => $validated['owner_phone'] ?: $existingUser->phone,
            ])->save();

            $user = $existingUser;
            $summary = 'Mevcut kullanıcı owner olarak bağlandı.';
        } else {
            $password = $validated['owner_password'] ?: Str::password(16);
            $generatedPassword = $validated['owner_password'] ? null : $password;

            $user = User::query()->create([
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'phone' => $validated['owner_phone'] ?: null,
                'password' => $password,
                'is_platform_admin' => false,
            ]);

            $summary = 'Yeni owner kullanıcı oluşturuldu.';
        }

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return [
            'generated_password' => $generatedPassword,
            'summary' => $summary,
        ];
    }

    private function normalizeDomain(string $value): string
    {
        $trimmed = strtolower(trim($value));

        if ($trimmed === '') {
            return '';
        }

        if (!str_contains($trimmed, '://')) {
            $trimmed = 'https://' . $trimmed;
        }

        $host = (string) parse_url($trimmed, PHP_URL_HOST);

        return strtolower(trim($host));
    }

    private function centralPreviewHost(): string
    {
        $host = (string) config('prodelya_domains.panel_domain');
        if (trim($host) === '') {
            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        }
        $host = strtolower(trim($host));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return 'prodelya_core.test';
        }

        return $host;
    }

    private function localeOptions(): array
    {
        return [
            'tr' => 'Türkçe',
            'en' => 'English',
        ];
    }

    private function timezoneOptions(): array
    {
        return [
            'Europe/Istanbul' => 'Europe/Istanbul',
            'UTC' => 'UTC',
        ];
    }

    private function statusOptions(): array
    {
        return [
            'active' => 'Aktif',
            'trial' => 'Deneme',
            'inactive' => 'Pasif',
            'suspended' => 'Askıya Alındı',
        ];
    }

    private function domainStatusOptions(): array
    {
        return [
            'draft' => 'Taslak',
            'pending_dns' => 'DNS Bekliyor',
            'configured' => 'Yapılandırıldı',
            'live' => 'Canlı',
            'blocked' => 'Sorunlu',
        ];
    }

    private function sslStatusOptions(): array
    {
        return [
            'not_started' => 'Başlamadı',
            'pending' => 'Bekliyor',
            'active' => 'Aktif',
            'error' => 'Hata',
        ];
    }

    private function subscriptionLifecycleSettings(TenantAccount $tenant, array $subscription): array
    {
        return [
            'trial_starts_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_START_SETTING),
            'trial_ends_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_TRIAL_END_SETTING),
            'package_starts_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_START_SETTING),
            'package_ends_at' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_PACKAGE_END_SETTING),
            'status_note' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_STATUS_NOTE_SETTING),
            'suspended_reason' => TenantSetting::getValue($tenant->id, self::SUBSCRIPTION_SUSPENDED_REASON_SETTING),
            'status_updated_at' => $subscription['status_updated_at']?->format('d.m.Y H:i') ?: 'Takip edilmiyor',
            'warning_label' => $subscription['warning_label'] ?: 'Uyarı yok',
            'effective_state' => $subscription['status'],
            'trial_starts_at_label' => $subscription['trial_starts_at']?->format('d.m.Y') ?: null,
            'trial_ends_at_label' => $subscription['trial_ends_at']?->format('d.m.Y') ?: null,
            'package_starts_at_label' => $subscription['package_starts_at']?->format('d.m.Y') ?: null,
            'package_ends_at_label' => $subscription['package_ends_at']?->format('d.m.Y') ?: null,
            'effective_state_label' => match ($subscription['status']) {
                'trial' => 'Deneme Sürecinde',
                'expired' => 'Süresi Dolmuş',
                'suspended' => 'Askıda',
                'passive' => 'Pasif',
                default => 'Aktif',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domainLifecycleSettings(TenantAccount $tenant): array
    {
        return [
            'panel_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PANEL_STATUS_SETTING, 'draft'),
            'custom_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_STATUS_SETTING, 'draft'),
            'custom_ssl_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_CUSTOM_SSL_STATUS_SETTING, 'not_started'),
            'portal_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_STATUS_SETTING, 'draft'),
            'portal_ssl_status' => TenantSetting::getValue($tenant->id, self::DOMAIN_PORTAL_SSL_STATUS_SETTING, 'not_started'),
            'operations_note' => TenantSetting::getValue($tenant->id, self::DOMAIN_OPERATIONS_NOTE_SETTING, ''),
        ];
    }

    private function persistSubscriptionLifecycleSettings(TenantAccount $tenant, array $validated): void
    {
        $settings = [
            self::SUBSCRIPTION_TRIAL_START_SETTING => $validated['subscription_trial_starts_at'] ?: null,
            self::SUBSCRIPTION_TRIAL_END_SETTING => $validated['subscription_trial_ends_at'] ?: null,
            self::SUBSCRIPTION_PACKAGE_START_SETTING => $validated['subscription_package_starts_at'] ?: null,
            self::SUBSCRIPTION_PACKAGE_END_SETTING => $validated['subscription_package_ends_at'] ?: null,
            self::SUBSCRIPTION_STATUS_NOTE_SETTING => $validated['subscription_status_note'] ?: null,
            self::SUBSCRIPTION_SUSPENDED_REASON_SETTING => $validated['subscription_suspended_reason'] ?: null,
            self::SUBSCRIPTION_STATUS_UPDATED_AT_SETTING => now()->toDateTimeString(),
            self::SUBSCRIPTION_LIFECYCLE_STATE_SETTING => $this->normalizeLifecycleStateSetting((string) $validated['status']),
            self::DOMAIN_PANEL_STATUS_SETTING => $validated['domain_panel_status'] ?: 'draft',
            self::DOMAIN_CUSTOM_STATUS_SETTING => $validated['domain_custom_status'] ?: 'draft',
            self::DOMAIN_CUSTOM_SSL_STATUS_SETTING => $validated['domain_custom_ssl_status'] ?: 'not_started',
            self::DOMAIN_PORTAL_STATUS_SETTING => $validated['domain_portal_status'] ?: 'draft',
            self::DOMAIN_PORTAL_SSL_STATUS_SETTING => $validated['domain_portal_ssl_status'] ?: 'not_started',
            self::DOMAIN_OPERATIONS_NOTE_SETTING => $validated['domain_operations_note'] ?: null,
        ];

        foreach ($settings as $key => $value) {
            if (blank($value)) {
                TenantSetting::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('key', $key)
                    ->delete();
                continue;
            }

            TenantSetting::setValue($tenant->id, $key, $value, 'string');
        }
    }

    private function persistInitialSubscriptionLifecycleSettings(TenantAccount $tenant, array $validated): void
    {
        $status = (string) ($validated['status'] ?? 'active');
        $lifecycleState = $this->normalizeLifecycleStateSetting($status);

        if ($lifecycleState === 'active') {
            TenantSetting::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('key', self::SUBSCRIPTION_LIFECYCLE_STATE_SETTING)
                ->delete();

            return;
        }

        TenantSetting::setValue($tenant->id, self::SUBSCRIPTION_LIFECYCLE_STATE_SETTING, $lifecycleState, 'string');
        TenantSetting::setValue($tenant->id, self::SUBSCRIPTION_STATUS_UPDATED_AT_SETTING, now()->toDateTimeString(), 'string');

        if ($lifecycleState !== 'trial') {
            return;
        }

        $package = Package::query()->where('key', $tenant->package_key)->first();
        $trialDays = max(1, (int) ($package?->trial_days ?: 30));
        $trialStart = now()->toDateString();
        $trialEnd = now()->addDays($trialDays)->toDateString();

        TenantSetting::setValue($tenant->id, self::SUBSCRIPTION_TRIAL_START_SETTING, $trialStart, 'string');
        TenantSetting::setValue($tenant->id, self::SUBSCRIPTION_TRIAL_END_SETTING, $trialEnd, 'string');
    }

    private function normalizeLifecycleStateSetting(string $status): string
    {
        return match ($status) {
            'trial' => 'trial',
            'suspended' => 'suspended',
            'passive', 'inactive' => 'passive',
            'expired' => 'expired',
            default => 'active',
        };
    }

    private function normalizeStorageStatus(string $status): string
    {
        return match ($status) {
            'suspended' => 'suspended',
            'passive', 'inactive' => 'inactive',
            default => 'active',
        };
    }

    private function numberFormatLocaleFor(string $locale): string
    {
        return $locale === 'en' ? 'en_US' : 'tr_TR';
    }

    private function localHostPreviewNote(): string
    {
        return 'Local test için Windows/Laragon ortamında hosts veya wildcard vhost gerekebilir. Örnek hosts kaydı: 127.0.0.1 {tenant}.'
            . $this->centralPreviewHost();
    }

    private function ownerAssignmentQuery(TenantAccount $tenant)
    {
        return UserRole::query()
            ->with(['user', 'role'])
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('role', function ($query): void {
                $query->where('key', 'tenant_owner')->active();
            })
            ->orderBy('id');
    }

    private function ensureDefaultRole(string $roleKey): Role
    {
        $roleConfig = config('prodelya_permissions.default_roles.' . $roleKey);

        abort_unless(is_array($roleConfig), 500, 'Varsayılan tenant owner rolü tanımlı değil.');

        return Role::query()->updateOrCreate(
            ['key' => $roleKey],
            [
                'tenant_account_id' => null,
                'name' => $roleConfig['name'] ?? $roleKey,
                'description' => $roleConfig['description'] ?? null,
                'permissions' => $roleConfig['permissions'] ?? [],
                'is_system' => (bool) ($roleConfig['is_system'] ?? false),
                'is_active' => true,
            ]
        );
    }
}
