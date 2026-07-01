<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PackageCatalogService;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function __construct(
        protected ModuleFeatureCatalogService $catalogService,
        protected PackageCatalogService $packageCatalogService,
    ) {
    }

    public function index(): View
    {
        $packages = Package::query()
            ->with(['modules', 'features'])
            ->whereNotIn('status', ['archived'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $modules = collect($this->catalogService->moduleOptionsForAdmin())
            ->map(function (array $module) use ($packages): array {
                $packageCount = $packages->filter(
                    fn (Package $package) => $this->packageCatalogService->hasModule($package, $module['key'])
                )->count();

                return $module + [
                    'package_count' => $packageCount,
                    'override_allowed' => !$module['is_core'] && in_array($module['status'], ['active', 'core'], true),
                    'menu_effect' => $module['is_core']
                        ? 'Temel tenant menüsü için zorunlu'
                        : (in_array($module['status'], ['planned', 'passive'], true)
                            ? 'Menü ve route erişimine kapalı'
                            : 'Paket veya tenant override ile görünür'),
                ];
            });

        return view('super-admin.modules.index', [
            'moduleGroups' => [
                'core' => $modules->where('is_core', true)->values(),
                'operations' => $modules->where('category', 'operations')->where('is_core', false)->whereIn('status', ['active', 'core'])->values(),
                'product_data' => $modules->where('category', 'product_data')->whereIn('status', ['active', 'core'])->values(),
                'optional' => $modules->whereNotIn('category', ['operations', 'product_data'])->where('is_core', false)->where('status', 'active')->values(),
                'planned_passive' => $modules->whereIn('status', ['planned', 'passive'])->where('is_core', false)->values(),
            ],
            'featureGroups' => $this->featureGroups(),
            'stats' => [
                'core' => $modules->where('is_core', true)->count(),
                'active_optional' => $modules->where('is_core', false)->where('status', 'active')->count(),
                'planned_passive' => $modules->whereIn('status', ['planned', 'passive'])->count(),
            ],
        ]);
    }

    public function settings(): View
    {
        $packages = Package::query()
            ->with(['modules', 'features', 'limits'])
            ->whereNotIn('status', ['archived'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('super-admin.modules.settings', [
            'decisionRules' => [
                [
                    'title' => 'Core modüller',
                    'description' => 'Core modüller aktif ve deneme tenantlarda varsayılan açık kalır. Tenant override ile kapatılamaz.',
                    'badge' => 'Zorunlu',
                    'tone' => 'green',
                ],
                [
                    'title' => 'Opsiyonel modüller',
                    'description' => 'Paket erişimi ile açılır, tenant override ile açık/kapalı hale gelebilir.',
                    'badge' => 'Paket / Override',
                    'tone' => 'blue',
                ],
                [
                    'title' => 'Planned / passive modüller',
                    'description' => 'UI’da görünür ama erişime kapalıdır. Menü veya route erişimi üretmez.',
                    'badge' => 'Kapalı',
                    'tone' => 'amber',
                ],
                [
                    'title' => 'Lifecycle kısıtı',
                    'description' => 'Suspended, passive veya expired durumları erişim kararlarını sınırlar. Active ve trial tam erişim için kullanılır.',
                    'badge' => 'Guard',
                    'tone' => 'red',
                ],
            ],
            'packageMatrix' => $packages->map(function (Package $package): array {
                return [
                    'name' => $package->name,
                    'key' => $package->key,
                    'status' => $package->safeStatusLabel(),
                    'module_count' => count($this->packageCatalogService->packageModules($package)),
                    'feature_count' => count($this->packageCatalogService->packageFeatures($package)),
                    'limit_count' => count($this->packageCatalogService->packageLimits($package)),
                ];
            })->all(),
        ]);
    }

    private function featureGroups(): array
    {
        $flat = collect();

        foreach ($this->catalogService->features() as $moduleKey => $features) {
            $module = $this->catalogService->getModule($moduleKey);

            foreach ($features as $feature) {
                $flat->push([
                    'key' => $feature['key'],
                    'label' => $feature['label'] ?? $feature['key'],
                    'status' => $feature['status'] ?? 'passive',
                    'module_label' => $module['label'] ?? $moduleKey,
                    'module_key' => $moduleKey,
                    'default_enabled' => (bool) ($feature['default_enabled'] ?? false),
                ]);
            }
        }

        return [
            'customer' => $flat->filter(fn (array $row) => str_contains($row['module_key'], 'portal') || str_contains($row['key'], 'approval'))->values(),
            'integrations' => $flat->filter(fn (array $row) => str_contains($row['key'], 'xml') || str_contains($row['key'], 'api') || str_contains($row['key'], 'domain'))->values(),
            'reporting' => $flat->filter(fn (array $row) => str_contains($row['key'], 'report') || str_contains($row['key'], 'whatsapp') || str_contains($row['key'], 'email'))->values(),
        ];
    }
}
