<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;

class ProductHubSellableTruthService
{
    private const EPSILON = 0.0001;

    public function resolve(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): array
    {
        $effectivePrice = $this->toFloat($variant?->display_price ?? $product->display_price);
        $effectiveCurrency = $variant?->currency ?: $product->currency ?: 'TL';
        $localStock = $variant
            ? (float) (($variant->local_stock_quantity ?? 0) > 0 ? $variant->local_stock_quantity : ($product->local_stock_quantity ?? 0))
            : (float) ($product->local_stock_quantity ?? 0);
        $supplierStock = $variant
            ? (float) (($variant->supplier_stock_quantity ?? 0) > 0 ? $variant->supplier_stock_quantity : ($product->supplier_stock_quantity ?? 0))
            : (float) ($product->supplier_stock_quantity ?? 0);
        $fallbackStock = $variant
            ? (float) (($variant->stock_quantity ?? 0) > 0 ? $variant->stock_quantity : ($product->total_stock_quantity ?? 0))
            : (float) ($product->total_stock_quantity ?? 0);
        $effectiveStock = $this->resolveEffectiveStock(
            $localStock,
            $supplierStock,
            $fallbackStock,
            (bool) ($product->local_stock_priority ?? true)
        );
        [$isStale, $staleReason] = $this->resolveStaleState($product, $variant, $effectivePrice, $effectiveStock);

        return [
            'effective_price' => $effectivePrice,
            'effective_stock' => $effectiveStock,
            'effective_currency' => $effectiveCurrency,
            'source_layer' => $variant ? 'tenant_catalog_product_variants' : 'tenant_catalog_products',
            'is_stale' => $isStale,
            'stale_reason' => $staleReason,
            'category_status' => $this->resolveCategoryStatus($product),
            'quote_visibility_status' => $this->resolveQuoteVisibilityStatus($product, $variant),
            'tenant_catalog_status' => $this->resolveTenantCatalogStatus($product, $variant),
            'tenant_price_override' => $this->hasTenantPriceOverride($product, $variant, $effectivePrice),
        ];
    }

    public function resolveEffectiveStock(float $localStock, float $supplierStock, float $fallbackStock, bool $localStockPriority = true): float
    {
        if ($localStockPriority && $localStock > 0) {
            return $localStock;
        }

        if ($supplierStock > 0) {
            return $supplierStock;
        }

        if ($localStock > 0) {
            return $localStock;
        }

        return max(0, $fallbackStock);
    }

    private function resolveCategoryStatus(TenantCatalogProduct $product): string
    {
        $meta = (array) ($product->meta ?? []);

        if (
            blank($product->standard_category_id)
            || (bool) data_get($meta, 'category_missing_warning', false)
            || data_get($meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'
            || in_array((string) data_get($meta, 'projection_status'), ['category_pending', 'category_conflict'], true)
        ) {
            return 'category_waiting';
        }

        return 'matched';
    }

    private function resolveQuoteVisibilityStatus(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): string
    {
        if ($variant) {
            if (data_get($variant->meta, 'quote_search_visible') !== null) {
                return (bool) data_get($variant->meta, 'quote_search_visible') ? 'visible' : 'hidden';
            }

            return (bool) ($product->visible_in_quote ?? false) ? 'visible' : 'hidden';
        }

        return (bool) ($product->visible_in_quote ?? false) ? 'visible' : 'hidden';
    }

    private function resolveTenantCatalogStatus(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): string
    {
        if (!(bool) ($product->is_active ?? true) || ($variant && !(bool) ($variant->is_active ?? true))) {
            return 'inactive';
        }

        if (!(bool) ($product->visible_in_catalog ?? true) || ($variant && !(bool) ($variant->visible_in_catalog ?? true))) {
            return 'hidden';
        }

        return (string) ($product->catalog_status ?: 'ready');
    }

    private function resolveStaleState(
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        ?float $effectivePrice,
        float $effectiveStock
    ): array {
        $standardPrice = $variant
            ? $this->toFloat($variant->standardVariant?->min_purchase_price ?? $product->standardProduct?->min_purchase_price)
            : $this->toFloat($product->standardProduct?->min_purchase_price);

        $standardStock = $variant
            ? $this->toFloat($variant->standardVariant?->stock_quantity ?? $product->standardProduct?->total_stock_quantity)
            : $this->toFloat($product->standardProduct?->total_stock_quantity);

        $priceStale = $this->valuesDiffer($effectivePrice, $standardPrice);
        $stockStale = $this->valuesDiffer($effectiveStock, $standardStock);

        return match (true) {
            $priceStale && $stockStale => [true, 'price_and_stock'],
            $priceStale => [true, 'price'],
            $stockStale => [true, 'stock'],
            default => [false, null],
        };
    }

    private function hasTenantPriceOverride(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant, ?float $effectivePrice): bool
    {
        $salePrice = $this->toFloat($product->sale_price);

        if ($salePrice === null || $effectivePrice === null) {
            return false;
        }

        return abs($salePrice - $effectivePrice) > self::EPSILON;
    }

    private function valuesDiffer(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) > self::EPSILON;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
