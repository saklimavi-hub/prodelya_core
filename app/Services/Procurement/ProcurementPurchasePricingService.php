<?php

namespace App\Services\Procurement;

use App\Exceptions\Currency\ExchangeRateNotFoundException;
use App\Exceptions\Currency\UnsupportedCurrencyException;
use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequestItem;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\CurrencyMath;
use App\Services\Currency\CurrencySnapshotBuilder;
use Carbon\CarbonImmutable;

class ProcurementPurchasePricingService
{
    public function __construct(
        private readonly SupplierPurchasePriceSourceResolver $sourceResolver,
        private readonly CurrencyConversionService $conversionService,
        private readonly CurrencySnapshotBuilder $currencySnapshotBuilder,
        private readonly CurrencyMath $math,
    ) {
    }

    public function buildDraftAttributes(OrderItemProcurement $procurement, SupplierProcurementRequestItem $item): array
    {
        $requestedDate = $this->resolveRequestedDate($procurement);
        $source = $this->sourceResolver->resolveForProcurement($procurement);

        return $this->composeAttributes(
            source: $source,
            requestedDate: $requestedDate,
            discountRate: $item->discount_rate ?? '0',
            manualUnitPrice: null,
            quantity: $item->requested_quantity,
            legacyListTryOverride: null,
            legacyOverrideReason: null,
        );
    }

    public function buildUpdatedAttributes(
        SupplierProcurementRequestItem $item,
        mixed $manualUnitPrice = null,
        mixed $quantityOverride = null,
    ): array {
        $item->loadMissing('procurement');

        $snapshot = is_array($item->purchase_price_snapshot) ? $item->purchase_price_snapshot : [];
        $hasCanonicalSnapshot = $item->hasCanonicalPurchaseSnapshot();
        $source = $hasCanonicalSnapshot
            ? $this->sourceResolver->resolveFromExistingSnapshot($snapshot)
            : $this->sourceResolver->resolveFromExistingSnapshot([
                'resolution_status' => 'legacy_snapshot',
                'warning_code' => 'legacy_unknown',
            ]);

        if (!$hasCanonicalSnapshot) {
            $source['resolution_status'] = 'legacy_snapshot';
            $source['warning_code'] = 'legacy_unknown';
        }

        return $this->composeAttributes(
            source: $source,
            requestedDate: $this->resolveRequestedDate($item->procurement, $snapshot),
            discountRate: $item->discount_rate ?? '0',
            manualUnitPrice: $manualUnitPrice,
            quantity: $quantityOverride ?? $item->requested_quantity,
            legacyListTryOverride: $item->purchase_list_price_try ?? $item->purchase_list_price,
            legacyOverrideReason: !$hasCanonicalSnapshot ? 'legacy_scalar_only' : null,
            existingSnapshot: $snapshot,
        );
    }
    public function buildRefreshedAttributes(
        SupplierProcurementRequestItem $item,
        mixed $manualUnitPrice = null,
        mixed $quantityOverride = null,
    ): array {
        $item->loadMissing('procurement');

        return $this->composeAttributes(
            source: $this->sourceResolver->resolveForProcurement($item->procurement),
            requestedDate: $this->resolveRequestedDate($item->procurement),
            discountRate: $item->discount_rate ?? '0',
            manualUnitPrice: $manualUnitPrice,
            quantity: $quantityOverride ?? $item->requested_quantity,
            legacyListTryOverride: null,
            legacyOverrideReason: null,
        );
    }

    public function suggestLegacyListPriceTry(OrderItemProcurement $procurement): ?float
    {
        $attributes = $this->composeAttributes(
            source: $this->sourceResolver->resolveForProcurement($procurement),
            requestedDate: $this->resolveRequestedDate($procurement),
            discountRate: '0',
            manualUnitPrice: null,
            quantity: '1',
            legacyListTryOverride: null,
            legacyOverrideReason: null,
        );

        return $attributes['purchase_list_price_try'] !== null
            ? (float) $attributes['purchase_list_price_try']
            : null;
    }

