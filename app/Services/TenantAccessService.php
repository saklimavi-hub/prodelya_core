<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;

class TenantAccessService
{
    public function __construct(
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
        protected TenantUsageService $usageService,
        protected PackageCatalogService $packageCatalogService,
    ) {
    }

    public function canAccessModule(TenantAccount $tenant, string $moduleKey): bool
    {
        return (bool) ($this->moduleStatus($tenant, $moduleKey)['enabled'] ?? false);
    }

    public function canAccessFeature(TenantAccount $tenant, string $featureKey, ?string $moduleKey = null): bool
    {
        return (bool) ($this->featureStatus($tenant, $featureKey, $moduleKey)['enabled'] ?? false);
    }

    public function effectiveModules(TenantAccount $tenant): array
    {
        $statuses = [];

        foreach ($this->catalogService->modules() as $key => $module) {
            $statuses[$key] = $this->moduleStatus($tenant, $key);
        }

        return $statuses;
    }

    public function effectiveFeatures(TenantAccount $tenant): array
    {
        $features = [];

        foreach ($this->catalogService->features() as $moduleKey => $moduleFeatures) {
            foreach ($moduleFeatures as $featureKey => $feature) {
                $features[$featureKey] = $this->featureStatus($tenant, $featureKey, $moduleKey);
            }
        }

        return $features;
    }

    public function moduleStatus(TenantAccount $tenant, string $moduleKey): array
    {
        $normalizedKey = $this->normalizeModuleKey($moduleKey);
        $module = $this->catalogService->getModule($normalizedKey);
        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $record = $this->resolveTenantModuleRecord($tenant, $normalizedKey);
        $package = $this->resolvePackage($tenant);

        if (!$module) {
            return [
                'key' => $normalizedKey,
                'status' => 'unknown',
                'enabled' => false,
                'reason' => 'unknown_module',
                'subscription' => $subscription['status'],
                'module_status' => null,
                'is_core' => false,
                'source' => 'catalog',
            ];
        }

        $moduleStatus = $module['status'] ?? 'passive';
        $subscriptionState = $subscription['status'];
        $isCore = (bool) ($module['is_core'] ?? false);
        $enabled = false;
        $reason = 'disabled';
        $source = 'catalog';

        if (in_array($moduleStatus, ['planned', 'passive'], true)) {
            $enabled = false;
            $reason = $moduleStatus;
        } elseif ($subscriptionState === 'suspended' || $subscriptionState === 'passive') {
            $enabled = false;
            $reason = 'subscription_restricted';
        } elseif ($isCore) {
            $enabled = in_array($subscriptionState, ['active', 'trial'], true)
                || ($subscriptionState === 'expired' && in_array($normalizedKey, ['core', 'tenant_settings'], true));
            $reason = $enabled ? 'core' : 'subscription_restricted';
            $source = 'core';
        } elseif ($this->shouldGrantDemoTenantModuleAccess($tenant, $moduleStatus, $subscriptionState)) {
            $enabled = true;
            $reason = 'demo_tenant_catalog_access';
            $source = 'demo_catalog';
        } elseif ($subscriptionState === 'expired') {
            $enabled = false;
            $reason = 'subscription_expired';
            $source = $record ? 'tenant_module' : 'catalog';
        } elseif ($record) {
            $enabled = (bool) $record->is_enabled;
            $reason = $enabled ? 'tenant_module' : 'tenant_module_disabled';
            $source = 'tenant_module';
        } elseif ($package && $this->packageCatalogService->hasModule($package, $normalizedKey)) {
            $enabled = true;
            $reason = 'package_module';
            $source = 'package';
        } elseif ($this->enabledByLegacyBridge($tenant, $normalizedKey)) {
            $enabled = true;
            $reason = 'legacy_bridge';
            $source = 'tenant_settings';
        }

        return [
            'key' => $normalizedKey,
            'status' => $enabled ? 'enabled' : 'disabled',
            'enabled' => $enabled,
            'reason' => $reason,
            'subscription' => $subscriptionState,
            'module_status' => $moduleStatus,
            'is_core' => $isCore,
            'source' => $source,
            'limit_value' => $record?->limit_value,
            'requires_package' => (bool) ($module['requires_package'] ?? false),
            'package_key' => $package?->key,
        ];
    }

