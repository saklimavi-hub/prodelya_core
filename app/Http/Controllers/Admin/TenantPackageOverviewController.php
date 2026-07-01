<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Tenant\TenantPackageOverviewService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantPackageOverviewController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantPackageOverviewService $overviewService,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        return view('admin.my-package.index', [
            'tenant' => $tenant,
            'overview' => $this->overviewService->build($tenant, $request->user()),
        ]);
    }
}