    private function composeAttributes(
        array $source,
        string $requestedDate,
        mixed $discountRate,
        mixed $manualUnitPrice,
        mixed $quantity,
        mixed $legacyListTryOverride,
        ?string $legacyOverrideReason,
        array $existingSnapshot = [],
    ): array {
        $sourceAmount = $source['amount_original'];
        $sourceCurrency = $source['currency_original'];
        $discount = $this->normalizeOrDefault($discountRate, '0', 2);
        $manual = $this->normalizeNullable($manualUnitPrice, 6);
        $quantityValue = $this->normalizeOrDefault($quantity, '0', 4);
        $fx = $this->resolveFxContext($sourceAmount, $sourceCurrency, $requestedDate, $existingSnapshot, $source['resolution_status']);
        $listTry = $this->normalizeNullable($legacyListTryOverride, 6) ?? $fx['list_try'];
        $calculatedUnit = $listTry !== null
            ? $this->calculateDiscountedAmount($listTry, $discount)
            : null;
        $manualOverride = $manual !== null;
        $finalUnit = $manualOverride ? $manual : $calculatedUnit;
        $total = $finalUnit !== null
            ? $this->round($this->math->multiply($finalUnit, $quantityValue), 2)
            : null;
        $resolutionStatus = $fx['resolution_status'] ?? $source['resolution_status'];
        $warningCode = $fx['warning_code'] ?? $source['warning_code'];

        $snapshot = [
            'version' => 1,
            'resolution_status' => $resolutionStatus,
            'warning_code' => $warningCode,
            'source_field' => $source['source_field'] ?? null,
            'source_kind' => $source['source_kind'] ?? null,
            'source_type' => $source['source_type'],
            'source_id' => $source['source_id'],
            'supplier_product_code' => $source['supplier_product_code'],
            'supplier_variant_code' => $source['supplier_variant_code'],
            'price_updated_at' => $source['price_updated_at'],
            'purchase_source_amount' => $sourceAmount,
            'purchase_source_currency' => $sourceCurrency,
            'purchase_settlement_currency' => 'TRY',
            'purchase_fx_rate' => $fx['rate'],
            'purchase_fx_rate_date' => $fx['rate_date'],
            'purchase_fx_rate_source' => $fx['rate_source'],
            'purchase_fx_rate_type' => $fx['rate_type'],
            'purchase_list_price_try' => $listTry,
            'purchase_discount_rate' => $discount,
            'purchase_calculated_unit_price' => $calculatedUnit,
            'purchase_manual_unit_price' => $manual,
            'purchase_manual_override' => $manualOverride,
            'purchase_manual_override_reason' => $legacyOverrideReason,
            'purchase_final_unit_price' => $finalUnit,
            'purchase_total' => $total,
            'quantity_basis' => $quantityValue,
            'requested_date' => $requestedDate,
            'fallback_used' => $fx['fallback_used'],
            'stale' => $fx['stale'],
            'conversion_legs' => $fx['conversion_legs'],
            'snapshot_locked' => true,
        ];

        return [
            'purchase_source_amount' => $sourceAmount !== null ? $this->normalizeNullable($sourceAmount, 6) : null,
            'purchase_source_currency' => $sourceCurrency,
            'purchase_fx_rate' => $fx['rate'],
            'purchase_fx_rate_date' => $fx['rate_date'],
            'purchase_fx_rate_source' => $fx['rate_source'],
            'purchase_list_price_try' => $listTry,
            'purchase_calculated_unit_price' => $calculatedUnit,
            'purchase_manual_unit_price' => $manualOverride ? $manual : null,
            'purchase_manual_override' => $manualOverride,
            'purchase_manual_override_reason' => $legacyOverrideReason,
            'purchase_settlement_currency' => 'TRY',
            'purchase_price_snapshot' => $snapshot,
            'purchase_price_snapshot_version' => 1,
            'purchase_list_price' => $listTry !== null ? $this->round($listTry, 2) : null,
            'purchase_unit_price' => $finalUnit !== null ? $this->round($finalUnit, 2) : null,
            'purchase_total' => $total,
        ];
    }

