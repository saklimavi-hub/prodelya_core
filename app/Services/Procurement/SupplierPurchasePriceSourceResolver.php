<?php

namespace App\Services\Procurement;

use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\ProductPriceSnapshot;
use App\Models\StandardProduct;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Services\Currency\CurrencyCodeNormalizer;

class SupplierPurchasePriceSourceResolver
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
    ) {
    }

    public function resolveForProcurement(OrderItemProcurement $procurement): array
    {
        $procurement->loadMissing([
            'orderItem.standardProduct',
            'orderItem.standardProductVariant',
        ]);

        $orderItem = $procurement->orderItem;
        $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];

        if ($resolved = $this->resolveSnapshotSource($snapshot)) {
            return $resolved;
        }

        if ($resolved = $this->resolveFromVariantRaw($procurement, $orderItem)) {
            return $resolved;
        }

        if ($resolved = $this->resolveFromRawProduct($procurement, $orderItem)) {
            return $resolved;
        }

        if ($resolved = $this->resolveFromStandardProduct($orderItem?->standardProduct)) {
            return $resolved;
        }

        if ($resolved = $this->resolveFromHistoricalSnapshot($orderItem?->standard_product_id)) {
            return $resolved;
        }

        return $this->unresolved('missing_supplier_purchase_source');
    }

    public function resolveFromExistingSnapshot(array $snapshot): array
    {
        $amount = $this->toNullableDecimal(
            data_get($snapshot, 'purchase_source_amount')
                ?? data_get($snapshot, 'source_amount')
        );
        $currency = $this->normalizeCurrency(
            data_get($snapshot, 'purchase_source_currency')
                ?? data_get($snapshot, 'source_currency')
        );

        if ($amount === null || $currency === null) {
            return $this->unresolved((string) (data_get($snapshot, 'warning_code') ?: 'legacy_unknown'), 'legacy_snapshot');
        }

        return [
            'source_type' => data_get($snapshot, 'source_type'),
            'source_id' => $this->toNullableInt(data_get($snapshot, 'source_id')),
            'supplier_product_code' => data_get($snapshot, 'supplier_product_code'),
            'supplier_variant_code' => data_get($snapshot, 'supplier_variant_code'),
            'amount_original' => $amount,
            'currency_original' => $currency,
            'price_updated_at' => data_get($snapshot, 'price_updated_at'),
            'resolution_status' => (string) (data_get($snapshot, 'resolution_status') ?: 'legacy_snapshot'),
            'warning_code' => data_get($snapshot, 'warning_code'),
            'source_field' => data_get($snapshot, 'source_field'),
            'source_kind' => data_get($snapshot, 'source_kind'),
        ];
    }

    private function resolveSnapshotSource(array $snapshot): ?array
    {
        $purchaseSnapshot = (array) data_get($snapshot, 'purchase_price_snapshot', []);

        foreach ([$purchaseSnapshot, $snapshot] as $candidate) {
            $amount = $this->toNullableDecimal(
                data_get($candidate, 'purchase_source_amount')
                    ?? data_get($candidate, 'source_amount')
                    ?? data_get($candidate, 'purchase_list_price')
            );
            $currency = $this->normalizeCurrency(
                data_get($candidate, 'purchase_source_currency')
                    ?? data_get($candidate, 'source_currency')
            );

            if ($amount !== null && $currency !== null) {
                return [
                    'source_type' => data_get($candidate, 'source_type', 'procurement_snapshot'),
                    'source_id' => $this->toNullableInt(data_get($candidate, 'source_id')),
                    'supplier_product_code' => data_get($candidate, 'supplier_product_code'),
                    'supplier_variant_code' => data_get($candidate, 'supplier_variant_code'),
                    'amount_original' => $amount,
                    'currency_original' => $currency,
                    'price_updated_at' => data_get($candidate, 'price_updated_at'),
                    'resolution_status' => 'legacy_snapshot',
                    'warning_code' => data_get($candidate, 'warning_code'),
                    'source_field' => data_get($candidate, 'source_field'),
                    'source_kind' => data_get($candidate, 'source_kind'),
                ];
            }
        }

        return null;
    }

    private function resolveFromVariantRaw(OrderItemProcurement $procurement, ?OrderItem $orderItem): ?array
    {
        $productSnapshot = is_array($orderItem?->product_snapshot) ? $orderItem->product_snapshot : [];
        $variantRawId = $this->toNullableInt(data_get($productSnapshot, 'supplier_product_variant_raw_id'));

        $query = SupplierProductVariantRaw::query()->with(['rawProduct.source', 'source']);

        if ($variantRawId) {
            $variant = $query->find($variantRawId);
        } elseif ($orderItem?->standard_product_variant_id) {
            $variant = $query
                ->where('standard_product_variant_id', $orderItem->standard_product_variant_id)
                ->when($procurement->supplier_source_id, fn ($q) => $q->where('supplier_source_id', $procurement->supplier_source_id))
                ->when($procurement->supplier_id, fn ($q) => $q->where('supplier_id', $procurement->supplier_id))
                ->orderByDesc('id')
                ->first();
        } else {
            $variant = null;
        }

        if (!$variant?->rawProduct) {
            return null;
        }

        return $this->resolveFromVariantModel($variant, [
            'source_id' => $variant->id,
            'supplier_variant_code' => $variant->variant_code,
        ]);
    }

    private function resolveFromRawProduct(OrderItemProcurement $procurement, ?OrderItem $orderItem): ?array
    {
        $productSnapshot = is_array($orderItem?->product_snapshot) ? $orderItem->product_snapshot : [];
        $rawProductId = $this->toNullableInt(data_get($productSnapshot, 'supplier_product_raw_id'));

        if ($rawProductId) {
            $raw = SupplierProductRaw::query()->with('source')->find($rawProductId);
        } else {
            $raw = SupplierProductRaw::query()
                ->with('source')
                ->when($procurement->supplier_source_id, fn ($q) => $q->where('supplier_source_id', $procurement->supplier_source_id))
                ->when($procurement->supplier_id, fn ($q) => $q->where('supplier_id', $procurement->supplier_id))
                ->when($orderItem?->standard_product_id, fn ($q) => $q->where('standard_product_id', $orderItem->standard_product_id))
                ->orderByDesc('synced_at')
                ->orderByDesc('id')
                ->first();
        }

        if (!$raw) {
            $standardProduct = $orderItem?->standardProduct;
            if ($standardProduct?->supplier_product_raw_id) {
                $raw = SupplierProductRaw::query()->with('source')->find($standardProduct->supplier_product_raw_id);
            }
        }

        if (!$raw) {
            return null;
        }

        return $this->resolveFromRawModel($raw, 'supplier_product_raw');
    }

    private function resolveFromVariantModel(SupplierProductVariantRaw $variant, array $extra = []): ?array
    {
        $raw = $variant->rawProduct;

        if (!$raw) {
            return null;
        }

        $contract = $this->resolveContractValues(
            profileKey: $this->resolveProfileKey($raw),
            normalized: is_array($variant->normalized_payload) ? $variant->normalized_payload : [],
            rawPayload: is_array($variant->raw_payload) ? $variant->raw_payload : [],
            raw: $raw,
        );

        if ($contract === null) {
            return null;
        }

        return [
            'source_type' => 'supplier_product_variant_raw_' . $contract['source_kind'],
            'source_id' => $extra['source_id'] ?? $variant->id,
            'supplier_product_code' => $raw->supplier_product_code,
            'supplier_variant_code' => $extra['supplier_variant_code'] ?? $variant->variant_code,
            'amount_original' => $contract['amount_original'],
            'currency_original' => $contract['currency_original'],
            'price_updated_at' => optional($raw->synced_at ?: $raw->updated_at)?->toAtomString(),
            'resolution_status' => 'resolved',
            'warning_code' => null,
            'source_field' => $contract['source_field'],
            'source_kind' => $contract['source_kind'],
        ];
    }

    private function resolveFromRawModel(SupplierProductRaw $raw, string $sourceType, array $extra = []): ?array
    {
        $contract = $this->resolveContractValues(
            profileKey: $this->resolveProfileKey($raw),
            normalized: is_array($raw->normalized_payload) ? $raw->normalized_payload : [],
            rawPayload: is_array($raw->raw_payload) ? $raw->raw_payload : [],
            raw: $raw,
        );

        if ($contract === null) {
            return null;
        }

        return [
            'source_type' => $sourceType . '_' . $contract['source_kind'],
            'source_id' => $extra['source_id'] ?? $raw->id,
            'supplier_product_code' => $raw->supplier_product_code,
            'supplier_variant_code' => $extra['supplier_variant_code'] ?? null,
            'amount_original' => $contract['amount_original'],
            'currency_original' => $contract['currency_original'],
            'price_updated_at' => optional($raw->synced_at ?: $raw->updated_at)?->toAtomString(),
            'resolution_status' => 'resolved',
            'warning_code' => null,
            'source_field' => $contract['source_field'],
            'source_kind' => $contract['source_kind'],
        ];
    }

    private function resolveFromStandardProduct(?StandardProduct $product): ?array
    {
        if (!$product) {
            return null;
        }

        $amount = $this->toNullableDecimal($product->purchase_price);
        $currency = $this->normalizeCurrency($product->purchase_currency ?: $product->currency);

        if ($amount === null || $currency === null) {
            return null;
        }

        return [
            'source_type' => 'standard_product_purchase_price',
            'source_id' => $product->id,
            'supplier_product_code' => $product->sku ?: $product->standard_product_code,
            'supplier_variant_code' => null,
            'amount_original' => $amount,
            'currency_original' => $currency,
            'price_updated_at' => optional($product->updated_at)?->toAtomString(),
            'resolution_status' => 'resolved',
            'warning_code' => null,
            'source_field' => 'purchase_price',
            'source_kind' => 'standard_product_purchase_price',
        ];
    }

    private function resolveFromHistoricalSnapshot(?int $standardProductId): ?array
    {
        if (!$standardProductId) {
            return null;
        }

        $snapshot = ProductPriceSnapshot::query()
            ->where('standard_product_id', $standardProductId)
            ->whereNotNull('purchase_price')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (!$snapshot) {
            return null;
        }

        $amount = $this->toNullableDecimal($snapshot->purchase_price);
        $currency = $this->normalizeCurrency($snapshot->purchase_currency);

        if ($amount === null || $currency === null) {
            return null;
        }

        return [
            'source_type' => 'product_price_snapshot_purchase_price',
            'source_id' => $snapshot->id,
            'supplier_product_code' => null,
            'supplier_variant_code' => null,
            'amount_original' => $amount,
            'currency_original' => $currency,
            'price_updated_at' => optional($snapshot->created_at)?->toAtomString(),
            'resolution_status' => 'resolved',
            'warning_code' => null,
            'source_field' => 'purchase_price',
            'source_kind' => 'historical_purchase_price',
        ];
    }

    private function unresolved(string $warningCode, string $resolutionStatus = 'unresolved'): array
    {
        return [
            'source_type' => null,
            'source_id' => null,
            'supplier_product_code' => null,
            'supplier_variant_code' => null,
            'amount_original' => null,
            'currency_original' => null,
            'price_updatedAt' => null,
            'price_updated_at' => null,
            'resolution_status' => $resolutionStatus,
            'warning_code' => $warningCode,
            'source_field' => null,
            'source_kind' => null,
        ];
    }

    private function resolveContractValues(string $profileKey, array $normalized, array $rawPayload, SupplierProductRaw $raw): ?array
    {
        return match ($profileKey) {
            'AKDENIZ' => $this->resolveAkdenizGrossList($normalized, $rawPayload, $raw),
            'POZITRON_JSON' => $this->resolveValueContract(
                $this->firstDecimal([
                    data_get($normalized, 'list_price'),
                    data_get($rawPayload, 'fiyat_normal'),
                    data_get($rawPayload, 'fiyat'),
                    data_get($rawPayload, 'urun_fiyati'),
                    $raw->source_price,
                ]),
                $this->normalizeCurrency(
                    data_get($normalized, 'currency')
                        ?: data_get($rawPayload, 'currency')
                        ?: $raw->source_currency
                        ?: $raw->currency
                        ?: data_get($raw->source?->config, 'currency')
                ),
                $this->firstFilledFieldName($normalized, $rawPayload, ['list_price', 'fiyat_normal', 'fiyat', 'urun_fiyati', 'source_price']),
                'supplier_list_price'
            ),
            'YENI-NESIL' => $this->resolveValueContract(
                $this->firstDecimal([
                    data_get($normalized, 'list_price'),
                    data_get($rawPayload, 'fiyat'),
                    $raw->source_price,
                ]),
                $this->normalizeCurrency(
                    data_get($normalized, 'currency')
                        ?: data_get($rawPayload, 'currency')
                        ?: $raw->source_currency
                        ?: $raw->currency
                        ?: 'TRY'
                ),
                $this->firstFilledFieldName($normalized, $rawPayload, ['list_price', 'fiyat', 'source_price']),
                'supplier_list_price'
            ),
            'ETKIN', 'ILPEN' => $this->resolveValueContract(
                $this->firstDecimal([
                    $raw->purchase_price,
                    data_get($normalized, 'purchase_price'),
                    $raw->source_price,
                ]),
                $this->normalizeCurrency($raw->currency ?: data_get($normalized, 'currency') ?: $raw->source_currency),
                $this->firstFilledFieldName($normalized, $rawPayload, ['purchase_price', 'AlisFiyati', 'urun_fiyat', 'source_price']),
                'supplier_list_price'
            ),
            default => $this->resolveValueContract(
                $this->firstDecimal([
                    $raw->purchase_price,
                    $raw->source_price,
                    data_get($normalized, 'purchase_price'),
                    data_get($normalized, 'list_price'),
                ]),
                $this->normalizeCurrency($raw->currency ?: $raw->source_currency ?: data_get($normalized, 'currency')),
                $this->firstFilledFieldName($normalized, $rawPayload, ['purchase_price', 'source_price', 'list_price']),
                'supplier_list_price'
            ),
        };
    }

    private function resolveAkdenizGrossList(array $normalized, array $rawPayload, SupplierProductRaw $raw): ?array
    {
        return $this->resolveValueContract(
            $this->firstDecimal([
                data_get($rawPayload, 'listefiyati'),
                data_get($rawPayload, 'listefiyatkapali'),
                data_get($normalized, 'list_price'),
                data_get($normalized, 'closed_list_price'),
                $raw->source_price,
            ]),
            $this->normalizeCurrency(
                data_get($rawPayload, 'kur')
                    ?: data_get($normalized, 'currency')
                    ?: $raw->source_currency
                    ?: $raw->currency
            ),
            $this->firstFilledFieldName($normalized, $rawPayload, ['listefiyati', 'listefiyatkapali', 'list_price', 'closed_list_price', 'source_price']),
            'supplier_list_price'
        );
    }

    private function resolveValueContract(?string $amount, ?string $currency, ?string $sourceField, string $sourceKind): ?array
    {
        if ($amount === null || $currency === null) {
            return null;
        }

        return [
            'amount_original' => $amount,
            'currency_original' => $currency,
            'source_field' => $sourceField,
            'source_kind' => $sourceKind,
        ];
    }

    private function resolveProfileKey(SupplierProductRaw $raw): string
    {
        return (string) (
            data_get($raw->source?->config, 'profile_key')
            ?: data_get($raw->normalized_payload, 'profile_key')
            ?: 'UNKNOWN'
        );
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $this->normalizer->normalize((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toNullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function firstDecimal(array $values): ?string
    {
        foreach ($values as $value) {
            $decimal = $this->toNullableDecimal($value);

            if ($decimal !== null) {
                return $decimal;
            }
        }

        return null;
    }

    private function firstFilledFieldName(array $normalized, array $rawPayload, array $candidates): ?string
    {
        foreach ($candidates as $field) {
            if (array_key_exists($field, $rawPayload) && $this->toNullableDecimal($rawPayload[$field]) !== null) {
                return $field;
            }

            if (array_key_exists($field, $normalized) && $this->toNullableDecimal($normalized[$field]) !== null) {
                return $field;
            }
        }

        return null;
    }
}
