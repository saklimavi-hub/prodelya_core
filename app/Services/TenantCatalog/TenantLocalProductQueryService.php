<?php

namespace App\Services\TenantCatalog;

use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TenantLocalProductQueryService
{
    public function __construct(
        private readonly TenantCatalogProductSourceResolver $sourceResolver,
    ) {
    }

    public function ownProductsForTenant(TenantAccount $tenant, array $filters, Request $request): LengthAwarePaginator
    {
        $products = $this->baseProductsForTenant($tenant)
            ->filter(fn (TenantCatalogProduct $product) => $this->sourceResolver->resolve($tenant, $product)['source_type'] === TenantCatalogProductSourceResolver::OWN_PRODUCT);

        return $this->paginate(
            $this->decorateAndFilterCollection($tenant, $products, $filters, true),
            $filters,
            $request
        );
    }

    public function supplierLocalStockProductsForTenant(TenantAccount $tenant, array $filters, Request $request): LengthAwarePaginator
    {
        $snapshot = $this->supplierLocalStockSnapshot($tenant);

        return $this->paginate(
            $this->filterSupplierLocalStockRows($snapshot['rows'], $filters),
            $filters,
            $request
        );
    }

    public function ownProductStats(TenantAccount $tenant): array
    {
        $products = $this->decorateBaseCollection(
            $tenant,
            $this->baseProductsForTenant($tenant)
                ->filter(fn (TenantCatalogProduct $product) => $this->sourceResolver->resolve($tenant, $product)['source_type'] === TenantCatalogProductSourceResolver::OWN_PRODUCT)
        );

        return [
            'total' => $products->count(),
            'in_stock' => $products->filter(fn (TenantCatalogProduct $product) => (float) ($product->display_local_stock_quantity ?? 0) > 0)->count(),
            'visible' => $products->where('visible_in_catalog', true)->count(),
            'inactive' => $products->where('is_active', false)->count(),
        ];
    }

    public function supplierLocalStockStats(TenantAccount $tenant): array
    {
        $snapshot = $this->supplierLocalStockSnapshot($tenant);
        $rows = $snapshot['rows'];

        return [
            'total' => $rows->count(),
            'quantity_on_hand' => round((float) $rows->sum('quantity_on_hand'), 4),
            'quantity_reserved' => round((float) $rows->sum('quantity_reserved'), 4),
            'quantity_available' => round((float) $rows->sum('quantity_available'), 4),
            'legacy_unassigned_count' => $snapshot['legacy_unassigned_count'],
            'legacy_unassigned_quantity' => $snapshot['legacy_unassigned_quantity'],
        ];
    }

    private function baseProductsForTenant(TenantAccount $tenant): Collection
    {
        $allowedSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->all();
        $tenantHasAnyAccessRule = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->exists();

        $products = TenantCatalogProduct::query()
            ->with(['category', 'localStocks'])
            ->where('tenant_account_id', $tenant->id)
            ->latest('updated_at')
            ->get();

        $movementDates = StockMovement::query()
            ->selectRaw('tenant_catalog_product_id, MAX(created_at) as last_stock_movement_at')
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('tenant_catalog_product_id', $products->pluck('id')->all())
            ->groupBy('tenant_catalog_product_id')
            ->pluck('last_stock_movement_at', 'tenant_catalog_product_id');

        return $products
            ->filter(fn (TenantCatalogProduct $product) => $this->productIsVisibleForTenant($product, $allowedSupplierIds, $tenantHasAnyAccessRule))
            ->values()
            ->map(function (TenantCatalogProduct $product) use ($tenant, $movementDates) {
                $this->decorateProduct($tenant, $product);
                $product->setAttribute('last_stock_movement_at', $movementDates->get($product->id));

                return $product;
            });
    }

    private function decorateAndFilterCollection(
        TenantAccount $tenant,
        Collection $products,
        array $filters,
        bool $useDisplayLocalStock,
    ): Collection {
        return $this->decorateBaseCollection($tenant, $products)
            ->filter(function (TenantCatalogProduct $product) use ($filters, $useDisplayLocalStock) {
                if (($filters['search'] ?? '') !== '') {
                    $haystack = str(implode(' ', array_filter([
                        $product->display_name,
                        $product->display_code,
                        $product->supplier_label,
                        $product->category_display_name,
                    ])))->lower()->toString();

                    if (!str_contains($haystack, str((string) $filters['search'])->lower()->toString())) {
                        return false;
                    }
                }

                if (($filters['stock_state'] ?? '') === 'in_stock') {
                    $value = $useDisplayLocalStock
                        ? (float) ($product->display_local_stock_quantity ?? 0)
                        : (float) ($product->operational_quantity_on_hand ?? 0);

                    return $value > 0;
                }

                if (($filters['stock_state'] ?? '') === 'out_of_stock') {
                    $value = $useDisplayLocalStock
                        ? (float) ($product->display_local_stock_quantity ?? 0)
                        : (float) ($product->operational_quantity_on_hand ?? 0);

                    return $value <= 0;
                }

                return true;
            })
            ->values();
    }

    private function decorateBaseCollection(TenantAccount $tenant, Collection $products): Collection
    {
        return $products->map(function (TenantCatalogProduct $product) use ($tenant) {
            $this->decorateProduct($tenant, $product);

            return $product;
        });
    }

    private function decorateProduct(TenantAccount $tenant, TenantCatalogProduct $product): void
    {
        $resolved = $this->sourceResolver->resolve($tenant, $product);
        $tenantLocalStocks = collect($product->localStocks ?? [])
            ->filter(fn ($row) => (int) $row->tenant_account_id === (int) $tenant->id);

        $supplierId = $this->firstSupplierId($product);
        $supplierLabel = $supplierId
            ? Supplier::query()->whereKey($supplierId)->value('name')
            : ($this->sourceResolver->isOwnProduct($product) ? 'Kendi Ürünüm' : '-');

        $onHand = round((float) $tenantLocalStocks->sum(fn ($row) => (float) $row->quantity_on_hand), 4);
        $reserved = round((float) $tenantLocalStocks->sum(fn ($row) => (float) $row->quantity_reserved), 4);
        $available = round((float) $tenantLocalStocks->sum(fn ($row) => (float) $row->quantity_available), 4);
        $displayLocalStock = $onHand > 0 ? $onHand : (float) ($product->local_stock_quantity ?? 0);

        $product->setAttribute('source_type', $resolved['source_type']);
        $product->setAttribute('source_badge_label', $resolved['badge_label']);
        $product->setAttribute('supplier_label', $supplierLabel);
        $product->setAttribute('operational_quantity_on_hand', $onHand);
        $product->setAttribute('operational_quantity_reserved', $reserved);
        $product->setAttribute('operational_quantity_available', $available);
        $product->setAttribute('operational_local_stock_exists', (bool) ($resolved['has_operational_local_stock'] ?? false));
        $product->setAttribute('display_local_stock_quantity', $displayLocalStock);
    }

    private function supplierLocalStockSnapshot(TenantAccount $tenant): array
    {
        $allowedSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->all();
        $tenantHasAnyAccessRule = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->exists();

        $stocks = TenantLocalStock::query()
            ->with([
                'catalogProduct.category',
                'catalogProduct.variants',
                'catalogVariant',
            ])
            ->where('tenant_account_id', $tenant->id)
            ->where('quantity_on_hand', '>', 0)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $movementDates = collect();

        if (Schema::hasColumn('stock_movements', 'tenant_local_stock_id')) {
            $movementDates = StockMovement::query()
                ->selectRaw('tenant_local_stock_id, MAX(created_at) as last_stock_movement_at')
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('tenant_local_stock_id', $stocks->pluck('id')->filter()->all())
                ->groupBy('tenant_local_stock_id')
                ->pluck('last_stock_movement_at', 'tenant_local_stock_id');
        }

        $rows = collect();
        $legacyUnassigned = collect();

        foreach ($stocks as $stock) {
            $product = $stock->catalogProduct;
            if (! $product instanceof TenantCatalogProduct) {
                continue;
            }

            if ($this->sourceResolver->isOwnProduct($product)) {
                continue;
            }

            if (! $this->productIsVisibleForTenant($product, $allowedSupplierIds, $tenantHasAnyAccessRule)) {
                continue;
            }

            $hasVariants = $product->variants->isNotEmpty();
            $supplierLabel = $this->supplierLabelFor($product, $stock->catalogVariant);
            $sourceBadgeLabel = $this->sourceResolver->badgeLabelFor(TenantCatalogProductSourceResolver::SUPPLIER_LOCAL_STOCK);
            $lastStockMovementAt = $movementDates->get($stock->id);

            if ($stock->tenant_catalog_product_variant_id) {
                $variant = $stock->catalogVariant;

                if (! $variant instanceof TenantCatalogProductVariant) {
                    continue;
                }

                if ((int) $variant->tenant_catalog_product_id !== (int) $product->id) {
                    continue;
                }

                $rows->push((object) [
                    'row_key' => 'stock-' . $stock->id,
                    'identity_type' => 'variant',
                    'tenant_local_stock_id' => $stock->id,
                    'tenant_catalog_product_id' => $product->id,
                    'tenant_catalog_product_variant_id' => $variant->id,
                    'display_name' => $variant->display_name,
                    'sku' => $variant->variant_code ?: $product->display_code,
                    'variant_label' => $variant->variant_color ?: 'Varyant',
                    'parent_context' => $product->display_name,
                    'supplier_label' => $supplierLabel,
                    'quantity_on_hand' => round((float) $stock->quantity_on_hand, 4),
                    'quantity_reserved' => round((float) $stock->quantity_reserved, 4),
                    'quantity_available' => round((float) $stock->quantity_available, 4),
                    'last_stock_movement_at' => $lastStockMovementAt,
                    'source_badge_label' => $sourceBadgeLabel,
                    'detail_url' => route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $variant->id]),
                ]);

                continue;
            }

            if ($hasVariants) {
                $legacyUnassigned->push((object) [
                    'tenant_local_stock_id' => $stock->id,
                    'tenant_catalog_product_id' => $product->id,
                    'display_name' => $product->display_name,
                    'quantity_on_hand' => round((float) $stock->quantity_on_hand, 4),
                ]);

                continue;
            }

            $rows->push((object) [
                'row_key' => 'stock-' . $stock->id,
                'identity_type' => 'product',
                'tenant_local_stock_id' => $stock->id,
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => null,
                'display_name' => $product->display_name,
                'sku' => $product->display_code,
                'variant_label' => null,
                'parent_context' => null,
                'supplier_label' => $supplierLabel,
                'quantity_on_hand' => round((float) $stock->quantity_on_hand, 4),
                'quantity_reserved' => round((float) $stock->quantity_reserved, 4),
                'quantity_available' => round((float) $stock->quantity_available, 4),
                'last_stock_movement_at' => $lastStockMovementAt,
                'source_badge_label' => $sourceBadgeLabel,
                'detail_url' => route('admin.catalog.show', $product),
            ]);
        }

        return [
            'rows' => $rows
                ->unique(fn ($row) => (string) $row->row_key)
                ->sortBy([
                    fn ($row) => $row->display_name,
                    fn ($row) => $row->sku,
                ])
                ->values(),
            'legacy_unassigned_count' => $legacyUnassigned->count(),
            'legacy_unassigned_quantity' => round((float) $legacyUnassigned->sum('quantity_on_hand'), 4),
        ];
    }

    private function filterSupplierLocalStockRows(Collection $rows, array $filters): Collection
    {
        return $rows
            ->filter(function (object $row) use ($filters) {
                if (($filters['search'] ?? '') !== '') {
                    $haystack = str(implode(' ', array_filter([
                        $row->display_name,
                        $row->sku,
                        $row->supplier_label,
                        $row->variant_label,
                        $row->parent_context,
                    ])))->lower()->toString();

                    if (! str_contains($haystack, str((string) $filters['search'])->lower()->toString())) {
                        return false;
                    }
                }

                if (($filters['stock_state'] ?? '') === 'in_stock') {
                    return (float) $row->quantity_on_hand > 0;
                }

                if (($filters['stock_state'] ?? '') === 'out_of_stock') {
                    return (float) $row->quantity_on_hand <= 0;
                }

                return true;
            })
            ->values();
    }

    private function supplierLabelFor(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): string
    {
        $supplierId = $this->firstSupplierId($product, $variant);

        if (! $supplierId) {
            return '-';
        }

        return Supplier::query()->whereKey($supplierId)->value('name') ?: '-';
    }

    private function firstSupplierId(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): ?int
    {
        return collect([
            ...collect((array) $variant?->source_summary)->pluck('supplier_id')->all(),
            ...collect((array) $product->source_summary)->pluck('supplier_id')->all(),
            data_get($variant?->source_summary, 'supplier_id'),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->first();
    }

    private function productIsVisibleForTenant(TenantCatalogProduct $product, array $allowedSupplierIds, bool $tenantHasAnyAccessRule): bool
    {
        if ($this->sourceResolver->isOwnProduct($product)) {
            return true;
        }

        $supplierIds = collect($product->source_summary ?? [])->pluck('supplier_id')->filter()->unique()->values()->all();

        if ($supplierIds === []) {
            return true;
        }

        if (! $tenantHasAnyAccessRule) {
            return true;
        }

        if ($allowedSupplierIds === []) {
            return false;
        }

        return collect($supplierIds)->intersect($allowedSupplierIds)->isNotEmpty();
    }

    private function paginate(Collection $items, array $filters, Request $request): LengthAwarePaginator
    {
        $limit = $filters['limit'] ?? 50;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = $limit === 'all' ? max($items->count(), 1) : (int) $limit;
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return (new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        ))->appends($request->query());
    }
}
