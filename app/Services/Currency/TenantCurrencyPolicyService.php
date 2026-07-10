<?php

namespace App\Services\Currency;

use App\Models\TenantAccount;

class TenantCurrencyPolicyService
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
    ) {
    }

    public function resolveBaseCurrency(?TenantAccount $tenant): string
    {
        $raw = $tenant?->default_currency;

        if ($raw === null || trim((string) $raw) === '') {
            return 'TRY';
        }

        return $this->normalizer->normalize($raw);
    }
}
