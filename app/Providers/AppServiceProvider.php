<?php

namespace App\Providers;

use App\Services\AdminMenuService;
use App\Services\ProductDataHub\NullProductImageAnalyzer;
use App\Services\ProductDataHub\ProductImageAnalyzerInterface;
use App\Services\TenantResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
}
