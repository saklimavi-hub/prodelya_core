<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantResolver->resolve($request);
        
        if ($tenant) {
            $this->tenantResolver->setCurrentTenant($request, $tenant);
            // Share current tenant with all views
            view()->share('currentTenant', $tenant);
        } else {
            // Share null for central admin areas
            view()->share('currentTenant', null);
        }
        
        return $next($request);
    }
}