    public function featureStatus(TenantAccount $tenant, string $featureKey, ?string $moduleKey = null): array
    {
        $normalizedFeatureKey = $this->normalizeFeatureKey($featureKey);
        $resolvedModuleKey = $moduleKey ? $this->normalizeModuleKey($moduleKey) : $this->resolveModuleKeyForFeature($normalizedFeatureKey);
        $feature = $this->catalogService->getFeature($normalizedFeatureKey);
        $moduleStatus = $resolvedModuleKey ? $this->moduleStatus($tenant, $resolvedModuleKey) : null;
        $package = $this->resolvePackage($tenant);
        $override = $this->resolveTenantFeatureOverride($tenant, $normalizedFeatureKey, $resolvedModuleKey);

        if (!$feature || !$resolvedModuleKey || !$moduleStatus) {
            return [
                'key' => $normalizedFeatureKey,
                'module_key' => $resolvedModuleKey,
                'status' => 'unknown',
                'enabled' => false,
                'reason' => 'unknown_feature',
            ];
        }

        $featureStatus = $feature['status'] ?? 'passive';
        if (in_array($featureStatus, ['planned', 'passive'], true)) {
            return [
                'key' => $normalizedFeatureKey,
                'module_key' => $resolvedModuleKey,
                'status' => 'disabled',
                'enabled' => false,
                'reason' => $featureStatus,
            ];
        }

        if ($this->shouldGrantDemoTenantFeatureAccess($tenant, $featureStatus, $moduleStatus)) {
            return [
                'key' => $normalizedFeatureKey,
                'module_key' => $resolvedModuleKey,
                'status' => 'enabled',
                'enabled' => true,
                'reason' => 'demo_tenant_catalog_access',
            ];
        }

        if ($override) {
            if (in_array($moduleStatus['reason'] ?? null, ['subscription_restricted', 'subscription_expired'], true)) {
                return [
                    'key' => $normalizedFeatureKey,
                    'module_key' => $resolvedModuleKey,
                    'status' => 'disabled',
                    'enabled' => false,
                    'reason' => $moduleStatus['reason'],
                ];
            }

            return [
                'key' => $normalizedFeatureKey,
                'module_key' => $resolvedModuleKey,
                'status' => $override->is_enabled ? 'enabled' : 'disabled',
                'enabled' => (bool) $override->is_enabled,
                'reason' => $override->is_enabled ? 'tenant_feature_override' : 'tenant_feature_disabled',
            ];
        }

        if ($package) {
            $packageFeature = $package->features->first(function ($record) use ($normalizedFeatureKey, $resolvedModuleKey) {
                return $record->feature_key === $normalizedFeatureKey
                    && ($record->module_key === null || $record->module_key === $resolvedModuleKey);
            });

            if ($packageFeature) {
                if (in_array($moduleStatus['reason'] ?? null, ['subscription_restricted', 'subscription_expired'], true)) {
                    return [
                        'key' => $normalizedFeatureKey,
                        'module_key' => $resolvedModuleKey,
                        'status' => 'disabled',
                        'enabled' => false,
                        'reason' => $moduleStatus['reason'],
                    ];
                }

                return [
                    'key' => $normalizedFeatureKey,
                    'module_key' => $resolvedModuleKey,
                    'status' => $packageFeature->is_enabled ? 'enabled' : 'disabled',
                    'enabled' => (bool) $packageFeature->is_enabled,
                    'reason' => $packageFeature->is_enabled ? 'package_feature' : 'package_feature_disabled',
                ];
            }
        }

        if (!$moduleStatus['enabled']) {
            return [
                'key' => $normalizedFeatureKey,
                'module_key' => $resolvedModuleKey,
                'status' => 'disabled',
                'enabled' => false,
                'reason' => 'module_disabled',
            ];
        }

        return [
            'key' => $normalizedFeatureKey,
            'module_key' => $resolvedModuleKey,
            'status' => 'enabled',
            'enabled' => true,
            'reason' => 'feature_enabled',
        ];
    }

    public function effectiveAccessSummary(TenantAccount $tenant): array
    {
        return [
            'subscription' => $this->subscriptionStatusService->getStatus($tenant),
            'modules' => $this->effectiveModules($tenant),
            'features' => $this->effectiveFeatures($tenant),
            'usage' => $this->usageService->getUsageSnapshot($tenant),
            'warnings' => $this->usageService->warningItems($tenant),
        ];
    }

