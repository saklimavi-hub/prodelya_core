<?php

namespace App\Http\Middleware;

use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantAccessService $tenantAccessService
    ) {
    }

    public function handle(Request $request, Closure $next, string $firstKey, ?string $secondKey = null): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant) {
            return $next($request);
        }

        $moduleKey = $secondKey !== null ? $firstKey : null;
        $featureKey = $secondKey !== null ? $secondKey : $firstKey;

        $normalizedModuleKey = $moduleKey ? $this->tenantAccessService->normalizeModuleKey($moduleKey) : null;
        $normalizedFeatureKey = $this->tenantAccessService->normalizeFeatureKey($featureKey);

        if (!$this->tenantAccessService->canAccessFeature($tenant, $normalizedFeatureKey, $normalizedModuleKey)) {
            return response()->view('errors.feature-disabled', [
                'featureKey' => $normalizedFeatureKey,
                'moduleKey' => $normalizedModuleKey,
                'tenant' => $tenant,
                'message' => 'Bu özellik tenant paketinizde aktif değil.',
            ], 403);
        }

        return $next($request);
    }
}