    private function resolveFxContext(
        ?string $sourceAmount,
        ?string $sourceCurrency,
        string $requestedDate,
        array $existingSnapshot,
        string $resolutionStatus,
    ): array {
        if (!empty($existingSnapshot) && (array_key_exists('purchase_list_price_try', $existingSnapshot) || array_key_exists('purchase_fx_rate', $existingSnapshot))) {
            return [
                'list_try' => $this->normalizeNullable(
                    data_get($existingSnapshot, 'purchase_list_price_try')
                        ?? data_get($existingSnapshot, 'purchase_list_price'),
                    6
                ),
                'rate' => $this->normalizeNullable(data_get($existingSnapshot, 'purchase_fx_rate'), 8),
                'rate_date' => data_get($existingSnapshot, 'purchase_fx_rate_date'),
                'rate_source' => data_get($existingSnapshot, 'purchase_fx_rate_source'),
                'rate_type' => data_get($existingSnapshot, 'purchase_fx_rate_type'),
                'fallback_used' => (bool) data_get($existingSnapshot, 'fallback_used', false),
                'stale' => (bool) data_get($existingSnapshot, 'stale', false),
                'conversion_legs' => data_get($existingSnapshot, 'conversion_legs', []),
                'resolution_status' => data_get($existingSnapshot, 'resolution_status', $resolutionStatus),
                'warning_code' => data_get($existingSnapshot, 'warning_code'),
            ];
        }

        if ($resolutionStatus === 'legacy_snapshot') {
            return [
                'list_try' => $this->normalizeNullable(
                    data_get($existingSnapshot, 'purchase_list_price_try')
                        ?? data_get($existingSnapshot, 'purchase_list_price'),
                    6
                ),
                'rate' => $this->normalizeNullable(data_get($existingSnapshot, 'purchase_fx_rate'), 8),
                'rate_date' => data_get($existingSnapshot, 'purchase_fx_rate_date'),
                'rate_source' => data_get($existingSnapshot, 'purchase_fx_rate_source'),
                'rate_type' => data_get($existingSnapshot, 'purchase_fx_rate_type'),
                'fallback_used' => (bool) data_get($existingSnapshot, 'fallback_used', false),
                'stale' => (bool) data_get($existingSnapshot, 'stale', false),
                'conversion_legs' => data_get($existingSnapshot, 'conversion_legs', []),
                'resolution_status' => 'legacy_snapshot',
                'warning_code' => data_get($existingSnapshot, 'warning_code', 'legacy_unknown'),
            ];
        }

        if ($sourceAmount === null || $sourceCurrency === null) {
            return [
                'list_try' => null,
                'rate' => null,
                'rate_date' => null,
                'rate_source' => null,
                'rate_type' => null,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
                'resolution_status' => 'unresolved',
                'warning_code' => 'missing_supplier_purchase_source',
            ];
        }

        if ($sourceCurrency === 'TRY') {
            return [
                'list_try' => $this->normalizeNullable($sourceAmount, 6),
                'rate' => '1',
                'rate_date' => CarbonImmutable::parse($requestedDate)->startOfDay()->toDateTimeString(),
                'rate_source' => 'identity',
                'rate_type' => (string) config('prodelya_currency.default_rate_type', 'forex_selling'),
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
                'resolution_status' => 'resolved',
                'warning_code' => null,
            ];
        }

        try {
            $result = $this->conversionService->convert('1', $sourceCurrency, 'TRY', $requestedDate);
            $snapshot = $this->currencySnapshotBuilder->build($result);
            $rate = $this->normalizeNullable((string) $result->effectiveRate, 8);

            return [
                'list_try' => $rate !== null ? $this->round($this->math->multiply($sourceAmount, $rate), 6) : null,
                'rate' => $rate,
                'rate_date' => CarbonImmutable::parse((string) $snapshot['rate_date'])->toDateTimeString(),
                'rate_source' => $snapshot['rate_source'],
                'rate_type' => $snapshot['rate_type'],
                'fallback_used' => (bool) $snapshot['fallback_used'],
                'stale' => (bool) $result->isStale,
                'conversion_legs' => $snapshot['conversion_legs'],
                'resolution_status' => 'resolved',
                'warning_code' => null,
            ];
        } catch (ExchangeRateNotFoundException) {
            return [
                'list_try' => null,
                'rate' => null,
                'rate_date' => null,
                'rate_source' => null,
                'rate_type' => null,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
                'resolution_status' => 'manual_required',
                'warning_code' => 'missing_fx_rate',
            ];
        } catch (UnsupportedCurrencyException) {
            return [
                'list_try' => null,
                'rate' => null,
                'rate_date' => null,
                'rate_source' => null,
                'rate_type' => null,
                'fallback_used' => false,
                'stale' => false,
                'conversion_legs' => [],
                'resolution_status' => 'manual_required',
                'warning_code' => 'unsupported_source_currency',
            ];
        }
    }

    private function calculateDiscountedAmount(string $listTry, string $discount): string
    {
        $remainingPercentage = $this->math->divide(
            bcsub('100', $this->math->normalizeNumber($discount), 12),
            '100',
            12
        );

        return $this->round($this->math->multiply($listTry, $remainingPercentage), 6);
    }

    private function resolveRequestedDate(?OrderItemProcurement $procurement, array $snapshot = []): string
    {
        return (string) (
            data_get($snapshot, 'requested_date')
            ?: optional($procurement?->order?->quote_date)->toDateString()
            ?: optional($procurement?->order?->created_at)->toDateString()
            ?: now()->toDateString()
        );
    }

    private function normalizeOrDefault(mixed $value, string $default, int $precision): string
    {
        return $this->normalizeNullable($value, $precision) ?? $default;
    }

    private function normalizeNullable(mixed $value, int $precision): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->round($this->math->normalizeNumber($value), $precision);
    }

    private function round(string $value, int $precision): string
    {
        return $this->math->round($value, $precision);
    }
}
