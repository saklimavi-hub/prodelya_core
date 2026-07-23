<?php

namespace App\Services\TenantCatalog;

use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;

class TenantCatalogProductSourceResolver
{
    public const OWN_PRODUCT = 'own_product';
    public const SUPPLIER_LOCAL_STOCK = 'supplier_local_stock';
    public const SUPPLIER_CATALOG = 'supplier_catalog';

    public function resolve(
        TenantAccount|int $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
    ): array {
        if ($this->isOwnProduct($product)) {
            return [
                'source_type' => self::OWN_PRODUCT,
                'badge_label' => $this->badgeLabelFor(self::OWN_PRODUCT),
                'has_operational_local_stock' => false,
            ];
        }

        $hasOperationalLocalStock = $this->hasOperationalLocalStock($tenant, $product, $variant);

        if ($this->hasSupplierIdentity($product, $variant) && $hasOperationalLocalStock) {
            return [
                'source_type' => self::SUPPLIER_LOCAL_STOCK,
                'badge_label' => $this->badgeLabelFor(self::SUPPLIER_LOCAL_STOCK),
                'has_operational_local_stock' => true,
            ];
        }

        return [
            'source_type' => self::SUPPLIER_CATALOG,
            'badge_label' => $this->badgeLabelFor(self::SUPPLIER_CATALOG),
            'has_operational_local_stock' => false,
        ];
    }

    public function badgeLabelFor(string $sourceType): string
    {
        return match ($sourceType) {
            self::OWN_PRODUCT => 'Kendi Ürünüm',
            self::SUPPLIER_LOCAL_STOCK => 'Tedarikçiden Stoğa Alınan',
            default => 'Tedarikçi Ürünü',
        };
    }

    public function isOwnProduct(TenantCatalogProduct $product): bool
    {
        return $product->catalog_source === 'local_product'
            || data_get($product->meta, 'catalog_source') === 'local_product';
    }

    private function hasSupplierIdentity(
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
    ): bool {
        $supplierIds = collect([
            ...collect((array) $product->source_summary)->pluck('supplier_id')->all(),
            ...collect((array) $variant?->source_summary)->pluck('supplier_id')->all(),
            data_get($variant?->source_summary, 'supplier_id'),
        ])
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values();

        return $supplierIds->isNotEmpty();
    }

    private function hasOperationalLocalStock(
        TenantAccount|int $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant = null,
    ): bool {
        $tenantId = $tenant instanceof TenantAccount ? $tenant->id : $tenant;

        if ($product->relationLoaded('localStocks')) {
            $rows = $product->localStocks
                ->filter(fn (TenantLocalStock $row) => (int) $row->tenant_account_id === (int) $tenantId);

            if ($variant !== null) {
                $rows = $rows->where('tenant_catalog_product_variant_id', $variant->id);
            }

            return $rows->contains(fn (TenantLocalStock $row) => (float) $row->quantity_on_hand > 0);
        }

        return $product->localStocks()
            ->where('tenant_account_id', $tenantId)
            ->when(
                $variant !== null,
                fn ($query) => $query->where('tenant_catalog_product_variant_id', $variant->id)
            )
            ->where('quantity_on_hand', '>', 0)
            ->exists();
    }
}
