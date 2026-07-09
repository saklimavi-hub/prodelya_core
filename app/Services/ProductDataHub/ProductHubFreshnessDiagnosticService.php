<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductHubFreshnessDiagnosticService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly ProductHubSellableTruthService $sellableTruthService,
    ) {
    }

    public function enrichRows(Collection $rows): array
    {
        $context = $this->buildContext($rows);
        $enriched = $rows->map(fn (array $row) => $this->enrichRow($row, $context))->values();

        return [
            'rows' => $enriched,
            'summary' => $this->buildSummary($enriched),
        ];
    }

    public function summarizeRows(Collection $rows): array
    {
        return $this->buildSummary($rows);
    }

    private function buildContext(Collection $rows): array
    {
        $productIds = $rows->pluck('standard_product_id')->filter()->unique()->values();
        $variantIds = $rows->pluck('standard_product_variant_id')->filter()->unique()->values();

        $standardProducts = StandardProduct::query()
            ->with(['rawProducts', 'tenantCatalogProducts.variants'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $standardVariants = StandardProductVariant::query()
            ->with(['rawVariants', 'tenantCatalogVariants.catalogProduct'])
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $catalogProducts = TenantCatalogProduct::query()
            ->whereIn('standard_product_id', $productIds)
            ->get()
            ->groupBy('standard_product_id');

        $catalogVariants = TenantCatalogProductVariant::query()
            ->whereIn('standard_product_variant_id', $variantIds)
            ->get()
            ->groupBy('standard_product_variant_id');

        $tenantIds = $catalogProducts->flatten(1)->pluck('tenant_account_id')
            ->merge($catalogVariants->flatten(1)->pluck('tenant_account_id'))
            ->filter()
            ->unique()
            ->values();

        $supplierIds = $rows->pluck('supplier_id')->filter()->unique()->values();

        $tenantAccess = TenantSupplierAccess::query()
            ->whereIn('tenant_account_id', $tenantIds)
            ->whereIn('supplier_id', $supplierIds)
            ->get()
            ->keyBy(fn (TenantSupplierAccess $access) => $this->accessKey((int) $access->tenant_account_id, (int) $access->supplier_id));

        return [
            'products' => $standardProducts,
            'variants' => $standardVariants,
            'catalogProducts' => $catalogProducts,
            'catalogVariants' => $catalogVariants,
            'tenantAccess' => $tenantAccess,
        ];
    }

    private function enrichRow(array $row, array $context): array
    {
        $rowType = (string) ($row['row_type'] ?? 'flat');
        $productId = (int) ($row['standard_product_id'] ?? 0);
        $variantId = (int) ($row['standard_product_variant_id'] ?? 0);
        $supplierId = (int) ($row['supplier_id'] ?? 0);

        /** @var StandardProduct|null $standardProduct */
        $standardProduct = $context['products']->get($productId);
        /** @var StandardProductVariant|null $standardVariant */
        $standardVariant = $variantId > 0 ? $context['variants']->get($variantId) : null;

        $rawSnapshot = $rowType === 'variant'
            ? $this->variantRawSnapshot($standardVariant, $standardProduct)
            : $this->productRawSnapshot($standardProduct);

        $standardSnapshot = $rowType === 'variant'
            ? $this->variantStandardSnapshot($standardVariant, $standardProduct)
            : $this->productStandardSnapshot($standardProduct);

        $projectionRows = $rowType === 'variant'
            ? collect($context['catalogVariants']->get($variantId, collect()))
            : collect($context['catalogProducts']->get($productId, collect()));

        $projectionSnapshot = $this->projectionSnapshot($projectionRows, $supplierId, $context['tenantAccess'], $rowType);
        $quoteSnapshot = $this->quoteSnapshot($projectionRows, $supplierId, $context['tenantAccess'], $rowType);
        $sellableTruth = $this->resolveSellableTruth($rowType, $projectionRows);

        $badgeKeys = $this->resolveBadgeKeys($rowType, $rawSnapshot, $standardSnapshot, $projectionSnapshot, $quoteSnapshot);
        $badgeList = collect($badgeKeys)->map(fn (string $key) => $this->badgePayload($key))->values()->all();
        $operationState = $this->resolveOperationState($row, $badgeKeys, $projectionSnapshot, $quoteSnapshot);

        return array_merge($row, [
            'raw_snapshot' => $rawSnapshot,
            'standard_snapshot' => $standardSnapshot,
            'projection_snapshot' => $projectionSnapshot,
            'quote_snapshot' => $quoteSnapshot,
            'supplier_access_summary' => [
                'open_count' => (int) ($projectionSnapshot['access_open_count'] ?? 0),
                'closed_count' => (int) ($projectionSnapshot['access_closed_count'] ?? 0),
                'label' => $this->accessSummaryLabel($projectionSnapshot),
            ],
            'projection_visibility_label' => $this->projectionVisibilityLabel($projectionSnapshot),
            'quote_visibility_label' => $this->quoteVisibilityLabel($quoteSnapshot),
            'sellable_truth' => $sellableTruth,
            'sellable_state_key' => $this->sellableStateKey($rowType),
            'diagnostic_badge_keys' => $badgeKeys,
            'diagnostic_badges' => $badgeList,
            'is_quote_visible' => (bool) ($quoteSnapshot['visible'] ?? false),
            'has_stale_price' => in_array('stale_price', $badgeKeys, true),
            'has_stale_stock' => in_array('stale_stock', $badgeKeys, true),
            'operation_state_key' => $operationState['key'],
            'operation_state_label' => $operationState['label'],
            'operation_state_tone' => $operationState['tone'],
        ]);
    }

    private function resolveSellableTruth(string $rowType, Collection $projectionRows): array
    {
        if ($projectionRows->isEmpty()) {
            return [
                'effective_price' => null,
                'effective_stock' => 0.0,
                'effective_currency' => 'TL',
                'source_layer' => $rowType === 'variant' ? 'tenant_catalog_product_variants' : 'tenant_catalog_products',
                'is_stale' => false,
                'stale_reason' => null,
                'category_status' => 'missing',
                'quote_visibility_status' => 'hidden',
                'tenant_catalog_status' => 'missing',
                'tenant_price_override' => false,
            ];
        }

        $preferredRow = $projectionRows->first(function ($row) use ($rowType) {
            if ($rowType === 'variant') {
                return (bool) ($row->visible_in_catalog ?? false)
                    && (bool) ($row->is_active ?? false)
                    && ((bool) data_get($row->meta, 'quote_search_visible', optional($row->catalogProduct)->visible_in_quote));
            }

            return (bool) ($row->visible_in_catalog ?? false) && (bool) ($row->is_active ?? false);
        }) ?: $projectionRows->first();

        if ($rowType === 'variant') {
            return $this->sellableTruthService->resolve($preferredRow->catalogProduct, $preferredRow);
        }

        return $this->sellableTruthService->resolve($preferredRow);
    }

    private function productRawSnapshot(?StandardProduct $product): array
    {
        $raw = $product?->rawProducts?->sortByDesc('updated_at')->first();

        return [
            'price' => $this->toFloat(data_get($raw, 'normalized_payload.list_price', $raw?->purchase_price)),
            'stock' => $this->toFloat(data_get($raw, 'normalized_payload.stock_quantity', $raw?->stock_quantity)),
            'updated_at' => $raw?->updated_at,
            'supplier_product_code' => $raw?->supplier_product_code,
            'supplier_variant_code' => null,
        ];
    }

    private function variantRawSnapshot(?StandardProductVariant $variant, ?StandardProduct $product): array
    {
        $raw = $variant?->rawVariants?->sortByDesc('updated_at')->first();

        return [
            'price' => $this->toFloat(data_get($raw, 'normalized_payload.list_price', data_get($variant?->source_summary, 'list_price', data_get($product?->source_summary, '0.list_price')))),
            'stock' => $this->toFloat(data_get($raw, 'normalized_payload.variant_stock_quantity', $raw?->variant_stock_quantity)),
            'updated_at' => $raw?->updated_at,
            'supplier_product_code' => data_get($raw, 'rawProduct.supplier_product_code', data_get($product?->source_summary, '0.supplier_product_code')),
            'supplier_variant_code' => $raw?->variant_stock_code ?: $raw?->variant_code,
        ];
    }

    private function productStandardSnapshot(?StandardProduct $product): array
    {
        return [
            'price' => $this->toFloat($product?->min_purchase_price),
            'stock' => $this->toFloat($product?->total_stock_quantity),
            'updated_at' => $product?->updated_at,
            'display_code' => $product?->standard_product_code ?: $product?->sku,
        ];
    }

    private function variantStandardSnapshot(?StandardProductVariant $variant, ?StandardProduct $product): array
    {
        return [
            'price' => $this->toFloat($variant?->min_purchase_price ?? $product?->min_purchase_price),
            'stock' => $this->toFloat($variant?->stock_quantity ?? $product?->total_stock_quantity),
            'updated_at' => $variant?->updated_at ?? $product?->updated_at,
            'display_code' => $variant?->generated_variant_code ?: $variant?->variant_code ?: $product?->standard_product_code,
        ];
    }

    private function projectionSnapshot(Collection $rows, int $supplierId, Collection $accessMap, string $rowType): array
    {
        $prices = $rows->pluck('display_price')->map(fn ($value) => $this->toFloat($value))->filter(fn ($value) => $value !== null)->unique()->values();
        $stocks = $rows->map(function ($row) {
            return $this->toFloat($row->stock_quantity ?? $row->total_stock_quantity ?? null);
        })->filter(fn ($value) => $value !== null)->unique()->values();
        $latestUpdatedAt = $rows->max('updated_at');

        $openCount = 0;
        $closedCount = 0;

        foreach ($rows as $projectionRow) {
            $access = $accessMap->get($this->accessKey((int) $projectionRow->tenant_account_id, $supplierId));
            if ($this->accessAllowsCatalog($access)) {
                $openCount++;
            } else {
                $closedCount++;
            }
        }

        return [
            'count' => $rows->count(),
            'price' => $prices->count() === 1 ? $prices->first() : null,
            'stock' => $stocks->count() === 1 ? $stocks->first() : null,
            'price_values' => $prices->all(),
            'stock_values' => $stocks->all(),
            'price_label' => $this->valueListLabel($prices, true),
            'stock_label' => $this->valueListLabel($stocks, false),
            'updated_at' => $latestUpdatedAt ? Carbon::parse($latestUpdatedAt) : null,
            'access_open_count' => $openCount,
            'access_closed_count' => $closedCount,
            'visible_count' => $rows->where('visible_in_catalog', true)->count(),
            'active_count' => $rows->where('is_active', true)->count(),
            'row_type' => $rowType,
        ];
    }

    private function quoteSnapshot(Collection $rows, int $supplierId, Collection $accessMap, string $rowType): array
    {
        $eligibleRows = $rows->filter(function ($row) use ($supplierId, $accessMap, $rowType) {
            $access = $accessMap->get($this->accessKey((int) $row->tenant_account_id, $supplierId));

            if (!$this->accessAllowsCatalog($access)) {
                return false;
            }

            if (!($row->visible_in_catalog ?? false) || !($row->is_active ?? false)) {
                return false;
            }

            if ($rowType === 'parent') {
                return false;
            }

            if ($rowType === 'variant') {
                return data_get($row->meta, 'quote_search_visible') !== null
                    ? (bool) data_get($row->meta, 'quote_search_visible')
                    : (bool) optional($row->catalogProduct)->visible_in_quote;
            }

            return (bool) ($row->visible_in_quote ?? false);
        })->values();

        $prices = $eligibleRows->pluck('display_price')
            ->map(fn ($value) => $this->toFloat($value))
            ->filter(fn ($value) => $value !== null)
            ->unique()
            ->values();

        return [
            'visible' => $eligibleRows->isNotEmpty(),
            'count' => $eligibleRows->count(),
            'price' => $prices->count() === 1 ? $prices->first() : null,
            'price_values' => $prices->all(),
            'price_label' => $this->valueListLabel($prices, true),
        ];
    }

    private function resolveBadgeKeys(string $rowType, array $raw, array $standard, array $projection, array $quote): array
    {
        $keys = [];

        $keys[] = $this->sellableStateKey($rowType);

        if (!$quote['visible']) {
            $keys[] = 'not_quote_visible';
        }

        if (($projection['access_closed_count'] ?? 0) > 0) {
            $keys[] = 'supplier_access_closed';
        }

        if ($this->hasMismatch([$raw['price'], $standard['price'], $projection['price'], $quote['price']])) {
            $keys[] = 'stale_price';
        }

        if ($this->hasMismatch([$raw['stock'], $standard['stock'], $projection['stock']])) {
            $keys[] = 'stale_stock';
        }

        if (
            $standard['updated_at']
            && ($projection['count'] ?? 0) > 0
            && (
                !$projection['updated_at']
                || $projection['updated_at']->lte($standard['updated_at'])
            )
            && (
                $this->hasMismatch([$raw['price'], $standard['price'], $projection['price']])
                || $this->hasMismatch([$raw['stock'], $standard['stock'], $projection['stock']])
            )
        ) {
            $keys[] = 'projection_outdated';
        }

        if (
            $raw['updated_at']
            && (
                !$standard['updated_at']
                || $standard['updated_at']->lte($raw['updated_at'])
            )
            && (
                $this->valuesDiffer($raw['price'], $standard['price'])
                || $this->valuesDiffer($raw['stock'], $standard['stock'])
            )
        ) {
            $keys[] = 'standard_variant_outdated';
        }

        if ($quote['visible'] && $this->valuesDiffer($projection['price'], $quote['price'])) {
            $keys[] = 'quote_price_outdated';
        }

        return array_values(array_unique($keys));
    }

    private function buildSummary(Collection $rows): array
    {
        return [
            'total_rows' => $rows->count(),
            'sellable_variant' => $rows->filter(fn (array $row) => ($row['sellable_state_key'] ?? null) === 'sellable_variant')->count(),
            'sellable_flat' => $rows->filter(fn (array $row) => ($row['sellable_state_key'] ?? null) === 'sellable_flat')->count(),
            'parent_only' => $rows->filter(fn (array $row) => ($row['sellable_state_key'] ?? null) === 'parent_only')->count(),
            'stale_price' => $rows->filter(fn (array $row) => in_array('stale_price', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'stale_stock' => $rows->filter(fn (array $row) => in_array('stale_stock', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'projection_outdated' => $rows->filter(fn (array $row) => in_array('projection_outdated', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'standard_variant_outdated' => $rows->filter(fn (array $row) => in_array('standard_variant_outdated', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'supplier_access_closed' => $rows->filter(fn (array $row) => in_array('supplier_access_closed', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'quote_visible' => $rows->filter(fn (array $row) => (bool) ($row['is_quote_visible'] ?? false))->count(),
            'not_quote_visible' => $rows->filter(fn (array $row) => in_array('not_quote_visible', $row['diagnostic_badge_keys'] ?? [], true))->count(),
            'auto_updated' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'auto_updated')->count(),
            'review_required' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'review_required')->count(),
            'category_waiting' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'category_waiting')->count(),
            'projection_lagging' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'projection_lagging')->count(),
            'tenant_output_closed' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'tenant_output_closed')->count(),
            'technical_parent' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'technical_parent')->count(),
        ];
    }

    private function resolveOperationState(array $row, array $badgeKeys, array $projection, array $quote): array
    {
        if (($row['sellable_state_key'] ?? null) === 'parent_only') {
            return ['key' => 'technical_parent', 'label' => 'Teknik grup satırı', 'tone' => 'purple'];
        }

        if (in_array('supplier_access_closed', $badgeKeys, true)) {
            return ['key' => 'tenant_output_closed', 'label' => 'Tenant çıkışı kapalı', 'tone' => 'red'];
        }

        if (($row['category_status'] ?? null) !== 'Eşleşmiş' || (bool) ($row['category_action_required'] ?? false)) {
            return ['key' => 'category_waiting', 'label' => 'Kategori eşleşmemiş', 'tone' => 'amber'];
        }

        if (
            in_array('projection_outdated', $badgeKeys, true)
            || ((int) ($projection['count'] ?? 0) === 0 && ($row['sellable_state_key'] ?? null) !== 'parent_only')
        ) {
            return ['key' => 'projection_lagging', 'label' => 'Katalog yansıması geri kalmış', 'tone' => 'red'];
        }

        if (
            in_array('standard_variant_outdated', $badgeKeys, true)
            || in_array('stale_price', $badgeKeys, true)
            || in_array('stale_stock', $badgeKeys, true)
            || in_array('quote_price_outdated', $badgeKeys, true)
            || (!(bool) ($quote['visible'] ?? false) && ($row['sellable_state_key'] ?? null) !== 'parent_only')
        ) {
            return ['key' => 'review_required', 'label' => 'İnceleme bekliyor', 'tone' => 'amber'];
        }

        return ['key' => 'auto_updated', 'label' => 'Otomatik güncellendi', 'tone' => 'green'];
    }

    public function badgePayload(string $key): array
    {
        return match ($key) {
            'stale_price' => ['key' => $key, 'label' => 'Katalog Fiyatı Eski', 'tone' => 'amber'],
            'stale_stock' => ['key' => $key, 'label' => 'Katalog Stoğu Eski', 'tone' => 'amber'],
            'projection_outdated' => ['key' => $key, 'label' => 'Katalog yansıması eski', 'tone' => 'red'],
            'standard_variant_outdated' => ['key' => $key, 'label' => 'Varyant Eşleşmesi Kontrol', 'tone' => 'red'],
            'quote_price_outdated' => ['key' => $key, 'label' => 'Teklif fiyatı eski olabilir', 'tone' => 'amber'],
            'supplier_access_closed' => ['key' => $key, 'label' => 'Tedarikçi erişimi kapalı', 'tone' => 'red'],
            'not_quote_visible' => ['key' => $key, 'label' => 'Teklifte Kapalı', 'tone' => 'blue'],
            'parent_only' => ['key' => $key, 'label' => 'Grup / parent', 'tone' => 'purple'],
            'sellable_variant' => ['key' => $key, 'label' => 'Satılabilir varyant', 'tone' => 'green'],
            'sellable_flat' => ['key' => $key, 'label' => 'Satılabilir ürün', 'tone' => 'green'],
            default => ['key' => $key, 'label' => $key, 'tone' => 'light'],
        };
    }

    private function sellableStateKey(string $rowType): string
    {
        return match ($rowType) {
            'variant' => 'sellable_variant',
            'flat' => 'sellable_flat',
            default => 'parent_only',
        };
    }

    private function hasMismatch(array $values): bool
    {
        $values = collect($values)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => round((float) $value, 4))
            ->unique()
            ->values();

        return $values->count() > 1;
    }

    private function valuesDiffer(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) > self::EPSILON;
    }

    private function valueListLabel(Collection $values, bool $isPrice): string
    {
        if ($values->isEmpty()) {
            return '-';
        }

        if ($values->count() === 1) {
            return $isPrice
                ? number_format((float) $values->first(), 2, ',', '.')
                : number_format((float) $values->first(), 0, ',', '.');
        }

        return $values->map(function ($value) use ($isPrice) {
            return $isPrice
                ? number_format((float) $value, 2, ',', '.')
                : number_format((float) $value, 0, ',', '.');
        })->implode(' / ');
    }

    private function accessAllowsCatalog(?TenantSupplierAccess $access): bool
    {
        if (!$access) {
            return false;
        }

        return $access->isCurrentlyAccessible()
            && (bool) $access->can_view_products
            && (bool) $access->visible_in_catalog;
    }

    private function accessKey(int $tenantId, int $supplierId): string
    {
        return $tenantId . ':' . $supplierId;
    }

    private function accessSummaryLabel(array $projectionSnapshot): string
    {
        $open = (int) ($projectionSnapshot['access_open_count'] ?? 0);
        $closed = (int) ($projectionSnapshot['access_closed_count'] ?? 0);

        if ($open === 0 && $closed === 0) {
            return 'Projection yok';
        }

        if ($closed === 0) {
            return 'Açık (' . $open . ')';
        }

        if ($open === 0) {
            return 'Kapalı (' . $closed . ')';
        }

        return 'Açık ' . $open . ' / Kapalı ' . $closed;
    }

    private function projectionVisibilityLabel(array $projectionSnapshot): string
    {
        $count = (int) ($projectionSnapshot['count'] ?? 0);
        if ($count === 0) {
            return 'Projection yok';
        }

        $visible = (int) ($projectionSnapshot['visible_count'] ?? 0);

        return $visible > 0
            ? 'Katalogda ' . $visible . ' tenant'
            : 'Katalogta kapalı';
    }

    private function quoteVisibilityLabel(array $quoteSnapshot): string
    {
        if (!($quoteSnapshot['visible'] ?? false)) {
            return 'Teklifte görünmez';
        }

        return 'Teklifte ' . ((int) ($quoteSnapshot['count'] ?? 0)) . ' tenant';
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
