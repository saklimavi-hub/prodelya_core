<?php

namespace App\Providers;

use App\Services\AdminMenuService;
use App\Services\ProductDataHub\NullProductImageAnalyzer;
use App\Services\ProductDataHub\ProductImageAnalyzerInterface;
use App\Services\TenantResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->applyLocalEnvironmentSafetyOverrides();
        $this->app->bind(ProductImageAnalyzerInterface::class, NullProductImageAnalyzer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.prodelya-admin', function ($view): void {
            $user = Auth::guard('web')->user();
            $request = request();

            if (!$user || !$request) {
                $view->with([
                    'adminMenu' => ['context' => 'tenant', 'items' => []],
                    'currentTenantForLayout' => null,
                    'isSuperAdminContext' => false,
                ]);

                return;
            }

            $tenantResolver = app(TenantResolver::class);
            $tenant = $tenantResolver->getCurrentTenant($request);
            $menu = app(AdminMenuService::class)->visibleMenuFor($user, $tenant);

            $view->with([
                'adminMenu' => $menu,
                'currentTenantForLayout' => $tenant,
                'isSuperAdminContext' => $menu['context'] === 'super_admin',
            ]);
        });
    }

    protected function applyLocalEnvironmentSafetyOverrides(): void
    {
        $appEnv = strtolower(trim((string) config('app.env')));
        $dbConnection = strtolower(trim((string) config('database.default')));
        $sessionDriver = strtolower(trim((string) config('session.driver')));
        $cacheStore = strtolower(trim((string) config('cache.default')));
        $sessionDomain = trim((string) config('session.domain'));
        $localHosts = config('prodelya_domains.local_hosts', []);

        $isLocalLike = in_array($appEnv, ['local', 'development'], true);
        $isSqlite = $dbConnection === 'sqlite';

        $sessionOverridden = false;
        $cacheOverridden = false;
        $sessionDomainOverridden = false;
        $sessionCookieOverridden = false;

        if ($isLocalLike && $isSqlite && $sessionDriver === 'database') {
            config([
                'session.driver' => 'file',
                'session.connection' => null,
                'session.store' => null,
            ]);
            $sessionOverridden = true;
        }

        if ($isLocalLike && $isSqlite && $cacheStore === 'database') {
            config([
                'cache.default' => 'file',
            ]);
            $cacheOverridden = true;
        }

        if ($isLocalLike && $sessionDomain === '') {
            $sharedLocalDomain = $this->sharedLocalSessionDomain(is_array($localHosts) ? $localHosts : []);

            if ($sharedLocalDomain !== null) {
                config([
                    'session.domain' => $sharedLocalDomain,
                ]);
                $sessionDomainOverridden = true;
            }
        }

        if ($isLocalLike && ($sessionOverridden || $sessionDomainOverridden)) {
            $baseCookie = trim((string) config('session.cookie'));

            if ($baseCookie === '' || $baseCookie === Str::slug((string) config('app.name', 'laravel')).'-session') {
                config([
                    'session.cookie' => 'prodelya-local-session',
                ]);
                $sessionCookieOverridden = true;
            }
        }

        config([
            'prodelya_local.sqlite_lock_protection' => [
                'active' => $sessionOverridden || $cacheOverridden || $sessionDomainOverridden || $sessionCookieOverridden,
                'session_driver_overridden' => $sessionOverridden,
                'cache_store_overridden' => $cacheOverridden,
                'session_domain_overridden' => $sessionDomainOverridden,
                'session_cookie_overridden' => $sessionCookieOverridden,
                'original_session_driver' => $sessionDriver,
                'original_cache_store' => $cacheStore,
                'original_session_domain' => $sessionDomain,
                'effective_session_driver' => (string) config('session.driver'),
                'effective_cache_store' => (string) config('cache.default'),
                'effective_session_domain' => (string) config('session.domain'),
                'effective_session_cookie' => (string) config('session.cookie'),
            ],
        ]);
    }

    /**
     * @param array<int, mixed> $hosts
     */
    protected function sharedLocalSessionDomain(array $hosts): ?string
    {
        foreach ($hosts as $host) {
            $normalized = strtolower(trim((string) $host));

            if ($normalized === '' || in_array($normalized, ['localhost', '127.0.0.1'], true)) {
                continue;
            }

            if (str_contains($normalized, '.') && !str_starts_with($normalized, '.')) {
                return '.' . $normalized;
            }
        }

        return null;
    }
}
