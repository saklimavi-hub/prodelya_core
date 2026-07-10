<?php

namespace Tests\Unit\Currency;

use App\Exceptions\Currency\UnsupportedCurrencyException;
use App\Models\TenantAccount;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\TenantCurrencyPolicyService;
use PHPUnit\Framework\TestCase;

class TenantCurrencyPolicyServiceTest extends TestCase
{
    public function test_it_resolves_legacy_tl_as_try(): void
    {
        $tenant = new TenantAccount(['default_currency' => 'TL']);

        $service = new TenantCurrencyPolicyService(new CurrencyCodeNormalizer());

        $this->assertSame('TRY', $service->resolveBaseCurrency($tenant));
    }

    public function test_it_defaults_null_to_try(): void
    {
        $service = new TenantCurrencyPolicyService(new CurrencyCodeNormalizer());

        $this->assertSame('TRY', $service->resolveBaseCurrency(new TenantAccount()));
    }

    public function test_it_resolves_supported_currencies(): void
    {
        $service = new TenantCurrencyPolicyService(new CurrencyCodeNormalizer());

        $this->assertSame('USD', $service->resolveBaseCurrency(new TenantAccount(['default_currency' => 'USD'])));
        $this->assertSame('EUR', $service->resolveBaseCurrency(new TenantAccount(['default_currency' => 'EUR'])));
    }

    public function test_it_rejects_unsupported_currency(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        $service = new TenantCurrencyPolicyService(new CurrencyCodeNormalizer());
        $service->resolveBaseCurrency(new TenantAccount(['default_currency' => 'GBP']));
    }
}
