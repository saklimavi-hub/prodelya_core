<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUserBelongsToTenant
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (!$user->belongsToTenant($tenant)) {
            return response()->view('errors.permission-denied', [
                'tenant' => $tenant,
                'message' => 'Bu tenant paneline erişim yetkiniz yok.',
            ], 403);
        }

        return $next($request);
    }
}
