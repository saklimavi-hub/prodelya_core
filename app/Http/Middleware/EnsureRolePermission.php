<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRolePermission
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            return redirect()->route('login');
        }
        
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        if (!$tenant) {
            if ($user->isPlatformAdmin()) {
                return $next($request);
            }
            
            return response()->view('errors.permission-denied', [], 403);
        }
        
        if (!$user->hasPermissionInTenant($permission, $tenant->id)) {
            // Log permission violation
            \App\Models\AuditLog::logPermissionViolation(
                $tenant->id,
                $user->id,
                $permission,
                get_class($request->route()->getControllerClass()),
                $request->route()->getActionMethod()
            );
            
            return response()->view('errors.permission-denied', [
                'permission' => $permission,
                'tenant' => $tenant
            ], 403);
        }
        
        return $next($request);
    }
}
