<?php

namespace Tests\Feature\Concerns;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\AdminMenuService;

trait BuildsLocalProductsMenuContext
{
    protected bool $seed = true;

    protected User $adminUser;
    protected TenantAccount $tenant;

    protected function setUpLocalProductsMenuContext(string $subdomain = 'local-products-menu-context'): void
    {
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'enterprise',
            'panel_subdomain' => $subdomain,
            'slug' => $subdomain,
        ])->save();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'advanced_catalog',
                'feature_key' => 'product_variants',
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'advanced_catalog',
                'feature_key' => 'local_stock',
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'product_data_hub',
                'feature_key' => 'tenant_catalog_projection',
            ],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Menu Route Recovery Supplier',
            'code' => strtoupper(substr($subdomain, 0, 8)),
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);
    }

    protected function getOnCentralHost(string $uri)
    {
        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get($uri);
    }

    protected function tenantMenu(): array
    {
        return app(AdminMenuService::class)->tenantMenu($this->tenant->fresh(), $this->adminUser);
    }

    protected function findMenuItemByKey(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $match = $this->findMenuItemByKey($item['children'], $key);

                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }
}
