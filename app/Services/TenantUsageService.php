<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\UserRole;

class TenantUsageService
{
    private const WARNING_PERCENTAGE = 80;

    public function __construct(
        protected PackageCatalogService $packageCatalogService
    ) {
    }

    public function getUsageSnapshot(TenantAccount $tenant): array
    {
        $keys = [
            'users',
            'current_accounts',
            'companies',
            'products',
            'supplier_feeds',
            'orders',
            'storage_mb',
            'custom_domains',
        ];

        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = $this->getUsageForKey($tenant, $key);
        }

        return $snapshot;
    }

    public function getUsageForKey(TenantAccount $tenant, string $key): array
    {
        $current = $this->currentUsage($tenant, $key);
        $limit = $this->getLimit($tenant, $key);

        if ($limit === null) {
            return [
                'key' => $key,
                'label' => $this->label($key),
                'current' => $current,
                'limit' => null,
                'percentage' => null,
                'status' => 'unlimited',
            ];
        }

        $percentage = $limit > 0 ? (int) min(100, floor(($current / $limit) * 100)) : 100;
        $status = $current > $limit
            ? 'exceeded'
            : ($percentage >= self::WARNING_PERCENTAGE ? 'warning' : 'ok');

        return [
            'key' => $key,
            'label' => $this->label($key),
            'current' => $current,
            'limit' => $limit,
            'percentage' => $percentage,
            'status' => $status,
        ];
    }

    public function getLimit(TenantAccount $tenant, string $key): mixed
    {
        $settingLimit = TenantSetting::getValue($tenant->id, 'limit_' . $key, null);
        if ($settingLimit === 'unlimited') {
            return null;
        }

        if ($settingLimit !== null && $settingLimit !== '') {
            return (int) $settingLimit;
        }

        $packageLimit = $this->packageLimit($tenant, $key);
        if ($packageLimit !== null) {
            return $packageLimit;
        }

        $moduleLimit = $this->moduleLimit($tenant, $key);
        if ($moduleLimit !== null) {
            return $moduleLimit;
        }

        $legacyPackageLimit = config('prodelya_packages.limits.' . ($tenant->package_key ?: 'default') . '.' . $key);

        return $legacyPackageLimit !== null ? (int) $legacyPackageLimit : null;
    }

    public function isExceeded(TenantAccount $tenant, string $key): bool
    {
        return $this->getUsageForKey($tenant, $key)['status'] === 'exceeded';
    }

    public function warningItems(TenantAccount $tenant): array
    {
        return array_values(array_filter(
            $this->getUsageSnapshot($tenant),
            fn (array $item) => in_array($item['status'], ['warning', 'exceeded'], true)
        ));
    }

    private function currentUsage(TenantAccount $tenant, string $key): int
    {
        return match ($key) {
            'users' => UserRole::query()
                ->where('tenant_account_id', $tenant->id)
                ->distinct('user_id')
                ->count('user_id'),
            'current_accounts' => $tenant->currentAccounts()->count(),
            'companies' => $tenant->companies()->count(),
            'products' => TenantCatalogProduct::query()->where('tenant_account_id', $tenant->id)->count(),
            'supplier_feeds' => $tenant->supplierAccesses()->active()->count(),
            'orders' => Order::query()->where('tenant_account_id', $tenant->id)->count(),
            'storage_mb' => (int) TenantSetting::getValue($tenant->id, 'storage_used_mb', 0),
            'custom_domains' => count(array_filter([
                $tenant->custom_domain,
                $tenant->portal_domain,
            ])),
            default => 0,
        };
    }

    private function moduleLimit(TenantAccount $tenant, string $key): ?int
    {
        $moduleKey = match ($key) {
            'supplier_feeds' => 'supplier_feed',
            'api_tokens' => 'api_access',
            default => null,
        };

        if ($moduleKey === null) {
            return null;
        }

        $tenant->loadMissing('modules');
        $module = $tenant->modules->first(function (TenantModule $module) use ($moduleKey) {
            return $module->module_key === $moduleKey;
        });

        return $module?->limit_value;
    }

    private function packageLimit(TenantAccount $tenant, string $key): ?int
    {
        $packageKey = trim((string) ($tenant->package_key ?? ''));

        if ($packageKey === '') {
            return null;
        }

        $package = $this->packageCatalogService->getPackageByKey($packageKey);

        if (!$package) {
            return null;
        }

        $limit = $this->packageCatalogService->getLimit($package, $key);

        if ($limit === null || ($limit['is_unlimited'] ?? false)) {
            return null;
        }

        return isset($limit['limit_value']) ? (int) $limit['limit_value'] : null;
    }

    private function label(string $key): string
    {
        return match ($key) {
            'users' => 'Kullanicilar',
            'current_accounts' => 'Cari Kartlar',
            'companies' => 'Firmalar',
            'products' => 'Urunler',
            'supplier_feeds' => 'Supplier Feed Erisimleri',
            'orders' => 'Siparisler',
            'storage_mb' => 'Depolama',
            'custom_domains' => 'Ozel Domainler',
            default => $key,
        };
    }
}
