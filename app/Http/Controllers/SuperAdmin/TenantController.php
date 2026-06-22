<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use App\Services\TenantAccessService;
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
use Illuminate\View\View;

class TenantController extends Controller
{
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
        protected TenantOnboardingDefaultsService $tenantOnboardingDefaultsService,
        protected TenantOnboardingStatusService $tenantOnboardingStatusService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenants = TenantAccount::query()
            ->with(['modules', 'package'])
            ->orderBy('name')
            ->get();

        return view('super-admin.tenants.index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): View
    {
        return view('super-admin.tenants.create', [
            'packages' => $this->packageCatalogService->activePackages(),
            'defaultValues' => [
                'status' => 'active',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
            ],
            'statusOptions' => $this->statusOptions(),
            'localeOptions' => $this->localeOptions(),
            'timezoneOptions' => $this->timezoneOptions(),
            'centralPreviewHost' => $this->centralPreviewHost(),
            'localHostPreviewNote' => $this->localHostPreviewNote(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $normalized = $this->normalizeTenantCreateInput($request);
        $validated = $this->validateTenantCreateInput($normalized);

        $tenant = TenantAccount::query()->create([
            'name' => $validated['name'],
            'legal_name' => $validated['legal_name'] ?: null,
            'slug' => $validated['slug'],
            'panel_subdomain' => $validated['panel_subdomain'],
            'custom_domain' => $validated['custom_domain'] ?: null,
            'portal_domain' => $validated['portal_domain'] ?: null,
            'status' => $validated['status'],
            'package_key' => $validated['package_key'],
            'default_locale' => $validated['default_locale'],
            'default_currency' => $validated['default_currency'],
            'timezone' => $validated['timezone'],
            'number_format_locale' => $this->numberFormatLocaleFor($validated['default_locale']),
        ]);

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Tenant oluşturuldu. Owner kullanıcı ve başlangıç ayarları sonraki adımda tamamlanmalıdır.');
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
            ->with('success', 'Tenant owner kullanıcısı oluşturuldu.')
            ->with('owner_temporary_password', $generatedPassword);
    }

    public function prepareDefaults(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $platformAdmin = $request->user();

        abort_unless($platformAdmin instanceof User, 403);

        $result = $this->tenantOnboardingDefaultsService->prepareDefaults($tenant, $platformAdmin);

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Tenant başlangıç ayarları hazırlandı.')
            ->with('onboarding_defaults_summary', $result->summary());
    }

    public function update(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'package_key' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
        ]);

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

        $tenant->update([
            'package_key' => $packageKey !== '' ? $packageKey : null,
            'status' => $validated['status'],
        ]);

        return redirect()
            ->route('admin.super.tenants.edit', $tenant)
            ->with('success', 'Tenant paket ve durum bilgisi guncellendi.');
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
            ->with('success', 'Tenant modul override ayarlari guncellendi.');
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
            ->with('success', 'Tenant feature override ayarlari guncellendi.');
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
            ->with('success', 'Tenant limit override ayarlari guncellendi.');
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
        $summary = $this->tenantAccessService->effectiveAccessSummary($tenant);
        $tenantMenuItems = $this->adminMenuService->tenantMenu($tenant, auth()->user());
        $ownerAssignment = $this->ownerAssignmentQuery($tenant)->first();
        $ownerUser = $ownerAssignment?->user;
        $ownerRole = $ownerAssignment?->role;
        $onboardingStatus = $this->tenantOnboardingStatusService->forTenant($tenant);

        return [
            'tenant' => $tenant,
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
            'tenantAdminPreviewUrl' => 'http://' . $tenant->panel_subdomain . '.' . $this->centralPreviewHost() . '/admin',
            'tenantPanelPreviewHost' => $tenant->panel_subdomain !== '' ? $tenant->panel_subdomain . '.' . $this->centralPreviewHost() : null,
            'tenantCustomDomainPreview' => filled($tenant->custom_domain) ? 'http://' . $tenant->custom_domain . '/admin' : null,
            'tenantPortalDomainPreview' => filled($tenant->portal_domain) ? 'http://' . $tenant->portal_domain . '/musteri-giris' : null,
            'localHostPreviewNote' => $this->localHostPreviewNote(),
            'moduleRows' => $moduleRows,
            'featureRows' => $featureRows,
            'limitRows' => $limitRows,
            'effectiveAccessSummary' => $summary,
            'tenantMenuLabels' => collect($tenantMenuItems)
                ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
                ->filter()
                ->values()
                ->all(),
        ];
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
        return [
            'name' => trim((string) $request->input('name')),
            'legal_name' => trim((string) $request->input('legal_name')),
            'slug' => Str::slug((string) $request->input('slug')),
            'panel_subdomain' => Str::slug((string) $request->input('panel_subdomain')),
            'custom_domain' => $this->normalizeDomain((string) $request->input('custom_domain')),
            'portal_domain' => $this->normalizeDomain((string) $request->input('portal_domain')),
            'status' => strtolower(trim((string) $request->input('status', 'active'))),
            'package_key' => trim((string) $request->input('package_key')),
            'default_locale' => strtolower(trim((string) $request->input('default_locale', 'tr'))),
            'default_currency' => strtoupper(trim((string) $request->input('default_currency', 'TL'))),
            'timezone' => trim((string) $request->input('timezone', 'Europe/Istanbul')),
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
        });

        return $validator->validate();
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
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
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
            'trial' => 'Trial',
            'inactive' => 'Pasif',
            'suspended' => 'Askıya Alındı',
        ];
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
