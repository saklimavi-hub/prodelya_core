<?php

namespace App\Services\PromotionQuote;

use App\Models\TenantAccount;
use App\Services\TenantAccessService;
use App\Services\Currency\TenantCurrencySettingsService;
use Illuminate\Contracts\Auth\Authenticatable;

class QuoteCurrencyAccessService
{
    public function __construct(
        private readonly TenantAccessService $tenantAccessService,
        private readonly TenantCurrencySettingsService $currencySettingsService,
    ) {
    }

    public function build(TenantAccount $tenant, ?Authenticatable $user): array
    {
        $multiCurrencyEnabled = $this->tenantAccessService->canAccessModule($tenant, 'multi_currency');
        $canViewCurrencyDetails = $multiCurrencyEnabled && (bool) ($user?->canViewFinancialData($tenant->id) ?? false);

        $currencySettings = $this->currencySettingsService->effectiveSettings($tenant);

        return [
            'multi_currency_enabled' => $multiCurrencyEnabled,
            'can_view_currency_details' => $canViewCurrencyDetails,
            'can_use_foreign_document_currency' => $multiCurrencyEnabled,
            'can_refresh_rates' => $canViewCurrencyDetails,
            'can_acknowledge_current_rates' => $canViewCurrencyDetails,
            'can_use_manual_rate' => false,
            'enabled_quote_currencies' => $currencySettings['enabled_quote_currencies'] ?? ['TRY'],
            'default_quote_currency' => $currencySettings['default_quote_currency'] ?? 'TRY',
            'base_currency' => $currencySettings['base_currency'] ?? 'TRY',
        ];
    }
}
