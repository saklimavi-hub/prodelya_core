<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralPublicHost
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantResolver->isCentralAdmin($request)) {
            abort(404);
        }

        return $next($request);
    }
}
