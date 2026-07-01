<?php

namespace App\Services\Tenant;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantSubscriptionStatusService;
use App\Services\TenantUsageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class TenantUpgradeRequestFormOptionsService
{
    public function __construct(
        protected PackageCatalogService $packageCatalogService,
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
        protected TenantUpgradeRequestService $upgradeRequestService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(TenantAccount $tenant, ?string $selectedType = null): array
    {
        $selectedType = $this->normalizeSelectedType($selectedType);
        $requests = $this->upgradeRequestService->listForTenant($tenant);
        $openRequests = $requests->filter(fn (TenantUpgradeRequest $request) => $request->isOpen());
        $usageSnapshot = array_values($this->tenantUsageService->getUsageSnapshot($tenant));

        return [
            'selected_type' => $selectedType,
            'tenant_summary' => [
                'package_name' => $tenant->package?->name ?? ($tenant->package_key ?: 'Tanımlı değil'),
                'package_key' => $tenant->package_key,
                'subscription' => $this->subscriptionStatusService->getStatus($tenant),
                'usage_warning_count' => count($this->tenantUsageService->warningItems($tenant)),
                'open_request_count' => $openRequests->count(),
                'latest_request' => $requests->first(),
            ],
            'request_type_options' => collect(TenantUpgradeRequest::requestTypeOptions())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'form_options' => [
                TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE => $this->packageOptions($tenant),
                TenantUpgradeRequest::TYPE_MODULE_ADDON => $this->moduleOptions($tenant),
                TenantUpgradeRequest::TYPE_FEATURE_ADDON => $this->featureOptions($tenant),
                TenantUpgradeRequest::TYPE_LIMIT_INCREASE => $this->limitOptions($tenant, $usageSnapshot),
                TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS => $this->supplierOptions($tenant),
                TenantUpgradeRequest::TYPE_SERVICE_REQUEST => $this->serviceOptions(),
            ],
            'requests' => $requests,
            'status_labels' => TenantUpgradeRequest::statusOptions(),
            'old_package_request_route' => Route::has('admin.package-requests.index') ? route('admin.package-requests.index') : null,
        ];
    }

    private function normalizeSelectedType(?string $selectedType): string
    {
        return array_key_exists((string) $selectedType, TenantUpgradeRequest::requestTypeOptions())
            ? (string) $selectedType
            : TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE;
    }

    /**
     * @return array<string, mixed>
     */
    private function packageOptions(TenantAccount $tenant): array
    {
        $openTargets = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])
            ->pluck('requested_package_key')
            ->filter()
            ->all();

        return [
            'current_package' => $tenant->package?->name ?? $tenant->package_key,
            'packages' => $this->packageCatalogService->activePackages()
                ->filter(fn ($package) => $package->key !== $tenant->package_key && !in_array($package->key, $openTargets, true))
                ->map(fn ($package) => [
                    'value' => $package->key,
                    'label' => $package->name,
                    'description' => $package->formattedPrice('monthly') ?: 'Fiyat tanımlı değil',
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleOptions(TenantAccount $tenant): array
    {
        $openTargets = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', TenantUpgradeRequest::TYPE_MODULE_ADDON)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])
            ->pluck('requested_module_key')
            ->filter()
            ->all();

        $items = [];

        foreach ($this->catalogService->modules() as $key => $module) {
            if (($module['is_core'] ?? false) || in_array($module['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                continue;
            }

            if ($this->tenantAccessService->canAccessModule($tenant, $key)) {
                continue;
            }

            if (in_array($key, $openTargets, true)) {
                continue;
            }

            $items[] = [
                'value' => $key,
                'label' => $module['label'] ?? Str::headline($key),
                'description' => $module['description'] ?? 'Ek modül erişimi talep edebilirsiniz.',
            ];
        }

        return ['modules' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureOptions(TenantAccount $tenant): array
    {
        $openTargets = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', TenantUpgradeRequest::TYPE_FEATURE_ADDON)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])
            ->pluck('requested_feature_key')
            ->filter()
            ->all();

        $items = [];

        foreach ($this->catalogService->features() as $moduleKey => $features) {
            $module = $this->catalogService->getModule($moduleKey);

            if (!$module || in_array($module['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                continue;
            }

            foreach ($features as $featureKey => $feature) {
                if (in_array($feature['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                    continue;
                }

                if ($this->tenantAccessService->canAccessFeature($tenant, $featureKey, $moduleKey)) {
                    continue;
                }

                if (in_array($featureKey, $openTargets, true)) {
                    continue;
                }
                $items[] = [
                    'value' => $featureKey,
                    'label' => $feature['label'] ?? Str::headline($featureKey),
                    'module_label' => $module['label'] ?? Str::headline($moduleKey),
                    'description' => $feature['description'] ?? 'Ek özellik talebi oluşturabilirsiniz.',
                ];
            }
        }

        return ['features' => $items];
    }

    /**
     * @param  array<int, array<string, mixed>>  $usageSnapshot
     * @return array<string, mixed>
     */
    private function limitOptions(TenantAccount $tenant, array $usageSnapshot): array
    {
        $openTargets = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', TenantUpgradeRequest::TYPE_LIMIT_INCREASE)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])
            ->pluck('requested_limit_key')
            ->filter()
            ->all();

        $items = [];

        foreach ($usageSnapshot as $item) {
            $key = (string) ($item['key'] ?? '');

            if ($key === '' || ($item['limit'] ?? null) === null || in_array($key, $openTargets, true)) {
                continue;
            }

            $items[] = [
                'value' => $key,
                'label' => $item['label'] ?? Str::headline($key),
                'current_limit' => (int) $item['limit'],
                'current_usage' => (int) ($item['current'] ?? 0),
                'status' => (string) ($item['status'] ?? 'ok'),
            ];
        }

        return ['limits' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierOptions(TenantAccount $tenant): array
    {
        $activeSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->pluck('supplier_id')
            ->all();

        $openTargets = TenantUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('request_type', TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS)
            ->whereIn('status', [
                TenantUpgradeRequest::STATUS_PENDING,
                TenantUpgradeRequest::STATUS_IN_REVIEW,
                TenantUpgradeRequest::STATUS_APPROVED,
            ])
            ->pluck('requested_supplier_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'suppliers' => Supplier::query()
                ->where('status', 'active')
                ->whereNotIn('id', $activeSupplierIds)
                ->whereNotIn('id', $openTargets)
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $supplier) => [
                    'value' => $supplier->id,
                    'label' => $supplier->name,
                    'code' => $supplier->code,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceOptions(): array
    {
        return [
            'services' => [
                ['value' => 'xml_export_setup', 'label' => 'XML / Feed Hazırlığı'],
                ['value' => 'advanced_reporting', 'label' => 'Gelişmiş Raporlama'],
                ['value' => 'supplier_feed_consulting', 'label' => 'Tedarikçi Feed Danışmanlığı'],
                ['value' => 'catalog_growth_support', 'label' => 'Katalog Büyütme Desteği'],
                ['value' => 'custom_integration', 'label' => 'Özel Entegrasyon'],
            ],
        ];
    }
}
