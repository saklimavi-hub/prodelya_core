<?php

namespace App\Services\Stock;

use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;

class TenantLocalStockPresentationService
{
    public function __construct(
        private readonly TenantLocalStockResolver $resolver,
    ) {
    }

    public function forCatalogSelection(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
    ): array {
        $projectionValue = (float) ($variant?->local_stock_quantity ?? $product->local_stock_quantity ?? 0);
        $resolved = $this->resolver->resolveForCatalogSelection($tenant, $product, $variant);
        $isOperational = (bool) ($resolved['resolved'] ?? false);
        $scope = (string) ($resolved['scope'] ?? 'unresolved');
        $reasonCode = $resolved['reason_code'] ?? null;

        if ($isOperational) {
            return [
                'local_stock_value' => (float) ($resolved['quantity_available'] ?? 0),
                'local_stock_source' => 'operational_exact',
                'local_stock_scope' => $scope,
                'local_stock_reason_code' => $reasonCode,
                'local_stock_label' => 'Yerel kullanılabilir stok',
                'local_stock_note' => null,
                'local_stock_projection_value' => $projectionValue,
                'local_stock_operational' => true,
            ];
        }

        if ($reasonCode === 'ambiguous_product_level_stock') {
            return [
                'local_stock_value' => null,
                'local_stock_source' => 'unresolved',
                'local_stock_scope' => 'unresolved',
                'local_stock_reason_code' => $reasonCode,
                'local_stock_label' => 'Yerel stok doğrulanamadı',
                'local_stock_note' => 'Siparişe dönüşümde yerel stok yeniden kontrol edilir.',
                'local_stock_projection_value' => $projectionValue,
                'local_stock_operational' => false,
            ];
        }

        return [
            'local_stock_value' => $projectionValue,
            'local_stock_source' => 'catalog_projection',
            'local_stock_scope' => $variant ? 'variant_projection' : 'product_projection',
            'local_stock_reason_code' => $reasonCode,
            'local_stock_label' => 'Katalog stok bilgisi',
            'local_stock_note' => 'Siparişe dönüşümde yerel stok yeniden kontrol edilir.',
            'local_stock_projection_value' => $projectionValue,
            'local_stock_operational' => false,
        ];
    }
}
