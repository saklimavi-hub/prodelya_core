<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class AdminMenuService
{
    public function __construct(
        protected TenantAccessService $tenantAccessService,
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantResolver $tenantResolver
    ) {
    }

    public function tenantMenu(?TenantAccount $tenant, User $user): array
    {
        return $this->filterItems(config('admin_menu.tenant', []), $tenant, $user);
    }

    public function superAdminMenu(User $user): array
    {
        return $this->filterItems(config('admin_menu.super_admin', []), null, $user);
    }

    public function visibleMenuFor(User $user, ?TenantAccount $tenant = null): array
    {
        $request = request();
        $isSuperAdminContext = $request instanceof Request
            && $this->tenantResolver->isCentralAdmin($request)
            && $request->routeIs('admin.super.*');

        return [
            'context' => $isSuperAdminContext ? 'super_admin' : 'tenant',
            'items' => $isSuperAdminContext
                ? $this->superAdminMenu($user)
                : $this->tenantMenu($tenant, $user),
        ];
    }

    public function filterItems(array $items, ?TenantAccount $tenant, User $user): array
    {
        usort($items, fn (array $left, array $right) => (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0));

        $visible = [];

        foreach ($items as $item) {
            $filtered = $this->filterItem($item, $tenant, $user);

            if ($filtered !== null) {
                $visible[] = $filtered;
            }
        }

        return $visible;
    }

    public function isItemVisible(array $item, ?TenantAccount $tenant, User $user): bool
    {
        return $this->filterItem($item, $tenant, $user) !== null;
    }

    private function filterItem(array $item, ?TenantAccount $tenant, User $user): ?array
    {
        $status = $item['status'] ?? 'active';

        if (in_array($status, ['planned', 'passive', 'deprecated'], true)) {
            return null;
        }

        $type = $item['type'] ?? 'link';

        if ($type === 'group' || $type === 'accordion') {
            $children = $this->filterChildren($item['children'] ?? [], $tenant, $user);

            if ($children === []) {
                return null;
            }

            $item['children'] = $children;
            $item['active'] = $this->childrenContainActive($children);

            return $item;
        }

        if ($type === 'heading') {
            return $item;
        }

        if (!$this->passesRouteVisibility($item)) {
            return null;
        }

        if (!$this->passesAccessVisibility($item, $tenant, $user)) {
            return null;
        }

        $item['href'] = $this->resolveHref($item);
        $item['active'] = $this->isRouteActive($item);

        return $item;
    }

    private function filterChildren(array $children, ?TenantAccount $tenant, User $user): array
    {
        usort($children, fn (array $left, array $right) => (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0));

        $visible = [];
        $bufferedHeading = null;

        foreach ($children as $child) {
            $filteredChild = $this->filterItem($child, $tenant, $user);

            if ($filteredChild === null) {
                continue;
            }

            if (($filteredChild['type'] ?? 'link') === 'heading') {
                $bufferedHeading = $filteredChild;
                continue;
            }

            if ($bufferedHeading !== null) {
                $visible[] = $bufferedHeading;
                $bufferedHeading = null;
            }

            $visible[] = $filteredChild;
        }

        return $visible;
    }

    private function passesRouteVisibility(array $item): bool
    {
        if (empty($item['route'])) {
            return !empty($item['url']);
        }

        return Route::has($item['route']);
    }

    private function passesAccessVisibility(array $item, ?TenantAccount $tenant, User $user): bool
    {
        if (!empty($item['permission'])) {
            if (!$tenant) {
                return false;
            }

            if (!$user->hasPermissionInTenant($item['permission'], $tenant->id)) {
                return false;
            }
        }

        if (!empty($item['module_key'])) {
            if (!$tenant || !$this->tenantAccessService->canAccessModule($tenant, $item['module_key'])) {
                return false;
            }
        }

        if (!empty($item['feature_key'])) {
            if (!$tenant || !$this->tenantAccessService->canAccessFeature($tenant, $item['feature_key'], $item['module_key'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function resolveHref(array $item): string
    {
        if (!empty($item['route']) && Route::has($item['route'])) {
            return route($item['route'], $item['route_params'] ?? []);
        }

        return (string) ($item['url'] ?? '#');
    }

    private function isRouteActive(array $item): bool
    {
        if (array_key_exists('active_patterns', $item)) {
            $patterns = is_array($item['active_patterns']) ? $item['active_patterns'] : [];

            foreach ($patterns as $pattern) {
                if (request()->routeIs($pattern)) {
                    return true;
                }
            }

            return false;
        }

        if (!empty($item['active'])) {
            return request()->routeIs($item['active']);
        }

        if (!empty($item['route'])) {
            return request()->routeIs($item['route']);
        }

        return false;
    }

    private function childrenContainActive(array $children): bool
    {
        foreach ($children as $child) {
            if (($child['active'] ?? false) === true) {
                return true;
            }

            if (!empty($child['children']) && $this->childrenContainActive($child['children'])) {
                return true;
            }
        }

        return false;
    }
}