    public function normalizeModuleKey(string $key): string
    {
        return $this->catalogService->normalizeModuleKey($key);
    }

    public function normalizeFeatureKey(string $key): string
    {
        return $this->catalogService->normalizeFeatureKey($key);
    }

    private function resolveTenantModuleRecord(TenantAccount $tenant, string $normalizedKey): ?TenantModule
    {
        $tenant->loadMissing('modules');

        $moduleLevelRecord = $tenant->modules->first(function (TenantModule $module) use ($normalizedKey) {
            return $this->normalizeModuleKey((string) $module->module_key) === $normalizedKey
                && blank($module->feature_key);
        });

        if ($moduleLevelRecord) {
            return $moduleLevelRecord;
        }

        return $tenant->modules
            ->filter(function (TenantModule $module) use ($normalizedKey) {
                return $this->normalizeModuleKey((string) $module->module_key) === $normalizedKey
                    && filled($module->feature_key);
            })
            ->sortByDesc(fn (TenantModule $module) => (int) $module->is_enabled)
            ->first();
    }

    private function resolveTenantFeatureOverride(TenantAccount $tenant, string $featureKey, ?string $moduleKey): ?TenantModule
    {
        $tenant->loadMissing('modules');

        return $tenant->modules->first(function (TenantModule $module) use ($featureKey, $moduleKey) {
            if (blank($module->feature_key)) {
                return false;
            }

            $normalizedFeatureKey = $this->normalizeFeatureKey((string) $module->feature_key);
            $normalizedModuleKey = filled($module->module_key)
                ? $this->normalizeModuleKey((string) $module->module_key)
                : null;

            return $normalizedFeatureKey === $featureKey
                && ($moduleKey === null || $normalizedModuleKey === $moduleKey);
        });
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

    private function enabledByLegacyBridge(TenantAccount $tenant, string $moduleKey): bool
    {
        return match ($moduleKey) {
            'customer_portal' => (bool) TenantSetting::getValue($tenant->id, 'enable_customer_portal', false)
                || (bool) TenantSetting::getValue($tenant->id, 'portal_enabled', false),
            'xml_export' => (bool) TenantSetting::getValue($tenant->id, 'enable_xml_export', false),
            'product_data_hub' => $tenant->supplierAccesses()->active()->exists(),
            'supplier_feed' => $tenant->supplierAccesses()->active()->exists(),
            default => false,
        };
    }

    private function resolvePackage(TenantAccount $tenant): ?\App\Models\Package
    {
        $packageKey = trim((string) ($tenant->package_key ?? ''));

        if ($packageKey === '') {
            return null;
        }

        return $this->packageCatalogService->getPackageByKey($packageKey);
    }

    private function shouldGrantDemoTenantModuleAccess(TenantAccount $tenant, ?string $moduleStatus, string $subscriptionState): bool
    {
        return $this->isFullAccessDemoTenant($tenant)
            && in_array($subscriptionState, ['active', 'trial'], true)
            && in_array($moduleStatus, ['active'], true);
    }

    private function shouldGrantDemoTenantFeatureAccess(TenantAccount $tenant, ?string $featureStatus, array $moduleStatus): bool
    {
        return $this->isFullAccessDemoTenant($tenant)
            && in_array($featureStatus, ['active'], true)
            && (bool) ($moduleStatus['enabled'] ?? false)
            && in_array($moduleStatus['module_status'] ?? null, ['active'], true)
            && !in_array($moduleStatus['reason'] ?? null, ['subscription_restricted', 'subscription_expired'], true);
    }

    private function isFullAccessDemoTenant(TenantAccount $tenant): bool
    {
        $panelSubdomain = strtolower(trim((string) ($tenant->panel_subdomain ?? '')));
        $slug = strtolower(trim((string) ($tenant->slug ?? '')));
        $packageKey = strtolower(trim((string) ($tenant->package_key ?? '')));

        if ($panelSubdomain !== 'demo' && $slug !== 'demo') {
            return false;
        }

        return $packageKey === '' || $packageKey === 'demo';
    }
}
