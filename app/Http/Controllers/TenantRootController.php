<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Marketing\PublicSiteController;
use App\Services\CustomerPortalAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantRootController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $customerPortalAccessService,
        protected PublicSiteController $publicSiteController,
    ) {
    }

    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($this->tenantResolver->isCentralAdmin($request)) {
            return $this->publicSiteController->home();
        }

        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant) {
            abort(404);
        }

        if ($this->customerPortalAccessService->portalLoginEnabled($tenant)) {
            return redirect('/musteri-giris');
        }

        return redirect('/admin');
    }
}
