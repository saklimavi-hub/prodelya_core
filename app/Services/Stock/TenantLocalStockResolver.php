<?php

namespace App\Services\Stock;

use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class TenantLocalStockResolver
{
    public function resolveForOrderItem(OrderItem $item): array
    {
        return $this->resolve(
            $item->tenant_account_id,
            $item->tenant_catalog_product_id,
            $item->tenant_catalog_product_variant_id,
            $item->product_code
        );
    }

    public function resolveForCatalogSelection(
        TenantAccount|int $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
    ): array {
        return $this->resolve(
            $tenant instanceof TenantAccount ? $tenant->id : $tenant,
            $product->id,
            $variant?->id,
            $variant?->variant_code ?: $product->product_code
        );
    }

    public function resolve(
        int $tenantAccountId,
        ?int $tenantCatalogProductId,
        ?int $tenantCatalogProductVariantId = null,
        ?string $productCode = null,
    ): array {
        if (!$tenantCatalogProductId) {
            return $this->unresolved('no_local_stock', $productCode);
        }

        if ($tenantCatalogProductVariantId) {
            $variantRows = $this->baseQuery($tenantAccountId, $tenantCatalogProductId)
                ->where('tenant_catalog_product_variant_id', $tenantCatalogProductVariantId)
                ->lockForUpdate()
                ->get();

            if ($variantRows->isNotEmpty()) {
                return $this->buildResolvedPayload('variant', 'exact_variant_stock_found', $productCode, $variantRows);
            }

            $legacyProductRows = $this->baseQuery($tenantAccountId, $tenantCatalogProductId)
                ->whereNull('tenant_catalog_product_variant_id')
                ->lockForUpdate()
                ->get();

            if ($legacyProductRows->isNotEmpty()) {
                return $this->unresolved('ambiguous_product_level_stock', $productCode, $legacyProductRows);
            }

            return $this->unresolved('variant_stock_missing', $productCode);
        }

        $productRows = $this->baseQuery($tenantAccountId, $tenantCatalogProductId)
            ->whereNull('tenant_catalog_product_variant_id')
            ->lockForUpdate()
            ->get();

        if ($productRows->isNotEmpty()) {
            return $this->buildResolvedPayload('product', 'flat_product_stock_found', $productCode, $productRows);
        }

        return $this->unresolved('no_local_stock', $productCode);
    }

    protected function baseQuery(int $tenantAccountId, int $tenantCatalogProductId)
    {
        return TenantLocalStock::query()
            ->where('tenant_account_id', $tenantAccountId)
            ->where('tenant_catalog_product_id', $tenantCatalogProductId)
            ->orderBy('id');
    }

    protected function buildResolvedPayload(
        string $scope,
        string $reasonCode,
        ?string $productCode,
        EloquentCollection $rows,
    ): array {
        $quantityOnHand = (float) $rows->sum(fn (TenantLocalStock $row) => (float) $row->quantity_on_hand);
        $quantityReserved = (float) $rows->sum(fn (TenantLocalStock $row) => (float) $row->quantity_reserved);
        $quantityAvailable = max(round($quantityOnHand - $quantityReserved, 4), 0.0);

        return [
            'resolved' => true,
            'scope' => $scope,
            'tenant_local_stock_id' => $rows->count() === 1 ? $rows->first()->id : null,
            'quantity_on_hand' => round($quantityOnHand, 4),
            'quantity_reserved' => round($quantityReserved, 4),
            'quantity_available' => round($quantityAvailable, 4),
            'reason_code' => $reasonCode,
            'product_code' => $productCode,
            'rows' => $rows,
        ];
    }

    protected function unresolved(
        string $reasonCode,
        ?string $productCode,
        ?EloquentCollection $rows = null,
    ): array {
        return [
            'resolved' => false,
            'scope' => 'unresolved',
            'tenant_local_stock_id' => null,
            'quantity_on_hand' => 0.0,
            'quantity_reserved' => 0.0,
            'quantity_available' => 0.0,
            'reason_code' => $reasonCode,
            'product_code' => $productCode,
            'rows' => $rows ?? new EloquentCollection(),
        ];
    }
}
