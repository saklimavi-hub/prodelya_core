<?php

namespace App\Services\PromotionQuote;

use App\Models\TenantAccount;
use App\Services\TenantAccessService;
use Illuminate\Contracts\Auth\Authenticatable;

class QuoteCurrencyAccessService
{
    public function __construct(
        private readonly TenantAccessService $tenantAccessService,
    ) {
    }

    public function build(TenantAccount $tenant, ?Authenticatable $user): array
    {
        $multiCurrencyEnabled = $this->tenantAccessService->canAccessModule($tenant, 'multi_currency');
        $canViewCurrencyDetails = $multiCurrencyEnabled && (bool) ($user?->canViewFinancialData($tenant->id) ?? false);

        return [
            'multi_currency_enabled' => $multiCurrencyEnabled,
            'can_view_currency_details' => $canViewCurrencyDetails,
            'can_use_foreign_document_currency' => $multiCurrencyEnabled,
            'can_refresh_rates' => $canViewCurrencyDetails,
            'can_acknowledge_current_rates' => $canViewCurrencyDetails,
            'can_use_manual_rate' => false,
        ];
    }
}
