<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;

class CustomerPortalAccessService
{
    public function __construct(
        protected TenantAccessService $tenantAccessService
    ) {
    }

    public function portalLoginEnabled(TenantAccount $tenant): bool
    {
        return $this->tenantAccessService->canAccessModule($tenant, 'customer_portal')
            && $this->tenantAccessService->canAccessFeature($tenant, 'customer_login', 'customer_portal');
    }

    public function portalQuotesEnabled(TenantAccount $tenant): bool
    {
        return $this->portalLoginEnabled($tenant)
            && $this->tenantAccessService->canAccessFeature($tenant, 'portal_quotes', 'customer_portal');
    }

    public function portalOrdersEnabled(TenantAccount $tenant): bool
    {
        return $this->portalLoginEnabled($tenant)
            && $this->tenantAccessService->canAccessFeature($tenant, 'portal_orders', 'customer_portal');
    }

    public function portalVisibleFilesEnabled(TenantAccount $tenant): bool
    {
        return $this->portalLoginEnabled($tenant)
            && $this->tenantAccessService->canAccessFeature($tenant, 'customer_visible_files', 'graphics');
    }

    public function canAccessPortal(TenantAccount $tenant, CustomerPortalUser $user): bool
    {
        if (! $this->portalLoginEnabled($tenant)) {
            return false;
        }

        if (! $user->belongsToTenant($tenant)) {
            return false;
        }

        if (! $user->isActive()) {
            return false;
        }

        return (bool) $user->company?->portal_enabled;
    }

    public function canAccessCompany(TenantAccount $tenant, CustomerPortalUser $user, Company $company): bool
    {
        return $this->canAccessPortal($tenant, $user)
            && $user->canSeeCompany($company);
    }
}
