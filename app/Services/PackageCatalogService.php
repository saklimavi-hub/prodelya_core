<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageFeature;
use App\Models\PackageLimit;
use App\Models\PackageModule;
use Illuminate\Support\Collection;

class PackageCatalogService
{
    public function __construct(
        protected ModuleFeatureCatalogService $catalogService
    ) {
    }

    public function activePackages(): Collection
    {
        return Package::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getPackageByKey(string $key): ?Package
    {
        return Package::query()
            ->with(['modules', 'features', 'limits'])
            ->where('key', trim($key))
            ->first();
    }

    public function packageModules(Package $package): array
    {
        $package->loadMissing('modules');

        return $package->modules
            ->map(fn (PackageModule $module) => [
                'module_key' => $this->catalogService->normalizeModuleKey($module->module_key),
                'is_enabled' => (bool) $module->is_enabled,
                'status' => $module->status,
                'notes' => $module->notes,
            ])
            ->all();
    }

    public function packageFeatures(Package $package): array
    {
        $package->loadMissing('features');

        return $package->features
            ->map(fn (PackageFeature $feature) => [
                'module_key' => $feature->module_key ? $this->catalogService->normalizeModuleKey($feature->module_key) : null,
                'feature_key' => $this->catalogService->normalizeFeatureKey($feature->feature_key),
                'is_enabled' => (bool) $feature->is_enabled,
                'status' => $feature->status,
                'notes' => $feature->notes,
            ])
            ->all();
    }

    public function packageLimits(Package $package): array
    {
        $package->loadMissing('limits');

        return $package->limits
            ->map(fn (PackageLimit $limit) => [
                'limit_key' => $limit->limit_key,
                'limit_value' => $limit->effectiveLimitValue(),
                'is_unlimited' => $limit->isUnlimited(),
                'notes' => $limit->notes,
            ])
            ->all();
    }

    public function hasModule(Package $package, string $moduleKey): bool
    {
        $normalizedKey = $this->catalogService->normalizeModuleKey($moduleKey);
        $catalogModule = $this->catalogService->getModule($normalizedKey);

        if (!$catalogModule || !in_array($catalogModule['status'] ?? 'passive', ['core', 'active'], true)) {
            return false;
        }

        $package->loadMissing('modules');
        $record = $package->modules->first(fn (PackageModule $module) => $module->module_key === $normalizedKey);

        return $record !== null && $record->is_enabled && $record->status === 'active';
    }

    public function hasFeature(Package $package, string $featureKey, ?string $moduleKey = null): bool
    {
        $normalizedFeatureKey = $this->catalogService->normalizeFeatureKey($featureKey);
        $catalogFeature = $this->catalogService->getFeature($normalizedFeatureKey);

        if (!$catalogFeature || !in_array($catalogFeature['status'] ?? 'passive', ['core', 'active'], true)) {
            return false;
        }

        $normalizedModuleKey = $moduleKey ? $this->catalogService->normalizeModuleKey($moduleKey) : null;

        $package->loadMissing('features');
        $record = $package->features->first(function (PackageFeature $feature) use ($normalizedFeatureKey, $normalizedModuleKey) {
            return $feature->feature_key === $normalizedFeatureKey
                && ($normalizedModuleKey === null || $feature->module_key === null || $feature->module_key === $normalizedModuleKey);
        });

        return $record !== null && $record->is_enabled && $record->status === 'active';
    }

    public function getLimit(Package $package, string $limitKey): ?array
    {
        $package->loadMissing('limits');
        $record = $package->limits->firstWhere('limit_key', $limitKey);

        if (!$record) {
            return null;
        }

        return [
            'limit_key' => $record->limit_key,
            'limit_value' => $record->effectiveLimitValue(),
            'is_unlimited' => $record->isUnlimited(),
            'notes' => $record->notes,
        ];
    }
}
