<?php

namespace App\Http\Middleware;

use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantAccessService $tenantAccessService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $normalizedModuleKey = $this->tenantAccessService->normalizeModuleKey($moduleKey);
        
        if (!$tenant) {
            return $next($request);
        }

        if (!$this->tenantAccessService->canAccessModule($tenant, $normalizedModuleKey)) {
            return response()->view('errors.module-disabled', [
                'moduleKey' => $normalizedModuleKey,
                'tenant' => $tenant,
                'message' => 'Bu modül tenant paketinizde aktif değil.',
            ], 403);
        }
        
        return $next($request);
    }
}
