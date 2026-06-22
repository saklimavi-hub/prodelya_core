<?php

namespace App\Http\Middleware;

use App\Models\CustomerPortalUser;
use App\Services\CustomerPortalAccessService;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerPortalAuthenticated
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('customer_portal');

        if (! $guard->check()) {
            return redirect()->guest(route('customer.login'));
        }

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $user = $guard->user();

        if (! $tenant || ! $user instanceof CustomerPortalUser || ! $this->portalAccessService->canAccessPortal($tenant, $user)) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('customer.login'));
        }

        return $next($request);
    }
}
