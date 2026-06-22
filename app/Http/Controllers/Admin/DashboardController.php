<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardWorkQueueDataBuilder;
use App\Services\TenantResolver;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected AdminDashboardWorkQueueDataBuilder $dashboardDataBuilder,
    ) {}

    /**
     * Show the tenant admin dashboard.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($tenant, 403);

        $dashboard = $this->dashboardDataBuilder->build($tenant, $request->user());

        return view('admin.dashboard', [
            'tenant' => $tenant,
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * Show orders page.
     */
    public function orders(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        return view('admin.orders', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Show products page.
     */
    public function products(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        return view('admin.products', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Show companies page.
     */
    public function companies(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        return view('admin.companies', [
            'tenant' => $tenant,
        ]);
    }
}
