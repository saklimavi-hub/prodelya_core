<?php

namespace App\Services\ProductDataHub;

use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Exceptions\Currency\UnsupportedCurrencyException;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\TenantCurrencyPolicyService;
use App\Services\TenantAccessService;
use Illuminate\Contracts\Auth\Authenticatable;

class ProductHubCurrencyService
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly CurrencyConversionService $conversionService,
        private readonly TenantCurrencyPolicyService $tenantCurrencyPolicyService,
        private readonly TenantAccessService $tenantAccessService,
    ) {
    }

    public function buildRawCurrencyContext(
        SupplierSource $source,
        array $normalized,
        array $rawPayload = [],
        ?array $productNormalized = null,
        ?array $productRawPayload = null,
        string $scope = 'variant',
    ): array {
        $profileKey = $this->resolveProfileKey($source);
        $profile = $profileKey ? (array) config("prodelya_product_data_hub.supplier_profiles.{$profileKey}", []) : [];
        $currencyFieldCandidates = $this->resolveCurrencyFieldCandidates($profile);

        $variantCurrency = $this->firstFilledValue($rawPayload, $currencyFieldCandidates);
        $productCurrency = $this->firstFilledValue($productRawPayload ?? [], $currencyFieldCandidates);
        $profileDefaultCurrency = data_get($source->config, 'currency')
            ?? data_get($source->config, 'default_currency')
            ?? ($profile['currency'] ?? null);
        $legacyDefaultCurrency = $profileKey
            ? data_get(config('prodelya_product_data_hub.currency_contract.legacy_source_defaults', []), $profileKey)
            : null;

        $fieldOrigin = $scope === 'product' ? 'product_field' : 'variant_field';
        $rawCurrencyInput = $variantCurrency;
        $origin = $variantCurrency !== null ? $fieldOrigin : null;

        if ($rawCurrencyInput === null && $productCurrency !== null) {
            $rawCurrencyInput = $productCurrency;
            $origin = 'product_field';
        }

        if ($rawCurrencyInput === null && filled($profileDefaultCurrency)) {
            $rawCurrencyInput = (string) $profileDefaultCurrency;
            $origin = 'source_default';
        }

        if ($rawCurrencyInput === null && filled($legacyDefaultCurrency)) {
            $rawCurrencyInput = (string) $legacyDefaultCurrency;
            $origin = 'legacy_default';
        }

        if ($rawCurrencyInput === null && filled($normalized['currency'] ?? null)) {
            $rawCurrencyInput = (string) $normalized['currency'];
            $origin ??= 'mapped_field';
        }

        [$sourceCurrency, $currencyStatus] = $this->normalizeCurrency($rawCurrencyInput);
        $sourcePrice = $this->resolveSourcePrice($normalized, $productNormalized);
        $sourceListPrice = $this->toNullableFloat($normalized['list_price'] ?? data_get($productNormalized, 'list_price'));
        $sourceNetPrice = $this->toNullableFloat($normalized['net_price'] ?? data_get($productNormalized, 'net_price'));
        $sourcePurchasePrice = $this->toNullableFloat($normalized['purchase_price'] ?? data_get($productNormalized, 'purchase_price'));

        return [
            'profile_key' => $profileKey,
            'source_price' => $sourcePrice,
            'source_currency' => $sourceCurrency,
            'source_currency_input' => $rawCurrencyInput,
            'source_list_price' => $sourceListPrice,
            'source_net_price' => $sourceNetPrice,
            'source_purchase_price' => $sourcePurchasePrice,
            'currency_origin' => $origin,
            'currency_status' => $currencyStatus,
            'pricing_policy_type' => $normalized['pricing_policy_type'] ?? data_get($productNormalized, 'pricing_policy_type'),
        ];
    }

    public function buildProjectionCurrencySnapshot(
        TenantAccount $tenant,
        array $priceSnapshot,
        ?string $requestedDate = null,
    ): array {
        $requestedDate ??= now()->toDateString();
        $tenantBaseCurrency = $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant);
        $sourcePrice = $this->toNullableFloat($priceSnapshot['source_price'] ?? $priceSnapshot['list_price'] ?? null);
        $sourceCurrency = $priceSnapshot['source_currency'] ?? $priceSnapshot['currency'] ?? null;
        $currencyStatus = (string) ($priceSnapshot['currency_status'] ?? (filled($sourceCurrency) ? 'resolved' : 'missing'));

        $snapshot = [
            'source_price' => $sourcePrice,
            'source_currency' => $sourceCurrency,
            'base_price' => null,
            'base_currency' => $tenantBaseCurrency,
            'conversion_available' => false,
            'conversion_status' => 'missing_source_price',
            'applied_rate' => null,
            'rate_date' => null,
            'rate_source' => null,
            'rate_type' => null,
            'is_fallback_rate' => false,
            'is_stale_rate' => false,
            'currency_origin' => $priceSnapshot['currency_origin'] ?? null,
            'currency_status' => $currencyStatus,
            'tenant_base_currency' => $tenantBaseCurrency,
            'requested_rate_date' => $requestedDate,
            'projected_at' => now()->toIso8601String(),
        ];

        if ($sourcePrice === null) {
            return $snapshot;
        }

        if ($currencyStatus === 'missing') {
            $snapshot['conversion_status'] = 'missing_currency';

            return $snapshot;
        }

        if ($currencyStatus === 'unsupported') {
            $snapshot['conversion_status'] = 'unsupported_currency';

            return $snapshot;
        }

        if (!filled($sourceCurrency)) {
            $snapshot['conversion_status'] = 'missing_currency';

            return $snapshot;
        }

        try {
            $normalizedSource = $this->normalizer->normalize($sourceCurrency);

            if ($normalizedSource === $tenantBaseCurrency) {
                $snapshot['base_price'] = $sourcePrice;
                $snapshot['conversion_available'] = true;
                $snapshot['conversion_status'] = 'not_required';
                $snapshot['applied_rate'] = 1.0;
                $snapshot['rate_date'] = $requestedDate;
                $snapshot['rate_source'] = 'identity';
                $snapshot['rate_type'] = (string) config('prodelya_currency.default_rate_type', 'forex_selling');

                return $snapshot;
            }

            $result = $this->conversionService->convert(
                $sourcePrice,
                $normalizedSource,
                $tenantBaseCurrency,
                $requestedDate
            );

            $snapshot['base_price'] = (float) $result->targetAmount;
            $snapshot['conversion_available'] = true;
            $snapshot['conversion_status'] = $result->isStale ? 'stale_rate' : 'converted';
            $snapshot['applied_rate'] = (float) $result->effectiveRate;
            $snapshot['rate_date'] = $result->resolvedRateDate;
            $snapshot['rate_source'] = $result->rateSource;
            $snapshot['rate_type'] = $result->rateType;
            $snapshot['is_fallback_rate'] = $result->isFallbackDate;
            $snapshot['is_stale_rate'] = $result->isStale;
            $snapshot['conversion_meta'] = [
                'requested_date' => $result->requestedDate,
                'legs' => $result->legs,
            ];

            return $snapshot;
        } catch (ExchangeRateNotFoundException) {
            $snapshot['conversion_status'] = 'missing_rate';

            return $snapshot;
        } catch (UnsupportedCurrencyException) {
            $snapshot['conversion_status'] = 'unsupported_currency';
            $snapshot['currency_status'] = 'unsupported';

            return $snapshot;
        } catch (\Throwable $exception) {
            $snapshot['conversion_status'] = 'conversion_error';
            $snapshot['conversion_error'] = class_basename($exception);

            return $snapshot;
        }
    }

    public function buildCapabilityFlags(TenantAccount $tenant, ?Authenticatable $user): array
    {
        $multiCurrencyEnabled = $this->tenantAccessService->canAccessModule($tenant, 'multi_currency');
        $canViewCurrencyDetails = $multiCurrencyEnabled && $this->userCanViewCurrencyDetails($tenant, $user);

        return [
            'multi_currency_enabled' => $multiCurrencyEnabled,
            'can_view_currency_details' => $canViewCurrencyDetails,
            'can_use_foreign_document_currency' => $multiCurrencyEnabled,
            'can_use_manual_rate' => false,
        ];
    }

    public function buildBrowserCurrencyPayload(
        TenantAccount $tenant,
        ?Authenticatable $user,
        array $currencySnapshot,
    ): array {
        $flags = $this->buildCapabilityFlags($tenant, $user);
        $canViewDetails = (bool) $flags['can_view_currency_details'];

        return array_merge($flags, [
            'source_price' => $canViewDetails ? $this->toNullableFloat($currencySnapshot['source_price'] ?? null) : null,
            'source_currency' => $canViewDetails ? ($currencySnapshot['source_currency'] ?? null) : null,
            'base_price' => $this->toNullableFloat($currencySnapshot['base_price'] ?? null),
            'base_currency' => $currencySnapshot['base_currency'] ?? $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant),
            'conversion_available' => (bool) ($currencySnapshot['conversion_available'] ?? false),
            'conversion_status' => $currencySnapshot['conversion_status'] ?? 'missing_source_price',
            'applied_rate' => $canViewDetails ? $this->toNullableFloat($currencySnapshot['applied_rate'] ?? null) : null,
            'rate_date' => $canViewDetails ? ($currencySnapshot['rate_date'] ?? null) : null,
            'rate_source' => $canViewDetails ? ($currencySnapshot['rate_source'] ?? null) : null,
            'rate_type' => $canViewDetails ? ($currencySnapshot['rate_type'] ?? null) : null,
            'is_fallback_rate' => $canViewDetails ? (bool) ($currencySnapshot['is_fallback_rate'] ?? false) : false,
            'is_stale_rate' => $canViewDetails ? (bool) ($currencySnapshot['is_stale_rate'] ?? false) : false,
            'currency_origin' => $canViewDetails ? ($currencySnapshot['currency_origin'] ?? null) : null,
            'currency_status' => $currencySnapshot['currency_status'] ?? null,
        ]);
    }

    public function sanitizePriceSnapshotForBrowser(
        array $priceSnapshot,
        TenantAccount $tenant,
        ?Authenticatable $user,
    ): array {
        $payload = $this->buildBrowserCurrencyPayload(
            $tenant,
            $user,
            (array) ($priceSnapshot['currency_snapshot'] ?? $priceSnapshot)
        );
        $canViewDetails = (bool) ($payload['can_view_currency_details'] ?? false);

        unset(
            $priceSnapshot['purchase_price'],
            $priceSnapshot['source_net_price'],
            $priceSnapshot['source_purchase_price'],
            $priceSnapshot['conversion_meta'],
            $priceSnapshot['conversion_error']
        );

        if (!$canViewDetails) {
            unset(
                $priceSnapshot['source_price'],
                $priceSnapshot['source_currency'],
                $priceSnapshot['applied_rate'],
                $priceSnapshot['rate_date'],
                $priceSnapshot['rate_source'],
                $priceSnapshot['rate_type'],
                $priceSnapshot['currency_origin'],
                $payload['source_price'],
                $payload['source_currency'],
                $payload['applied_rate'],
                $payload['rate_date'],
                $payload['rate_source'],
                $payload['rate_type'],
                $payload['currency_origin']
            );
        }

        return array_merge($priceSnapshot, $payload);
    }

    private function resolveProfileKey(SupplierSource $source): ?string
    {
        $profileKey = data_get($source->config, 'profile_key')
            ?? data_get($source->config, 'profile_identity_key');

        if (!is_string($profileKey) || trim($profileKey) === '') {
            return null;
        }

        return trim($profileKey);
    }

    private function resolveCurrencyFieldCandidates(array $profile): array
    {
        return collect($profile['field_aliases'] ?? [])
            ->filter(fn ($target) => $target === 'currency')
            ->keys()
            ->values()
            ->all();
    }

    private function firstFilledValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function normalizeCurrency(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [null, 'missing'];
        }

        try {
            return [$this->normalizer->normalize($value), 'resolved'];
        } catch (UnsupportedCurrencyException) {
            return [null, 'unsupported'];
        }
    }

    private function resolveSourcePrice(array $normalized, ?array $productNormalized = null): ?float
    {
        return $this->toNullableFloat(
            $normalized['list_price']
            ?? $normalized['purchase_price']
            ?? data_get($productNormalized, 'list_price')
            ?? data_get($productNormalized, 'purchase_price')
        );
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function userCanViewCurrencyDetails(TenantAccount $tenant, ?Authenticatable $user): bool
    {
        if (!$user) {
            return false;
        }

        if (property_exists($user, 'is_platform_admin') && (bool) $user->is_platform_admin) {
            return true;
        }

        if (!method_exists($user, 'hasAnyPermissionInTenant')) {
            return false;
        }

        $permissions = (array) config('prodelya_product_data_hub.currency_contract.detail_permissions', []);

        return $permissions !== []
            && (bool) $user->hasAnyPermissionInTenant($permissions, $tenant->id);
    }
}
