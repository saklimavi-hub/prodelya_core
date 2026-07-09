<?php

namespace App\Services\ProductDataHub;

use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;

class ProductHubLiveProductInfoService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly ProductHubSellableTruthService $sellableTruthService,
    ) {
    }

    public function resolve(TenantAccount $tenant, array $input): array
    {
        $validation = $this->validateInput($input);

        if ($validation !== null) {
            return $validation;
        }

        $catalogProductId = $this->toNullableInt($input['tenant_catalog_product_id'] ?? null);
        $catalogVariantId = $this->toNullableInt($input['tenant_catalog_product_variant_id'] ?? null);
        $quoteItemId = $this->toNullableInt($input['quote_item_id'] ?? null);

        $quoteItem = $quoteItemId
            ? OrderItem::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereKey($quoteItemId)
                ->first()
            : null;

        if ($quoteItemId && !$quoteItem) {
            return $this->notFoundResponse();
        }

        $variant = $catalogVariantId
            ? TenantCatalogProductVariant::query()
                ->with(['catalogProduct.standardProduct', 'standardVariant'])
                ->where('tenant_account_id', $tenant->id)
                ->whereKey($catalogVariantId)
                ->first()
            : null;

        $product = $variant?->catalogProduct;

        if ($catalogProductId) {
            $product = $product ?: TenantCatalogProduct::query()
                ->with(['standardProduct', 'variants.standardVariant'])
                ->where('tenant_account_id', $tenant->id)
                ->whereKey($catalogProductId)
                ->first();
        }

        if (!$product) {
            return $this->notFoundResponse();
        }

        if ($variant && (int) $variant->tenant_catalog_product_id !== (int) $product->id) {
            return $this->notFoundResponse();
        }

        if ($quoteItem && !$this->quoteItemMatchesSelection($quoteItem, $product, $variant)) {
            return $this->notFoundResponse();
        }

        $truth = $this->sellableTruthService->resolve($product, $variant);
        $supplierAccess = $this->resolveSupplierAccessState($tenant, $product, $variant);
        $quoteVisible = ($truth['quote_visibility_status'] ?? 'hidden') === 'visible';
        $tenantCatalogStatus = (string) ($truth['tenant_catalog_status'] ?? 'inactive');
        $tenantCatalogActive = $this->tenantCatalogStatusAllowsQuoteSelection($tenantCatalogStatus);
        $currentPriceValue = $this->toFloat($truth['effective_price'] ?? null);
        $currentStock = $this->toFloat($truth['effective_stock'] ?? null);
        $selectionSellable = $variant ? true : (bool) $product->is_sellable;

        $warnings = [];
        $stockWarning = null;
        $productInactiveWarning = null;

        if (!$supplierAccess['active']) {
            $warnings[] = 'Abone Firma bu tedarikçiye erişemiyor.';
        }

        if (!$tenantCatalogActive) {
            $productInactiveWarning = match ($tenantCatalogStatus) {
                'hidden' => 'Ürün satış listesinde kapalı.',
                default => 'Bu ürün şu anda aktif değil.',
            };
            $warnings[] = $productInactiveWarning;
        }

        if (!$quoteVisible) {
            $warnings[] = 'Ürün teklifte kullanıma kapalı.';
        }

        if (!$selectionSellable) {
            $warnings[] = 'Ürün seçilebilir satış satırı olarak hazır değil.';
        }

        if (($truth['category_status'] ?? null) === 'category_waiting' || $tenantCatalogStatus === 'category_pending') {
            $warnings[] = 'Kategori eşleşmemiş.';
            $warnings[] = 'Genel kategori henüz bağlanmadı.';
        }

        if ($currentPriceValue === null) {
            $warnings[] = 'Bu ürünün güncel fiyat bilgisi eksik.';
        }

        if ($currentStock === null) {
            $stockWarning = 'Stok bilgisi şu anda okunamıyor.';
            $warnings[] = $stockWarning;
        } elseif ($currentStock <= 0) {
            $stockWarning = 'Bu ürün için satışta kullanılabilir stok görünmüyor.';
            $warnings[] = $stockWarning;
        }

        $snapshotPrice = $this->resolveSnapshotPrice($input, $quoteItem);
        $snapshotStock = $this->resolveSnapshotStock($input, $quoteItem);
        $priceChanged = $this->valuesDiffer($snapshotPrice, $currentPriceValue);
        $stockChanged = $this->valuesDiffer($snapshotStock, $currentStock);

        if ($priceChanged) {
            $warnings[] = 'Bu ürünün güncel fiyatı teklif satırındaki fiyattan farklı.';
        }

        if ($stockChanged) {
            $warnings[] = 'Stok bilgisi değişmiş olabilir.';
        }

        $warnings = array_values(array_unique(array_filter($warnings)));
        $isSellable = $selectionSellable
            && $supplierAccess['active']
            && $tenantCatalogActive
            && $quoteVisible
            && $currentPriceValue !== null
            && ($currentStock === null || $currentStock > 0);

        $message = $this->resolvePublicMessage($isSellable, $warnings);

        return [
            'status' => 200,
            'body' => [
                'ok' => $isSellable,
                'product_name' => $product->display_name,
                'variant_name' => $variant?->display_name,
                'display_code' => $variant?->variant_code ?: $product->display_code,
                'current_stock' => $currentStock,
                'stock_label' => $this->formatStock($currentStock),
                'current_price' => $this->formatPrice($currentPriceValue, $variant?->currency ?: $product->currency ?: ($input['currency'] ?? null)),
                'current_price_value' => $currentPriceValue,
                'currency' => $variant?->currency ?: $product->currency ?: ($input['currency'] ?? 'TL'),
                'last_synced_at' => optional($product->last_synced_at)->format('Y-m-d H:i'),
                'is_sellable' => $isSellable,
                'supplier_access_active' => $supplierAccess['active'],
                'tenant_catalog_active' => $tenantCatalogActive,
                'quote_visible' => $quoteVisible,
                'stock_warning' => $stockWarning,
                'price_changed_since_snapshot' => $priceChanged,
                'stock_changed_since_snapshot' => $stockChanged,
                'product_inactive_warning' => $productInactiveWarning,
                'alternative_available' => false,
                'public_safe_message' => $message,
                'warnings' => $warnings,
            ],
        ];
    }

    private function validateInput(array $input): ?array
    {
        $productId = $input['tenant_catalog_product_id'] ?? null;
        $variantId = $input['tenant_catalog_product_variant_id'] ?? null;

        if (blank($productId) && blank($variantId)) {
            return [
                'status' => 422,
                'body' => [
                    'ok' => false,
                    'public_safe_message' => 'Ürün seçimi eksik.',
                    'warnings' => ['En az bir ürün veya varyant kimliği gönderilmelidir.'],
                    'errors' => [
                        'tenant_catalog_product_id' => ['En az bir ürün veya varyant kimliği gönderilmelidir.'],
                        'tenant_catalog_product_variant_id' => ['En az bir ürün veya varyant kimliği gönderilmelidir.'],
                    ],
                ],
            ];
        }

        foreach (['tenant_catalog_product_id', 'tenant_catalog_product_variant_id', 'quote_item_id'] as $field) {
            if (array_key_exists($field, $input) && filled($input[$field]) && $this->toNullableInt($input[$field]) === null) {
                return [
                    'status' => 422,
                    'body' => [
                        'ok' => false,
                        'public_safe_message' => 'Gönderilen ürün bilgisi doğrulanamadı.',
                        'warnings' => ['Kimlik alanları sayısal olmalıdır.'],
                        'errors' => [
                            $field => ['Kimlik alanı sayısal olmalıdır.'],
                        ],
                    ],
                ];
            }
        }

        foreach (['snapshot_price', 'snapshot_stock'] as $field) {
            if (array_key_exists($field, $input) && filled($input[$field]) && $this->toFloat($input[$field]) === null) {
                return [
                    'status' => 422,
                    'body' => [
                        'ok' => false,
                        'public_safe_message' => 'Gönderilen karşılaştırma bilgisi doğrulanamadı.',
                        'warnings' => ['Snapshot alanları sayısal olmalıdır.'],
                        'errors' => [
                            $field => ['Snapshot alanı sayısal olmalıdır.'],
                        ],
                    ],
                ];
            }
        }

        return null;
    }

    private function notFoundResponse(): array
    {
        return [
            'status' => 404,
            'body' => [
                'ok' => false,
                'public_safe_message' => 'Bu ürün bilgisi güvenli şekilde okunamadı.',
                'warnings' => ['Kayıt bulunamadı veya erişim izni yok.'],
            ],
        ];
    }

    private function quoteItemMatchesSelection(OrderItem $quoteItem, TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant): bool
    {
        if ((int) $quoteItem->tenant_catalog_product_id !== (int) $product->id) {
            return false;
        }

        if ($variant) {
            return (int) $quoteItem->tenant_catalog_product_variant_id === (int) $variant->id;
        }

        return blank($quoteItem->tenant_catalog_product_variant_id);
    }

    private function resolveSupplierAccessState(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant
    ): array {
        if ($this->isLocalProduct($product)) {
            return ['active' => true];
        }

        $supplierIds = collect([
            ...collect((array) $product->source_summary)->pluck('supplier_id')->all(),
            ...collect((array) $variant?->source_summary)->pluck('supplier_id')->all(),
            data_get($variant?->source_summary, 'supplier_id'),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($supplierIds->isEmpty()) {
            return ['active' => true];
        }

        $tenantHasRules = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->exists();

        if (!$tenantHasRules) {
            return ['active' => true];
        }

        $allowed = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('supplier_id', $supplierIds->all())
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->where('can_use_in_quotes', true)
            ->exists();

        return ['active' => $allowed];
    }

    private function isLocalProduct(TenantCatalogProduct $product): bool
    {
        return $product->catalog_source === 'local_product'
            || data_get($product->meta, 'catalog_source') === 'local_product';
    }

    private function resolveSnapshotPrice(array $input, ?OrderItem $quoteItem): ?float
    {
        $direct = $this->toFloat($input['snapshot_price'] ?? null);

        if ($direct !== null) {
            return $direct;
        }

        if (!$quoteItem) {
            return null;
        }

        return $this->toFloat(
            data_get($quoteItem->price_snapshot, 'list_price')
            ?? data_get($quoteItem->price_snapshot, 'unit_price')
            ?? $quoteItem->list_price
            ?? $quoteItem->unit_price
        );
    }

    private function resolveSnapshotStock(array $input, ?OrderItem $quoteItem): ?float
    {
        $direct = $this->toFloat($input['snapshot_stock'] ?? null);

        if ($direct !== null) {
            return $direct;
        }

        if (!$quoteItem) {
            return null;
        }

        return $this->toFloat(
            data_get($quoteItem->stock_snapshot, 'visible_stock_quantity')
            ?? data_get($quoteItem->stock_snapshot, 'stock_quantity')
            ?? data_get($quoteItem->stock_snapshot, 'total_stock_quantity')
        );
    }

    private function resolvePublicMessage(bool $isSellable, array $warnings): string
    {
        if ($isSellable && ($warnings === [] || $this->containsOnlyCategoryInfoWarnings($warnings))) {
            return 'Ürün güncel ve teklif için uygun.';
        }

        if ($isSellable) {
            return 'Ürün seçilebilir, ancak güncel durum için uyarı kontrol edilmelidir.';
        }

        return 'Bu ürün şu anda teklif için uygun değil.';
    }

    private function containsOnlyCategoryInfoWarnings(array $warnings): bool
    {
        $categoryWarnings = [
            'Kategori eşleşmemiş.',
            'Genel kategori henüz bağlanmadı.',
        ];

        $filtered = array_values(array_filter($warnings));

        return $filtered !== []
            && collect($filtered)->every(fn ($warning) => in_array((string) $warning, $categoryWarnings, true));
    }

    private function tenantCatalogStatusAllowsQuoteSelection(string $status): bool
    {
        return !in_array($status, ['inactive', 'hidden', 'local_archived', 'archived', 'deleted', 'disabled'], true);
    }

    private function formatPrice(?float $price, ?string $currency): ?string
    {
        if ($price === null) {
            return null;
        }

        return number_format($price, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    private function formatStock(?float $stock): ?string
    {
        if ($stock === null) {
            return null;
        }

        if (abs($stock - round($stock)) < self::EPSILON) {
            return number_format($stock, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($stock, 4, ',', '.'), '0'), ',');
    }

    private function valuesDiffer(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) > self::EPSILON;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
