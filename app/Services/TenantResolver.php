<?php

namespace App\Services;

use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantResolver
{
    private const LOCAL_CUSTOMER_PORTAL_TENANT_SESSION_KEY = 'local_customer_portal_tenant_id';

    /**
     * Central domains where super admin area works
     */
    protected array $centralDomains = [
        'prodelya.test',
        'app.prodelya.test',
        'prodelya_core.test',
        'localhost',
        '127.0.0.1',
    ];

    /**
     * Resolve tenant from request
     */
    public function resolve(Request $request): ?TenantAccount
    {
        $host = $request->getHost();
        $path = $request->path();
        
        // Check if this is a central domain
        if ($this->isCentralDomain($host)) {
            if (app()->environment(['local', 'testing'])) {
                $portalTenant = $this->resolveLocalCustomerPortalTenant($request, $path);

                if ($portalTenant) {
                    return $portalTenant;
                }
            }

            // Local development fallback for tenant admin routes
            // Bu fallback yalnız local geliştirme içindir; production'da kapalıdır.
            if (app()->environment(['local', 'testing']) && 
                $this->isTenantAdminRoute($path) && 
                !$this->isSuperAdminRoute($path)) {
                $userTenant = $this->resolveAuthenticatedWebUserTenant();

                if ($userTenant) {
                    return $userTenant;
                }

                $webUser = Auth::guard('web')->user();

                if ($webUser?->isPlatformAdmin()) {
                    return null;
                }
            }
            
            return null; // Super admin area
        }

        $domainTenant = $this->resolveByDomain($host);

        if ($domainTenant) {
            return $domainTenant;
        }

        // Try to resolve by subdomain
        $subdomain = $this->extractSubdomain($host);
        
        if ($subdomain) {
            return TenantAccount::where('panel_subdomain', $subdomain)
                ->where('status', 'active')
                ->first();
        }

        return null;
    }

    /**
     * Check if host is a central domain
     */
    protected function isCentralDomain(string $host): bool
    {
        return in_array($host, $this->centralDomains);
    }

    /**
     * Extract subdomain from host
     */
    protected function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        
        if (count($parts) >= 3) {
            return $parts[0];
        }
        
        return null;
    }

    protected function resolveByDomain(string $host): ?TenantAccount
    {
        return TenantAccount::query()
            ->where('status', 'active')
            ->where(function ($query) use ($host) {
                $query->where('custom_domain', $host)
                    ->orWhere('portal_domain', $host);
            })
            ->first();
    }

    /**
     * Check if current request is for central admin
     */
    public function isCentralAdmin(Request $request): bool
    {
        return $this->isCentralDomain($request->getHost());
    }

    /**
     * Get current tenant from request
     */
    public function getCurrentTenant(Request $request): ?TenantAccount
    {
        return $request->get('current_tenant');
    }

    /**
     * Set current tenant on request
     */
    public function setCurrentTenant(Request $request, ?TenantAccount $tenant): void
    {
        $request->attributes->set('current_tenant', $tenant);
    }

    /**
     * Check if path is a tenant admin route
     * Bu fallback yalnız local geliştirme içindir; production'da kapalıdır.
     */
    protected function isTenantAdminRoute(string $path): bool
    {
        return str_starts_with($path, 'admin/') && !str_starts_with($path, 'admin/super-admin');
    }

    /**
     * Check if path is a super admin route
     */
    protected function isSuperAdminRoute(string $path): bool
    {
        return str_starts_with($path, 'admin/super-admin');
    }

    protected function resolveLocalCustomerPortalTenant(Request $request, string $path): ?TenantAccount
    {
        if (! $this->isCustomerPortalRoute($path)) {
            return null;
        }

        $tenant = $this->resolveLocalCustomerPortalTenantFromAuthenticatedUser()
            ?? $this->resolveLocalCustomerPortalTenantFromSession($request)
            ?? $this->resolveLocalCustomerPortalTenantFromToken($request);

        if ($tenant) {
            $this->rememberLocalCustomerPortalTenant($request, $tenant);
        }

        return $tenant;
    }

    protected function isCustomerPortalRoute(string $path): bool
    {
        foreach (['musteri-giris', 'musteri-sifre-sifirla', 'musteri-cikis', 'musteri-portal'] as $exactOrPrefix) {
            if ($path === $exactOrPrefix || str_starts_with($path, $exactOrPrefix . '/')) {
                return true;
            }
        }

        foreach (['musteri-davet/', 'musteri-sifre-yenile/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveLocalCustomerPortalTenantFromAuthenticatedUser(): ?TenantAccount
    {
        /** @var CustomerPortalUser|null $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();

        if (! $portalUser instanceof CustomerPortalUser) {
            return null;
        }

        return TenantAccount::query()
            ->whereKey($portalUser->tenant_account_id)
            ->where('status', 'active')
            ->first();
    }

    protected function resolveLocalCustomerPortalTenantFromSession(Request $request): ?TenantAccount
    {
        if (! $request->hasSession()) {
            return null;
        }

        $tenantId = (int) $request->session()->get(self::LOCAL_CUSTOMER_PORTAL_TENANT_SESSION_KEY);

        if ($tenantId <= 0) {
            return null;
        }

        return TenantAccount::query()
            ->whereKey($tenantId)
            ->where('status', 'active')
            ->first();
    }

    protected function resolveLocalCustomerPortalTenantFromToken(Request $request): ?TenantAccount
    {
        $token = (string) $request->route('token');

        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $path = $request->path();

        $user = CustomerPortalUser::query()
            ->with('tenant')
            ->where(function ($query) use ($path, $hash) {
                if (str_starts_with($path, 'musteri-davet/')) {
                    $query->where('invite_token', $hash);

                    return;
                }

                if (str_starts_with($path, 'musteri-sifre-yenile/')) {
                    $query->where('password_reset_token', $hash);
                }
            })
            ->first();

        $tenant = $user?->tenant;

        if (! $tenant instanceof TenantAccount || $tenant->status !== 'active') {
            return null;
        }

        return $tenant;
    }

    protected function rememberLocalCustomerPortalTenant(Request $request, TenantAccount $tenant): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::LOCAL_CUSTOMER_PORTAL_TENANT_SESSION_KEY, $tenant->id);
    }

    protected function resolveAuthenticatedWebUserTenant(): ?TenantAccount
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return null;
        }

        $assignment = $user->activeTenantRoles()->first();

        if ($assignment?->tenant instanceof TenantAccount) {
            return $assignment->tenant;
        }

        return $user->preferredTenant();
    }
}
