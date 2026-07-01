<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantSupplierAccessController extends Controller
{
    public function __construct()
    {
        // Route group auth:web + central.access + super.admin middleware katmanlariyla korunur.
    }

    public function index(): View
    {
        $tenants = TenantAccount::query()
            ->with([
                'modules' => fn ($query) => $query->whereIn('module_key', $this->moduleKeys()),
                'supplierAccesses' => fn ($query) => $query->with('supplier')->orderBy('supplier_id'),
            ])
            ->orderBy('name')
            ->get();

        return view('super-admin.tenant-supplier-access.index', [
            'tenants' => $tenants,
            'moduleLabels' => $this->moduleLabels(),
        ]);
    }

    public function edit(TenantAccount $tenant): View
    {
        $tenant->load([
            'modules' => fn ($query) => $query->whereIn('module_key', $this->moduleKeys()),
            'supplierAccesses' => fn ($query) => $query->with('supplier')->orderBy('supplier_id'),
        ]);

        $suppliers = Supplier::query()
            ->active()
            ->with(['sources' => fn ($query) => $query->visibleInProductDataHub()->orderBy('source_name')])
            ->whereHas('sources', fn ($query) => $query->visibleInProductDataHub())
            ->orderBy('name')
            ->get();

        $moduleStates = $this->buildModuleStates($tenant);
        $accessBySupplier = $tenant->supplierAccesses->keyBy('supplier_id');

        return view('super-admin.tenant-supplier-access.edit', [
            'tenant' => $tenant,
            'suppliers' => $suppliers,
            'moduleStates' => $moduleStates,
            'accessBySupplier' => $accessBySupplier,
        ]);
    }

    public function update(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'modules.product_data_hub.enabled' => 'nullable|boolean',
            'modules.advanced_catalog.enabled' => 'nullable|boolean',
            'modules.supplier_feed.enabled' => 'nullable|boolean',
            'modules.supplier_feed.limit_value' => 'nullable|integer|min:0',
            'modules.export_web_feed.enabled' => 'nullable|boolean',
            'supplier_access' => 'nullable|array',
            'supplier_access.*.is_enabled' => 'nullable|boolean',
            'supplier_access.*.can_view_products' => 'nullable|boolean',
            'supplier_access.*.can_request_purchase' => 'nullable|boolean',
            'supplier_access.*.can_use_in_quotes' => 'nullable|boolean',
            'supplier_access.*.price_multiplier' => 'nullable|numeric|min:0',
            'supplier_access.*.safe_stock_quantity' => 'nullable|integer|min:0',
            'supplier_access.*.visible_in_catalog' => 'nullable|boolean',
            'supplier_access.*.export_allowed' => 'nullable|boolean',
        ]);

        $supplierIds = Supplier::query()->pluck('id')->all();

        DB::transaction(function () use ($tenant, $validated, $supplierIds) {
            $modulePayload = $validated['modules'] ?? [];

            foreach ($this->moduleKeys() as $moduleKey) {
                $payload = $modulePayload[$moduleKey] ?? [];

                TenantModule::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'module_key' => $moduleKey,
                        'feature_key' => null,
                    ],
                    [
                        'is_enabled' => (bool) ($payload['enabled'] ?? false),
                        'limit_value' => $moduleKey === 'supplier_feed' ? ($payload['limit_value'] ?? null) : null,
                        'meta' => [
                            'updated_via' => 'super_admin_tenant_supplier_access',
                            // TODO: align with manage_product_data_hub / view_advanced_catalog permission policy
                        ],
                    ]
                );
            }

            $rows = $validated['supplier_access'] ?? [];

            foreach ($rows as $supplierId => $row) {
                if (!in_array((int) $supplierId, $supplierIds, true)) {
                    continue;
                }

                TenantSupplierAccess::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'supplier_id' => (int) $supplierId,
                    ],
                    [
                        'is_active' => (bool) ($row['is_enabled'] ?? false),
                        'granted_at' => ($row['is_enabled'] ?? false) ? now() : null,
                        'can_view_products' => (bool) ($row['can_view_products'] ?? false),
                        'can_request_purchase' => (bool) ($row['can_request_purchase'] ?? false),
                        'can_use_in_quotes' => (bool) ($row['can_use_in_quotes'] ?? false),
                        'price_multiplier' => $row['price_multiplier'] ?? null,
                        'safe_stock_quantity' => $row['safe_stock_quantity'] ?? null,
                        'visible_in_catalog' => (bool) ($row['visible_in_catalog'] ?? false),
                        'export_allowed' => (bool) ($row['export_allowed'] ?? false),
                        'access_settings' => [
                            'supplier_feed_enabled' => (bool) ($modulePayload['supplier_feed']['enabled'] ?? false),
                        ],
                        'meta' => [
                            'updated_via' => 'super_admin_tenant_supplier_access',
                            // TODO: attach manage_tenant_supplier_access audit metadata
                        ],
                    ]
                );
            }
        });

        return redirect()
            ->route('admin.super.tenant-supplier-access.edit', $tenant)
            ->with('success', 'Tenant tedarikçi erişim ayarları güncellendi.');
    }

    private function moduleKeys(): array
    {
        return [
            'product_data_hub',
            'advanced_catalog',
            'supplier_feed',
            'export_web_feed',
        ];
    }

    private function moduleLabels(): array
    {
        return [
            'product_data_hub' => 'Product Data Hub',
            'advanced_catalog' => 'Advanced Catalog',
            'supplier_feed' => 'Supplier Feed',
            'export_web_feed' => 'Export / Web Feed',
        ];
    }

    private function buildModuleStates(TenantAccount $tenant): array
    {
        $states = [];
        $modules = $tenant->modules->keyBy('module_key');

        foreach ($this->moduleKeys() as $moduleKey) {
            $module = $modules->get($moduleKey);
            $states[$moduleKey] = [
                'enabled' => (bool) ($module?->is_enabled ?? false),
                'limit_value' => $module?->limit_value,
                'label' => $this->moduleLabels()[$moduleKey],
            ];
        }

        return $states;
    }
}
