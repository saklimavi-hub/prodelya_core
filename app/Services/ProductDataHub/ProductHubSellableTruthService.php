<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;

class ProductHubSellableTruthService
{
    private const EPSILON = 0.0001;

    public function resolve(
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
        TenantAccount|int|null $tenant = null
    ): array {
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
        $tenantCatalogStatus = $this->resolveTenantCatalogStatus($product, $variant);
        $quoteVisibilityStatus = $this->resolveQuoteVisibilityStatus($product, $variant);
        $supplierAccess = $this->resolveSupplierAccess($product, $variant, $tenant);
        $selectionReady = $variant ? true : (bool) ($product->is_sellable ?? true);
        $tenantCatalogActive = $this->tenantCatalogStatusAllowsQuoteSelection($tenantCatalogStatus);
        $quoteVisible = $quoteVisibilityStatus === 'visible';
        $selectionAllowed = $selectionReady
            && $tenantCatalogActive
            && $quoteVisible
            && (bool) ($supplierAccess['allowed'] ?? true);
        $reasonCode = $this->resolveReasonCode(
            $product,
            $variant,
            $selectionReady,
            $tenantCatalogStatus,
            $quoteVisibilityStatus,
            $supplierAccess
        );

        return [
            'effective_price' => $effectivePrice,
            'effective_stock' => $effectiveStock,
            'effective_currency' => $effectiveCurrency,
            'source_layer' => $variant ? 'tenant_catalog_product_variants' : 'tenant_catalog_products',
            'is_stale' => $isStale,
            'stale_reason' => $staleReason,
            'category_status' => $this->resolveCategoryStatus($product),
            'quote_visibility_status' => $quoteVisibilityStatus,
            'tenant_catalog_status' => $tenantCatalogStatus,
            'tenant_price_override' => $this->hasTenantPriceOverride($product, $variant, $effectivePrice),
            'tenant_catalog_active' => $tenantCatalogActive,
            'quote_visible' => $quoteVisible,
            'selection_ready' => $selectionReady,
            'selection_allowed' => $selectionAllowed,
            'save_allowed' => $selectionAllowed,
            'sellable' => $selectionAllowed,
            'reason_code' => $reasonCode,
            'supplier_access' => $supplierAccess,
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

    private function resolveSupplierAccess(
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        TenantAccount|int|null $tenant
    ): array {
        $supplierIds = collect([
            ...collect((array) ($variant?->source_summary ?? []))->pluck('supplier_id')->all(),
            data_get($variant?->source_summary, 'supplier_id'),
            ...collect((array) ($product->source_summary ?? []))->pluck('supplier_id')->all(),
            data_get($product->source_summary, 'supplier_id'),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($this->isLocalProduct($product) || $supplierIds->isEmpty()) {
            return [
                'mode' => 'not_required',
                'allowed' => true,
                'tenant_has_rules' => false,
                'supplier_ids' => $supplierIds->all(),
            ];
        }

        $tenantId = $tenant instanceof TenantAccount ? $tenant->id : $tenant;
        if (!$tenantId) {
            return [
                'mode' => 'not_evaluated',
                'allowed' => true,
                'tenant_has_rules' => false,
                'supplier_ids' => $supplierIds->all(),
            ];
        }

        $tenantHasRules = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->exists();

        if (!$tenantHasRules) {
            return [
                'mode' => 'legacy_allow',
                'allowed' => true,
                'tenant_has_rules' => false,
                'supplier_ids' => $supplierIds->all(),
            ];
        }

        $allowed = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('supplier_id', $supplierIds->all())
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->where('can_use_in_quotes', true)
            ->exists();

        return [
            'mode' => $allowed ? 'explicit_allow' : 'explicit_deny',
            'allowed' => $allowed,
            'tenant_has_rules' => true,
            'supplier_ids' => $supplierIds->all(),
        ];
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

    private function tenantCatalogStatusAllowsQuoteSelection(string $status): bool
    {
        return !in_array($status, ['inactive', 'hidden'], true);
    }

    private function resolveReasonCode(
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        bool $selectionReady,
        string $tenantCatalogStatus,
        string $quoteVisibilityStatus,
        array $supplierAccess
    ): ?string {
        if (!($supplierAccess['allowed'] ?? true)) {
            return 'supplier_access_denied';
        }

        if (!(bool) ($product->is_active ?? true)) {
            return 'product_inactive';
        }

        if ($variant && !(bool) ($variant->is_active ?? true)) {
            return 'variant_inactive';
        }

        if (!(bool) ($product->visible_in_catalog ?? true) || $tenantCatalogStatus === 'hidden') {
            return 'product_not_quote_visible';
        }

        if ($variant && !(bool) ($variant->visible_in_catalog ?? true)) {
            return 'variant_not_quote_visible';
        }

        if ($quoteVisibilityStatus !== 'visible') {
            return $variant ? 'variant_not_quote_visible' : 'product_not_quote_visible';
        }

        if (!$selectionReady) {
            return 'catalog_identity_unresolved';
        }

        return null;
    }

    private function isLocalProduct(TenantCatalogProduct $product): bool
    {
        return (string) ($product->catalog_source ?? '') === 'local_product';
    }
}
