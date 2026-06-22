<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use App\Services\TenantSubscriptionStatusService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantSubscriptionStatusService $subscriptionStatusService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        if (!$tenant) {
            return response()->view('errors.tenant-not-found', [], 404);
        }

        $subscription = $this->subscriptionStatusService->getStatus($tenant);

        if (!$this->subscriptionStatusService->canAccessAdmin($tenant)) {
            return response()->view('errors.tenant-inactive', [
                'tenant' => $tenant,
                'subscription' => $subscription,
                'message' => 'Tenant erişime kapalı durumda.',
            ], 403);
        }

        if (!$this->subscriptionStatusService->canCreateOrUpdate($tenant) && !$this->isReadOnlyRequest($request)) {
            return response()->view('errors.tenant-inactive', [
                'tenant' => $tenant,
                'subscription' => $subscription,
                'message' => 'Bu tenant için oluşturma ve güncelleme işlemleri kapalıdır.',
            ], 403);
        }
        
        return $next($request);
    }

    private function isReadOnlyRequest(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
