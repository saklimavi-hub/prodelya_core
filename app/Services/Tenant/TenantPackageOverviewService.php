<?php

namespace App\Services\Tenant;

use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\User;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantSubscriptionStatusService;
use App\Services\TenantUsageService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class TenantPackageOverviewService
{
    public function __construct(
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected ModuleFeatureCatalogService $catalogService,
        protected PackageCatalogService $packageCatalogService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(TenantAccount $tenant, ?User $user = null): array
    {
        $tenant->loadMissing('package.modules', 'package.features', 'package.limits');

        $package = $tenant->package;
        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $modules = $this->buildModuleSections($tenant);
        $requestQuery = TenantPackageUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id);

        $requests = (clone $requestQuery)
            ->with(['requestedPackage', 'currentPackage'])
            ->latest()
            ->take(6)
            ->get();

        $usageSnapshots = array_values($this->tenantUsageService->getUsageSnapshot($tenant));
        $usageWarnings = array_values(array_filter(
            $usageSnapshots,
            fn (array $item) => in_array($item['status'] ?? null, ['warning', 'exceeded'], true)
        ));

        $packageRequestRoute = Route::has('admin.package-requests.index')
            ? route('admin.package-requests.index')
            : null;

        return [
            'tenant_summary' => [
                'name' => $tenant->name,
                'panel_host' => $tenant->panel_subdomain ? $tenant->panel_subdomain . '.' . $this->centralHost() : null,
            ],
            'package_summary' => [
                'name' => $package?->name ?? ($tenant->package_key ?: 'Tanımlı değil'),
                'key' => $package?->key ?? $tenant->package_key,
                'status' => $package?->status ?? 'missing',
                'status_label' => $package?->safeStatusLabel() ?? 'Bulunamadı',
                'is_public' => (bool) ($package?->is_public ?? false),
                'monthly_price' => $package?->formattedPrice('monthly'),
                'yearly_price' => $package?->formattedPrice('yearly'),
                'currency' => $package?->currency ?? $tenant->default_currency,
                'warning' => $this->packageWarning($package),
                'limit_summary' => $this->buildPackageLimitSummary($package),
            ],
            'subscription_summary' => $subscription,
            'usage_snapshots' => $usageSnapshots,
            'active_modules' => $modules['active'],
            'upgradeable_modules' => $modules['upgradeable'],
            'package_requests' => [
                'items' => $requests,
                'open_count' => (clone $requestQuery)->whereIn('status', [
                    TenantPackageUpgradeRequest::STATUS_NEW,
                    TenantPackageUpgradeRequest::STATUS_APPROVED,
                ])->count(),
                'route' => $packageRequestRoute,
                'status_labels' => TenantPackageUpgradeRequest::statusOptions(),
            ],
            'recommended_actions' => [
                'package_request_route' => $packageRequestRoute,
                'settings_route' => Route::has('admin.settings') ? route('admin.settings') : null,
            ],
            'upcoming_request_types' => [
                ['label' => 'Ek Modül Talebi', 'status' => 'Sonraki faz'],
                ['label' => 'Limit Artırma Talebi', 'status' => 'Sonraki faz'],
                ['label' => 'XML / Feed Hizmeti', 'status' => 'Sonraki faz'],
                ['label' => 'Product Data Hub Erişimi', 'status' => 'Sonraki faz'],
            ],
            'warnings' => $this->buildWarnings($subscription, $usageWarnings, $requests, (clone $requestQuery)->whereIn('status', [
                TenantPackageUpgradeRequest::STATUS_NEW,
                TenantPackageUpgradeRequest::STATUS_APPROVED,
            ])->count()),
        ];
    }

    private function centralHost(): string
    {
        $host = (string) config('prodelya_domains.panel_domain');

        if (trim($host) !== '') {
            return trim($host);
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return trim($host) !== '' ? $host : 'prodelya_core.test';
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildModuleSections(TenantAccount $tenant): array
    {
        $active = [];
        $upgradeable = [];
        $featureCatalog = $this->catalogService->features();

        foreach ($this->catalogService->modules() as $key => $module) {
            $status = $this->tenantAccessService->moduleStatus($tenant, $key);
            $moduleStatus = (string) ($module['status'] ?? 'passive');

            if ($moduleStatus === 'deprecated') {
                continue;
            }

            $item = [
                'key' => $key,
                'label' => $module['label'] ?? Str::headline($key),
                'description' => $module['description'] ?? null,
                'status_label' => $this->moduleStatusLabel($module, $status),
                'status_tone' => $this->moduleStatusTone($module, $status),
                'unlock_label' => $this->moduleUnlockLabel($module, $status),
                'feature_summary' => $this->buildFeatureSummary($tenant, $key, $featureCatalog[$key] ?? []),
                'is_core' => (bool) ($module['is_core'] ?? false),
                'requires_package' => (bool) ($module['requires_package'] ?? false),
            ];

            if ((bool) ($status['enabled'] ?? false) && !in_array($moduleStatus, ['planned', 'passive'], true)) {
                $active[] = $item;
                continue;
            }

            if (!($module['is_core'] ?? false)) {
                $upgradeable[] = $item;
            }
        }

        return [
            'active' => $active,
            'upgradeable' => $upgradeable,
        ];
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $status
     */
    private function moduleStatusLabel(array $module, array $status): string
    {
        if (($module['is_core'] ?? false) && ($status['enabled'] ?? false)) {
            return 'Temel sistem modülü';
        }

        if (($status['enabled'] ?? false) && ($status['source'] ?? null) === 'package') {
            return 'Paket ile aktif';
        }

        if (($status['enabled'] ?? false)) {
            return 'Super Admin override ile aktif';
        }

        return match ($module['status'] ?? 'passive') {
            'planned' => 'Yakında / Planlı',
            'passive' => 'Yakında',
            default => match ($status['reason'] ?? null) {
                'tenant_module_disabled' => 'Pasif',
                'subscription_expired', 'subscription_restricted' => 'Abonelik kısıtlı',
                default => 'Paket yükseltme gerekli',
            },
        };
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $status
     */
    private function moduleStatusTone(array $module, array $status): string
    {
        if (($status['enabled'] ?? false)) {
            return 'green';
        }

        if (in_array($module['status'] ?? 'passive', ['planned', 'passive'], true)) {
            return 'gray';
        }

        if (in_array($status['reason'] ?? null, ['subscription_expired', 'subscription_restricted'], true)) {
            return 'red';
        }

        return 'amber';
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $status
     */
    private function moduleUnlockLabel(array $module, array $status): string
    {
        if (($status['enabled'] ?? false)) {
            return 'Kullanıma hazır';
        }

        $moduleStatus = $module['status'] ?? 'passive';

        if (in_array($moduleStatus, ['planned', 'passive'], true)) {
            return 'Yakında';
        }

        if (($status['reason'] ?? null) === 'tenant_module_disabled') {
            return 'Super Admin onayı';
        }

        if (($module['requires_package'] ?? false) === true) {
            return 'Daha üst paket';
        }

        return in_array($status['key'] ?? '', ['product_data_hub', 'supplier_feed', 'xml_import_export', 'xml_export', 'customer_portal'], true)
            ? 'Super Admin onayı'
            : 'Ek hizmet';
    }

    /**
     * @param  array<string, array<string, mixed>>  $features
     * @return array<string, mixed>
     */
    private function buildFeatureSummary(TenantAccount $tenant, string $moduleKey, array $features): array
    {
        $labels = [];

        foreach ($features as $featureKey => $feature) {
            if (in_array($feature['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                continue;
            }

            if ($this->tenantAccessService->canAccessFeature($tenant, $featureKey, $moduleKey)) {
                $labels[] = $feature['label'] ?? Str::headline($featureKey);
            }
        }

        return [
            'items' => array_slice($labels, 0, 3),
            'extra_count' => max(0, count($labels) - 3),
        ];
    }

    private function packageWarning(mixed $package): ?string
    {
        if (!$package) {
            return 'Paket kaydı bulunamadı.';
        }

        if (!$package->isActive()) {
            return 'Bu paket aktif katalogda görünmüyor.';
        }

        if (!$package->is_public) {
            return 'Bu paket public pakette görünmüyor; Super Admin tarafından atanmış olabilir.';
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildPackageLimitSummary(mixed $package): array
    {
        if (!$package) {
            return [];
        }

        return $package->limits
            ->take(4)
            ->map(fn ($limit) => [
                'label' => $limit->label ?: Str::headline((string) $limit->limit_key),
                'value' => $limit->is_unlimited ? 'Limitsiz' : (string) $limit->limit_value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<int, array<string, mixed>>  $usageWarnings
     * @param  \Illuminate\Support\Collection<int, TenantPackageUpgradeRequest>  $requests
     * @return array<int, array<string, string>>
     */
    private function buildWarnings(array $subscription, array $usageWarnings, $requests, int $openCount): array
    {
        $warnings = [];

        if (filled($subscription['warning_label'] ?? null)) {
            $warnings[] = [
                'tone' => 'amber',
                'label' => (string) $subscription['warning_label'],
            ];
        }

        foreach ($usageWarnings as $warning) {
            $warnings[] = [
                'tone' => ($warning['status'] ?? null) === 'exceeded' ? 'red' : 'amber',
                'label' => ($warning['label'] ?? 'Limit') . ' ' . (($warning['status'] ?? null) === 'exceeded' ? 'aşıldı' : 'yaklaşıyor'),
            ];
        }

        if ($openCount > 0) {
            $warnings[] = [
                'tone' => 'blue',
                'label' => $openCount . ' açık paket talebi var',
            ];
        }

        return array_slice($warnings, 0, 5);
    }
}
