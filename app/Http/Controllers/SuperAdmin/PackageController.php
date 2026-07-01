<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    private const LIMIT_KEYS = [
        'users' => 'Kullanıcılar',
        'current_accounts' => 'Cari Kartlar',
        'companies' => 'Firmalar',
        'products' => 'Ürünler',
        'supplier_feeds' => 'Tedarikçi / Feed',
        'orders' => 'Siparişler',
        'storage_mb' => 'Depolama',
        'custom_domains' => 'Özel Domain',
        'api_tokens' => 'API Token',
    ];

    public function __construct(
        protected PackageCatalogService $packageCatalogService,
        protected ModuleFeatureCatalogService $catalogService
    ) {
    }

    public function index(): View
    {
        $packages = Package::query()
            ->with(['limits', 'tenants'])
            ->withCount(['modules', 'features', 'limits', 'tenants'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $packages->each(function (Package $package): void {
            $package->setAttribute('users_limit_label', $this->limitLabel($package, 'users'));
            $package->setAttribute('products_limit_label', $this->limitLabel($package, 'products'));
            $package->setAttribute('supplier_feeds_limit_label', $this->limitLabel($package, 'supplier_feeds'));
            $package->setAttribute('orders_limit_label', $this->limitLabel($package, 'orders'));
            $package->setAttribute('active_tenants_count', $package->tenants->where('status', 'active')->count());
            $package->setAttribute('trial_tenants_count', $package->tenants->where('status', 'trial')->count());
        });

        return view('super-admin.packages.index', [
            'packages' => $packages,
            'stats' => [
                'active' => $packages->where('status', 'active')->count(),
                'passive' => $packages->where('status', 'passive')->count(),
                'planned' => $packages->where('status', 'planned')->count(),
                'archived' => $packages->where('status', 'archived')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('super-admin.packages.create', [
            'package' => new Package([
                'status' => 'active',
                'currency' => 'TRY',
                'is_public' => true,
                'sort_order' => 0,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $package = Package::query()->create($this->validatePackage($request));

        return redirect()
            ->route('admin.super.packages.edit', $package)
            ->with('success', 'Paket oluşturuldu.');
    }

    public function show(Package $package): View
    {
        $package->load(['modules', 'features', 'limits', 'tenants']);
        $moduleCatalog = $this->moduleCatalogRows($package);
        $featureCatalog = $this->featureCatalogRows($package);
        $limitRows = $this->limitRows($package);
        $tenantRows = $this->tenantRows($package);

        return view('super-admin.packages.show', [
            'package' => $package,
            'moduleCatalog' => $moduleCatalog,
            'featureCatalog' => $featureCatalog,
            'limitRows' => $limitRows,
            'tenantRows' => $tenantRows,
            'overrideTenantCount' => collect($tenantRows)->where('has_override', true)->count(),
        ]);
    }

    public function edit(Package $package): View
    {
        $package->load(['modules', 'features', 'limits']);

        return view('super-admin.packages.edit', [
            'package' => $package,
            'moduleCatalog' => $this->moduleCatalogRows($package),
            'featureCatalog' => $this->featureCatalogRows($package),
            'limitRows' => $this->limitRows($package),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $package->update($this->validatePackage($request, $package));

        return redirect()
            ->route('admin.super.packages.edit', $package)
            ->with('success', 'Paket bilgileri güncellendi.');
    }

    public function updateModules(Request $request, Package $package): RedirectResponse
    {
        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],
        ]);

        $selected = collect($validated['modules'] ?? [])
            ->map(fn (string $key) => $this->catalogService->normalizeModuleKey($key))
            ->filter(fn (string $key) => $this->catalogService->getModule($key) !== null)
            ->unique()
            ->values();

        DB::transaction(function () use ($package, $selected): void {
            $package->modules()
                ->whereNotIn('module_key', $selected->all())
                ->delete();

            foreach ($selected as $moduleKey) {
                $package->modules()->updateOrCreate(
                    ['module_key' => $moduleKey],
                    ['is_enabled' => true, 'status' => 'active']
                );
            }
        });

        return redirect()
            ->route('admin.super.packages.edit', $package)
            ->with('success', 'Paket modülleri güncellendi.');
    }

    public function updateFeatures(Request $request, Package $package): RedirectResponse
    {
        $validated = $request->validate([
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
        ]);

        $selected = collect($validated['features'] ?? [])
            ->map(fn (string $key) => $this->catalogService->normalizeFeatureKey($key))
            ->filter(fn (string $key) => $this->catalogService->getFeature($key) !== null)
            ->unique()
            ->values();

        DB::transaction(function () use ($package, $selected): void {
            $package->features()
                ->whereNotIn('feature_key', $selected->all())
                ->delete();

            foreach ($selected as $featureKey) {
                $package->features()->updateOrCreate(
                    ['feature_key' => $featureKey],
                    [
                        'module_key' => $this->resolveFeatureModuleKey($featureKey),
                        'is_enabled' => true,
                        'status' => 'active',
                    ]
                );
            }
        });

        return redirect()
            ->route('admin.super.packages.edit', $package)
            ->with('success', 'Paket feature ayarları güncellendi.');
    }

    public function updateLimits(Request $request, Package $package): RedirectResponse
    {
        $rules = [];

        foreach (array_keys(self::LIMIT_KEYS) as $limitKey) {
            $rules["limits.$limitKey.limit_value"] = ['nullable', 'integer', 'min:0'];
            $rules["limits.$limitKey.is_unlimited"] = ['nullable', 'boolean'];
            $rules["limits.$limitKey.notes"] = ['nullable', 'string'];
        }

        $validated = $request->validate($rules);
        $limits = $validated['limits'] ?? [];

        DB::transaction(function () use ($package, $limits): void {
            foreach (self::LIMIT_KEYS as $limitKey => $label) {
                $payload = $limits[$limitKey] ?? [];
                $isUnlimited = (bool) ($payload['is_unlimited'] ?? false);
                $limitValue = $isUnlimited ? null : (isset($payload['limit_value']) && $payload['limit_value'] !== '' ? (int) $payload['limit_value'] : null);

                $package->limits()->updateOrCreate(
                    ['limit_key' => $limitKey],
                    [
                        'limit_value' => $limitValue,
                        'is_unlimited' => $isUnlimited,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
            }
        });

        return redirect()
            ->route('admin.super.packages.edit', $package)
            ->with('success', 'Paket limitleri güncellendi.');
    }

    private function validatePackage(Request $request, ?Package $package = null): array
    {
        return $request->validate([
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('packages', 'key')->ignore($package?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'passive', 'planned', 'archived'])],
            'is_public' => ['nullable', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function moduleCatalogRows(Package $package): array
    {
        $selected = $package->modules->keyBy('module_key');

        return collect($this->catalogService->modules())
            ->reject(fn (array $module) => ($module['status'] ?? 'passive') === 'deprecated')
            ->map(function (array $module) use ($selected): array {
                $record = $selected->get($module['key']);

                return [
                    'key' => $module['key'],
                    'label' => $module['label'],
                    'category' => $module['category'] ?? null,
                    'status' => $module['status'] ?? 'passive',
                    'is_core' => (bool) ($module['is_core'] ?? false),
                    'enabled' => (bool) ($module['is_core'] ?? false) || ($record?->is_enabled ?? false),
                    'locked' => (bool) ($module['is_core'] ?? false),
                    'description' => $module['description'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function featureCatalogRows(Package $package): array
    {
        $selected = $package->features->keyBy('feature_key');
        $rows = [];

        foreach ($this->catalogService->features() as $moduleKey => $features) {
            $module = $this->catalogService->getModule($moduleKey);
            $moduleLabel = $module['label'] ?? $moduleKey;

            foreach ($features as $feature) {
                if (($feature['status'] ?? 'passive') === 'deprecated') {
                    continue;
                }

                $record = $selected->get($feature['key']);
                $rows[] = [
                    'key' => $feature['key'],
                    'label' => $feature['label'] ?? $feature['key'],
                    'module_key' => $moduleKey,
                    'module_label' => $moduleLabel,
                    'status' => $feature['status'] ?? 'passive',
                    'enabled' => (bool) ($record?->is_enabled ?? false),
                    'description' => $feature['description'] ?? null,
                ];
            }
        }

        return $rows;
    }

    private function limitRows(Package $package): array
    {
        $selected = $package->limits->keyBy('limit_key');

        return collect(self::LIMIT_KEYS)
            ->map(function (string $label, string $key) use ($selected): array {
                $record = $selected->get($key);

                return [
                    'key' => $key,
                    'label' => $label,
                    'limit_value' => $record?->limit_value,
                    'is_unlimited' => (bool) ($record?->is_unlimited ?? false),
                    'notes' => $record?->notes,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveFeatureModuleKey(string $featureKey): ?string
    {
        foreach ($this->catalogService->features() as $moduleKey => $features) {
            if (array_key_exists($featureKey, $features)) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function limitLabel(Package $package, string $limitKey): string
    {
        $limit = $this->packageCatalogService->getLimit($package, $limitKey);

        if ($limit === null) {
            return 'Takip edilmiyor';
        }

        if ($limit['is_unlimited'] ?? false) {
            return 'Limitsiz';
        }

        return isset($limit['limit_value']) ? (string) $limit['limit_value'] : 'Takip edilmiyor';
    }

    private function tenantRows(Package $package): array
    {
        return $package->tenants
            ->sortBy('name')
            ->values()
            ->map(function (TenantAccount $tenant): array {
                $hasOverride = TenantModule::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->exists()
                    || TenantSetting::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('key', 'like', 'limit_%')
                        ->exists();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'panel_subdomain' => $tenant->panel_subdomain,
                    'status' => $tenant->status,
                    'has_override' => $hasOverride,
                ];
            })
            ->all();
    }
}
