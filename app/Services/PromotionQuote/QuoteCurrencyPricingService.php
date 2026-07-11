<?php

namespace App\Services\PromotionQuote;

use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Exceptions\Currency\UnsupportedCurrencyException;
use App\Models\TenantAccount;
use App\Services\Currency\CurrencyCodeNormalizer;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\CurrencyMath;
use App\Services\Currency\CurrencySnapshotBuilder;
use App\Services\Currency\TenantCurrencyPolicyService;

class QuoteCurrencyPricingService
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly CurrencyConversionService $conversionService,
        private readonly TenantCurrencyPolicyService $tenantCurrencyPolicyService,
        private readonly CurrencySnapshotBuilder $currencySnapshotBuilder,
        private readonly CurrencyMath $math,
    ) {
    }

    public function normalizeDocumentCurrency(TenantAccount $tenant, array $access, ?string $requestedCurrency): string
    {
        if (!($access['multi_currency_enabled'] ?? false)) {
            return 'TRY';
        }

        $requested = $requestedCurrency ?: $tenant->default_currency ?: 'TRY';

        return $this->normalizer->normalize($requested);
    }

    public function buildItemPricing(
        TenantAccount $tenant,
        string $documentCurrency,
        array $catalogPriceSnapshot,
        array $itemData,
        string $requestedDate,
    ): array {
        $tenantBaseCurrency = $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant);
        $sourcePrice = $this->toNullableFloat($catalogPriceSnapshot['source_price'] ?? $catalogPriceSnapshot['list_price'] ?? null);
        $sourceCurrency = $this->normalizeNullableCurrency($catalogPriceSnapshot['source_currency'] ?? $catalogPriceSnapshot['currency'] ?? null);
        $baseCost = $this->toNullableFloat($catalogPriceSnapshot['base_price'] ?? null);
        $conversionStatus = (string) ($catalogPriceSnapshot['conversion_status'] ?? 'missing_source_price');
        $baseSnapshot = [
            'source_price' => $sourcePrice,
            'source_currency' => $sourceCurrency,
            'source_list_price' => $this->toNullableFloat($catalogPriceSnapshot['source_list_price'] ?? $catalogPriceSnapshot['list_price'] ?? null),
            'source_net_price' => $this->toNullableFloat($catalogPriceSnapshot['source_net_price'] ?? null),
            'tenant_base_currency' => $tenantBaseCurrency,
            'base_cost' => $baseCost,
            'conversion_status' => $conversionStatus,
            'requested_date' => $requestedDate,
            'currency_origin' => $catalogPriceSnapshot['currency_origin'] ?? null,
            'currency_status' => $catalogPriceSnapshot['currency_status'] ?? null,
            'snapshot_version' => 1,
        ];

        $submittedUnitPriceDocument = $this->toNullableFloat($itemData['unit_price'] ?? null);
        $calculatedUnitPriceDocument = $this->toNullableFloat($itemData['calculated_unit_price'] ?? null);
        $manualOverride = filter_var($itemData['manual_unit_price'] ?? false, FILTER_VALIDATE_BOOL);
        $suggestedSaleUnitPriceBase = $calculatedUnitPriceDocument !== null
            ? $this->convertDocumentAmountToBase($calculatedUnitPriceDocument, $documentCurrency, $tenantBaseCurrency, $requestedDate)
            : $baseCost;
        $suggestedSaleUnitPriceDocument = $calculatedUnitPriceDocument
            ?? $this->convertBaseAmountToDocument($suggestedSaleUnitPriceBase, $tenantBaseCurrency, $documentCurrency, $requestedDate);

        $actualSaleUnitPriceDocument = $submittedUnitPriceDocument ?? $suggestedSaleUnitPriceDocument;
        $actualSaleUnitPriceBase = $actualSaleUnitPriceDocument !== null
            ? $this->convertDocumentAmountToBase($actualSaleUnitPriceDocument, $documentCurrency, $tenantBaseCurrency, $requestedDate)
            : $suggestedSaleUnitPriceBase;

        $documentSnapshot = $this->buildDocumentPricingSnapshot(
            $baseSnapshot,
            $documentCurrency,
            $tenantBaseCurrency,
            $requestedDate
        );

        return array_merge($baseSnapshot, [
            'document_currency' => $documentCurrency,
            'suggested_sales_unit_price_base' => $suggestedSaleUnitPriceBase,
            'suggested_sales_unit_price_document' => $suggestedSaleUnitPriceDocument,
            'actual_sales_unit_price_base' => $actualSaleUnitPriceBase,
            'actual_sales_unit_price_document' => $actualSaleUnitPriceDocument,
            'manual_sales_price_override' => $manualOverride,
            'applied_rate' => $documentSnapshot['applied_rate'],
            'rate_source' => $documentSnapshot['rate_source'],
            'rate_type' => $documentSnapshot['rate_type'],
            'rate_date' => $documentSnapshot['rate_date'],
            'fallback_used' => $documentSnapshot['fallback_used'],
            'stale' => $documentSnapshot['stale'],
            'conversion_legs' => $documentSnapshot['conversion_legs'],
            'document_conversion_status' => $documentSnapshot['conversion_status'],
        ]);
    }

    public function buildPrintPricingSnapshot(
        TenantAccount $tenant,
        string $documentCurrency,
        float $printUnitPriceDocument,
        float $printTotalDocument,
        string $requestedDate,
        bool $manualOverride = true,
    ): array {
        $baseCurrency = $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant);
        $unitBase = $this->convertDocumentAmountToBase($printUnitPriceDocument, $documentCurrency, $baseCurrency, $requestedDate);
        $totalBase = $this->convertDocumentAmountToBase($printTotalDocument, $documentCurrency, $baseCurrency, $requestedDate);

        return [
            'document_currency' => $documentCurrency,
            'tenant_base_currency' => $baseCurrency,
            'document_unit_price' => $printUnitPriceDocument,
            'document_total' => $printTotalDocument,
            'base_unit_price' => $unitBase,
            'base_total' => $totalBase,
            'manual_override' => $manualOverride,
            'requested_date' => $requestedDate,
            'snapshot_version' => 1,
        ];
    }

    public function buildOrderSummarySnapshot(
        TenantAccount $tenant,
        string $documentCurrency,
        array $access,
        string $requestedDate,
    ): array {
        return [
            'document_currency' => $documentCurrency,
            'tenant_base_currency' => $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant),
            'currency_policy' => ($access['multi_currency_enabled'] ?? false) ? 'multi_currency_draft' : 'base_currency_only',
            'requested_date' => $requestedDate,
            'snapshot_version' => 1,
        ];
    }

    private function buildDocumentPricingSnapshot(
        array $baseSnapshot,
        string $documentCurrency,
        string $tenantBaseCurrency,
        string $requestedDate,
    ): array {
        if ($documentCurrency === $tenantBaseCurrency) {
            return [
                'conversion_status' => 'not_required',
                'applied_rate' => 1.0,
                'rate_source' => 'identity',
                'rate_type' => (string) config('prodelya_currency.default_rate_type', 'forex_selling'),
                'rate_date' => $requestedDate,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
            ];
        }

        try {
            $result = $this->conversionService->convert(
                1,
                $tenantBaseCurrency,
                $documentCurrency,
                $requestedDate
            );
            $snapshot = $this->currencySnapshotBuilder->build($result);

            return [
                'conversion_status' => $result->isStale ? 'stale_rate' : 'converted',
                'applied_rate' => (float) $snapshot['effective_rate'],
                'rate_source' => $snapshot['rate_source'],
                'rate_type' => $snapshot['rate_type'],
                'rate_date' => $snapshot['rate_date'],
                'fallback_used' => (bool) $snapshot['fallback_used'],
                'stale' => (bool) $result->isStale,
                'conversion_legs' => $snapshot['conversion_legs'],
            ];
        } catch (ExchangeRateNotFoundException) {
            return [
                'conversion_status' => 'missing_rate',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
            ];
        } catch (UnsupportedCurrencyException) {
            return [
                'conversion_status' => 'unsupported_currency',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
            ];
        }
    }

    private function convertDocumentAmountToBase(
        float|string|int|null $amount,
        string $documentCurrency,
        string $baseCurrency,
        string $requestedDate,
    ): ?float {
        if ($amount === null) {
            return null;
        }

        if ($documentCurrency === $baseCurrency) {
            return $this->toNullableFloat($amount);
        }

        try {
            $result = $this->conversionService->convert(
                $amount,
                $documentCurrency,
                $baseCurrency,
                $requestedDate
            );

            return $this->toNullableFloat($result->targetAmount);
        } catch (ExchangeRateNotFoundException|UnsupportedCurrencyException) {
            return null;
        }
    }

    private function convertBaseAmountToDocument(
        float|string|int|null $amount,
        string $baseCurrency,
        string $documentCurrency,
        string $requestedDate,
    ): ?float {
        if ($amount === null) {
            return null;
        }

        if ($baseCurrency === $documentCurrency) {
            return $this->toNullableFloat($amount);
        }

        try {
            $result = $this->conversionService->convert(
                $amount,
                $baseCurrency,
                $documentCurrency,
                $requestedDate
            );

            return $this->toNullableFloat($result->targetAmount);
        } catch (ExchangeRateNotFoundException|UnsupportedCurrencyException) {
            return null;
        }
    }

    private function normalizeNullableCurrency(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return $this->normalizer->normalize($value);
        } catch (UnsupportedCurrencyException) {
            return strtoupper(trim($value));
        }
    }

    private function toNullableFloat(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $this->math->round($value, 2);
    }
}
