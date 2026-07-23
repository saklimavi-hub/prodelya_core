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
            'base_price' => $baseCost,
            'list_price' => $this->toNullableFloat($catalogPriceSnapshot['list_price'] ?? $catalogPriceSnapshot['source_price'] ?? null),
            'conversion_status' => $conversionStatus,
            'requested_date' => $requestedDate,
            'currency_origin' => $catalogPriceSnapshot['currency_origin'] ?? null,
            'currency_status' => $catalogPriceSnapshot['currency_status'] ?? null,
            'snapshot_version' => 1,
            'source_to_base_rate' => $this->toNullableRate($catalogPriceSnapshot['source_to_base_rate'] ?? $catalogPriceSnapshot['applied_rate'] ?? null),
            'source_to_base_rate_date' => $catalogPriceSnapshot['source_to_base_rate_date'] ?? $catalogPriceSnapshot['rate_date'] ?? null,
            'source_to_base_rate_source' => $catalogPriceSnapshot['source_to_base_rate_source'] ?? $catalogPriceSnapshot['rate_source'] ?? null,
            'source_to_base_fallback_used' => (bool) ($catalogPriceSnapshot['source_to_base_fallback_used'] ?? $catalogPriceSnapshot['fallback_used'] ?? false),
            'source_to_base_stale' => (bool) ($catalogPriceSnapshot['source_to_base_stale'] ?? $catalogPriceSnapshot['stale'] ?? false),
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

        $salesPresentation = $this->buildSalesPresentationPayload(array_merge($baseSnapshot, [
            'document_currency' => $documentCurrency,
            'suggested_sales_unit_price_document' => $suggestedSaleUnitPriceDocument,
            'actual_sales_unit_price_document' => $actualSaleUnitPriceDocument,
            'manual_sales_price_override' => $manualOverride,
            'applied_rate' => $documentSnapshot['applied_rate'],
            'rate_source' => $documentSnapshot['rate_source'],
            'rate_type' => $documentSnapshot['rate_type'],
            'rate_date' => $documentSnapshot['rate_date'],
            'fallback_used' => $documentSnapshot['fallback_used'],
            'stale' => $documentSnapshot['stale'],
            'document_conversion_status' => $documentSnapshot['conversion_status'],
        ]));

        return array_merge($baseSnapshot, [
            'document_currency' => $documentCurrency,
            'document_list_price' => $this->resolveDocumentListPrice(
                $sourcePrice,
                $sourceCurrency,
                $baseCost,
                $tenantBaseCurrency,
                $documentCurrency,
                $requestedDate
            ),
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
            'sales_presentation' => $salesPresentation,
        ]);
    }

    public function buildQuoteDisplayPayload(
        TenantAccount $tenant,
        string $documentCurrency,
        array $catalogPriceSnapshot,
        array $itemData,
        string $requestedDate,
    ): array {
        $pricingSnapshot = $this->buildItemPricing(
            $tenant,
            $documentCurrency,
            $catalogPriceSnapshot,
            $itemData,
            $requestedDate
        );

        return $this->buildQuoteDisplayPayloadFromSnapshot($documentCurrency, $pricingSnapshot);
    }

    public function buildQuoteDisplayPayloadFromSnapshot(string $documentCurrency, array $pricingSnapshot): array
    {
        $documentConversionStatus = (string) data_get($pricingSnapshot, 'document_conversion_status', 'missing_rate');
        $quotePriceValue = $this->toNullableFloat(
            data_get($pricingSnapshot, 'document_list_price')
            ?? data_get($pricingSnapshot, 'base_price')
            ?? data_get($pricingSnapshot, 'source_price')
            ?? data_get($pricingSnapshot, 'list_price')
        );
        $snapshotSourceCurrency = $this->normalizeNullableCurrency($pricingSnapshot['source_currency'] ?? null);
        $snapshotDocumentCurrency = $this->normalizeNullableCurrency($pricingSnapshot['document_currency'] ?? $documentCurrency);

        $quotePriceStatus = match (true) {
            $snapshotSourceCurrency === null || $snapshotDocumentCurrency === null => 'unavailable',
            $snapshotSourceCurrency === $snapshotDocumentCurrency => 'not_required',
            in_array($documentConversionStatus, ['missing_rate', 'unsupported_currency', 'missing_source_price'], true) => 'unavailable',
            default => 'ready',
        };

        $reasonCode = null;
        $message = null;
        $blocking = false;

        if ($quotePriceValue === null || $quotePriceValue <= 0) {
            $quotePriceValue = null;
            $quotePriceStatus = 'unavailable';
            $reasonCode = 'canonical_quote_price_unavailable';
            $message = 'Ürün satış fiyatı teklif için hazırlanamadı.';
            $blocking = true;
        }

        $quoteAppliedRate = $pricingSnapshot['applied_rate'] ?? null;
        $quoteRateDate = $pricingSnapshot['rate_date'] ?? null;
        $quoteRateSource = $pricingSnapshot['rate_source'] ?? null;
        $quoteFallbackUsed = (bool) ($pricingSnapshot['fallback_used'] ?? false);
        $quoteStale = (bool) ($pricingSnapshot['stale'] ?? false);

        if ($snapshotSourceCurrency !== null && $snapshotSourceCurrency !== $snapshotDocumentCurrency) {
            $quoteAppliedRate = $pricingSnapshot['source_to_base_rate'] ?? $quoteAppliedRate;
            $quoteRateDate = $pricingSnapshot['source_to_base_rate_date'] ?? $quoteRateDate;
            $quoteRateSource = $pricingSnapshot['source_to_base_rate_source'] ?? $quoteRateSource;
            $quoteFallbackUsed = (bool) ($pricingSnapshot['source_to_base_fallback_used'] ?? $quoteFallbackUsed);
            $quoteStale = (bool) ($pricingSnapshot['source_to_base_stale'] ?? $quoteStale);
        }

        return [
            'quote_price_value' => $quotePriceValue,
            'quote_currency' => $documentCurrency,
            'quote_price_status' => $quotePriceStatus,
            'quote_price_reason_code' => $reasonCode,
            'quote_price_message' => $message,
            'quote_price_blocking' => $blocking,
            'quote_price_snapshot' => [
                'document_currency' => $pricingSnapshot['document_currency'] ?? $documentCurrency,
                'document_list_price' => $pricingSnapshot['document_list_price'] ?? null,
                'suggested_sales_unit_price_document' => $pricingSnapshot['suggested_sales_unit_price_document'] ?? null,
                'actual_sales_unit_price_document' => $pricingSnapshot['actual_sales_unit_price_document'] ?? null,
                'manual_sales_price_override' => (bool) ($pricingSnapshot['manual_sales_price_override'] ?? false),
                'document_conversion_status' => $documentConversionStatus,
                'applied_rate' => $quoteAppliedRate,
                'rate_date' => $quoteRateDate,
                'rate_source' => $quoteRateSource,
                'rate_type' => $pricingSnapshot['rate_type'] ?? null,
                'fallback_used' => $quoteFallbackUsed,
                'stale' => $quoteStale,
                'source_price' => $pricingSnapshot['source_price'] ?? null,
                'source_currency' => $pricingSnapshot['source_currency'] ?? null,
                'base_price' => $pricingSnapshot['base_price'] ?? $pricingSnapshot['base_cost'] ?? null,
                'base_currency' => $pricingSnapshot['tenant_base_currency'] ?? 'TRY',
                'quote_price_reason_code' => $reasonCode,
                'quote_price_message' => $message,
                'quote_price_blocking' => $blocking,
                'sales_presentation' => $this->buildSalesPresentationPayload($pricingSnapshot),
            ],
        ];
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
            'source_to_base_rate' => $this->toNullableRate($catalogPriceSnapshot['source_to_base_rate'] ?? $catalogPriceSnapshot['applied_rate'] ?? null),
            'source_to_base_rate_date' => $catalogPriceSnapshot['source_to_base_rate_date'] ?? $catalogPriceSnapshot['rate_date'] ?? null,
            'source_to_base_rate_source' => $catalogPriceSnapshot['source_to_base_rate_source'] ?? $catalogPriceSnapshot['rate_source'] ?? null,
            'source_to_base_fallback_used' => (bool) ($catalogPriceSnapshot['source_to_base_fallback_used'] ?? $catalogPriceSnapshot['fallback_used'] ?? false),
            'source_to_base_stale' => (bool) ($catalogPriceSnapshot['source_to_base_stale'] ?? $catalogPriceSnapshot['stale'] ?? false),
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
            'source_to_base_rate' => $this->toNullableRate($catalogPriceSnapshot['source_to_base_rate'] ?? $catalogPriceSnapshot['applied_rate'] ?? null),
            'source_to_base_rate_date' => $catalogPriceSnapshot['source_to_base_rate_date'] ?? $catalogPriceSnapshot['rate_date'] ?? null,
            'source_to_base_rate_source' => $catalogPriceSnapshot['source_to_base_rate_source'] ?? $catalogPriceSnapshot['rate_source'] ?? null,
            'source_to_base_fallback_used' => (bool) ($catalogPriceSnapshot['source_to_base_fallback_used'] ?? $catalogPriceSnapshot['fallback_used'] ?? false),
            'source_to_base_stale' => (bool) ($catalogPriceSnapshot['source_to_base_stale'] ?? $catalogPriceSnapshot['stale'] ?? false),
        ];
    }

    public function convertManualDocumentPrice(
        TenantAccount $tenant,
        float|string|int|null $amount,
        ?string $fromCurrency,
        ?string $toCurrency,
        string $requestedDate,
    ): array {
        $resolvedAmount = $this->toNullableFloat($amount);
        $tenantBaseCurrency = $this->tenantCurrencyPolicyService->resolveBaseCurrency($tenant);

        if ($resolvedAmount === null) {
            return [
                'converted_amount' => null,
                'document_currency' => $this->normalizeNullableCurrency($toCurrency) ?? $tenantBaseCurrency,
                'source_document_currency' => $this->normalizeNullableCurrency($fromCurrency) ?? $tenantBaseCurrency,
                'conversion_status' => 'missing_source_price',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
            ];
        }

        try {
            $sourceDocumentCurrency = $this->normalizer->normalize($fromCurrency ?: $tenantBaseCurrency);
            $targetDocumentCurrency = $this->normalizer->normalize($toCurrency ?: $tenantBaseCurrency);
        } catch (UnsupportedCurrencyException) {
            return [
                'converted_amount' => null,
                'document_currency' => $toCurrency ?: $tenantBaseCurrency,
                'source_document_currency' => $fromCurrency ?: $tenantBaseCurrency,
                'conversion_status' => 'unsupported_currency',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
            ];
        }

        if ($sourceDocumentCurrency === $targetDocumentCurrency) {
            return [
                'converted_amount' => $resolvedAmount,
                'document_currency' => $targetDocumentCurrency,
                'source_document_currency' => $sourceDocumentCurrency,
                'conversion_status' => 'not_required',
                'applied_rate' => 1.0,
                'rate_source' => 'identity',
                'rate_type' => (string) config('prodelya_currency.default_rate_type', 'forex_selling'),
                'rate_date' => $requestedDate,
                'fallback_used' => false,
                'stale' => false,
            ];
        }

        try {
            $result = $this->conversionService->convert(
                $resolvedAmount,
                $sourceDocumentCurrency,
                $targetDocumentCurrency,
                $requestedDate
            );
            $snapshot = $this->currencySnapshotBuilder->build($result);

            return [
                'converted_amount' => $this->toNullableFloat($result->targetAmount),
                'document_currency' => $targetDocumentCurrency,
                'source_document_currency' => $sourceDocumentCurrency,
                'conversion_status' => $result->isStale ? 'stale_rate' : 'converted',
                'applied_rate' => (float) $snapshot['effective_rate'],
                'rate_source' => $snapshot['rate_source'],
                'rate_type' => $snapshot['rate_type'],
                'rate_date' => $snapshot['rate_date'],
                'fallback_used' => (bool) $snapshot['fallback_used'],
                'stale' => (bool) $result->isStale,
            ];
        } catch (ExchangeRateNotFoundException) {
            return [
                'converted_amount' => null,
                'document_currency' => $targetDocumentCurrency,
                'source_document_currency' => $sourceDocumentCurrency,
                'conversion_status' => 'missing_rate',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
            ];
        } catch (UnsupportedCurrencyException) {
            return [
                'converted_amount' => null,
                'document_currency' => $targetDocumentCurrency,
                'source_document_currency' => $sourceDocumentCurrency,
                'conversion_status' => 'unsupported_currency',
                'applied_rate' => null,
                'rate_source' => null,
                'rate_type' => null,
                'rate_date' => null,
                'fallback_used' => false,
                'stale' => false,
            ];
        }
    }

    public function buildSalesPresentationPayload(array $pricingSnapshot): array
    {
        $sourceCurrency = $this->normalizeNullableCurrency($pricingSnapshot['source_currency'] ?? null);
        $documentCurrency = $this->normalizeNullableCurrency($pricingSnapshot['document_currency'] ?? null)
            ?? $sourceCurrency
            ?? 'TRY';
        $tenantBaseCurrency = $this->normalizeNullableCurrency($pricingSnapshot['tenant_base_currency'] ?? $pricingSnapshot['base_currency'] ?? null) ?? 'TRY';
        $sourceAmount = $this->toNullableFloat($pricingSnapshot['source_price'] ?? $pricingSnapshot['source_list_price'] ?? null);
        $basePrice = $this->toNullableFloat($pricingSnapshot['base_price'] ?? $pricingSnapshot['base_cost'] ?? null);
        $calculatedUnit = $this->toNullableFloat($pricingSnapshot['suggested_sales_unit_price_document'] ?? $pricingSnapshot['calculated_unit_price'] ?? null);
        $finalUnit = $this->toNullableFloat($pricingSnapshot['actual_sales_unit_price_document'] ?? null);
        $discountRate = $this->toNullableFloat($pricingSnapshot['discount_rate'] ?? null);
        $conversionStatus = (string) ($pricingSnapshot['document_conversion_status'] ?? $pricingSnapshot['conversion_status'] ?? 'missing_rate');
        $sourceToBaseRate = $this->toNullableRate($pricingSnapshot['source_to_base_rate'] ?? null);
        $sourceToBaseRateDate = $pricingSnapshot['source_to_base_rate_date'] ?? null;
        $sourceToBaseRateSource = $pricingSnapshot['source_to_base_rate_source'] ?? null;
        $sourceToBaseFallback = (bool) ($pricingSnapshot['source_to_base_fallback_used'] ?? false);
        $sourceToBaseStale = (bool) ($pricingSnapshot['source_to_base_stale'] ?? false);

        if ($sourceCurrency === null || $sourceCurrency === $tenantBaseCurrency) {
            $sourceToBaseRate = null;
            $sourceToBaseRateDate = null;
            $sourceToBaseRateSource = null;
            $sourceToBaseFallback = false;
            $sourceToBaseStale = false;
        } elseif ($sourceToBaseRate === null && $sourceAmount !== null && $basePrice !== null && $sourceAmount > 0) {
            $sourceToBaseRate = (float) round($basePrice / $sourceAmount, 4);
            $sourceToBaseRateSource = $sourceToBaseRateSource ?: 'derived';
        }

        return [
            'sales_source_amount' => $sourceAmount,
            'sales_source_currency' => $sourceCurrency,
            'sales_rate' => $sourceToBaseRate,
            'sales_rate_date' => $sourceToBaseRateDate,
            'sales_rate_source' => $sourceToBaseRateSource,
            'sales_list_try' => $basePrice,
            'sales_discount_percent' => $discountRate,
            'sales_calculated_unit_try' => $calculatedUnit,
            'sales_final_unit_try' => $finalUnit,
            'sales_manual_override' => (bool) ($pricingSnapshot['manual_sales_price_override'] ?? false),
            'sales_document_currency' => $documentCurrency,
            'conversion_status' => $conversionStatus,
            'fallback_used' => $sourceToBaseFallback || (bool) ($pricingSnapshot['fallback_used'] ?? false),
            'stale' => $sourceToBaseStale || (bool) ($pricingSnapshot['stale'] ?? false),
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

    private function toNullableRate(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }

    private function resolveDocumentListPrice(
        ?float $sourcePrice,
        ?string $sourceCurrency,
        ?float $basePrice,
        string $tenantBaseCurrency,
        string $documentCurrency,
        string $requestedDate,
    ): ?float {
        if ($sourcePrice !== null && $sourceCurrency !== null && $sourceCurrency === $documentCurrency) {
            return $sourcePrice;
        }

        if ($basePrice !== null) {
            return $this->convertBaseAmountToDocument($basePrice, $tenantBaseCurrency, $documentCurrency, $requestedDate);
        }

        if ($sourcePrice !== null && $documentCurrency === $tenantBaseCurrency) {
            return $sourcePrice;
        }

        return null;
    }
}
