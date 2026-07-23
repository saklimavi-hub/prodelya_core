<?php

namespace App\Services\ProductDataHub;

use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Services\PromotionQuote\QuoteCurrencyAccessService;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use App\Services\Stock\TenantLocalStockPresentationService;
use Illuminate\Contracts\Auth\Authenticatable;

class ProductHubLiveProductInfoService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly ProductHubSellableTruthService $sellableTruthService,
        private readonly ProductHubCurrencyService $productHubCurrencyService,
        private readonly TenantLocalStockPresentationService $tenantLocalStockPresentationService,
        private readonly QuoteCurrencyAccessService $quoteCurrencyAccessService,
        private readonly QuoteCurrencyPricingService $quoteCurrencyPricingService,
    ) {
    }

    public function resolve(TenantAccount $tenant, array $input, ?Authenticatable $user = null): array
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
        $requestedDate = trim((string) ($input['quote_date'] ?? now()->format('Y-m-d')));
        $quoteCurrencyAccess = $this->quoteCurrencyAccessService->build($tenant, $user);
        $requestedCurrency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $requestedCurrency = $requestedCurrency === 'TL' ? 'TRY' : $requestedCurrency;
        if (($quoteCurrencyAccess['multi_currency_enabled'] ?? false) === false) {
            $requestedCurrency = 'TRY';
        } elseif (! in_array($requestedCurrency, ['TRY', 'USD', 'EUR'], true)) {
            $requestedCurrency = null;
        } elseif (in_array($requestedCurrency, ['USD', 'EUR'], true) && ! ($quoteCurrencyAccess['can_use_foreign_document_currency'] ?? false)) {
            $requestedCurrency = 'TRY';
        }
        $documentCurrency = $this->quoteCurrencyPricingService->normalizeDocumentCurrency($tenant, $quoteCurrencyAccess, $requestedCurrency ?: null);
        $supplierAccess = $this->resolveSupplierAccessState($tenant, $product, $variant);
        $quoteVisible = ($truth['quote_visibility_status'] ?? 'hidden') === 'visible';
        $tenantCatalogStatus = (string) ($truth['tenant_catalog_status'] ?? 'inactive');
        $tenantCatalogActive = $this->tenantCatalogStatusAllowsQuoteSelection($tenantCatalogStatus);
        $currentPriceValue = $this->toFloat($truth['effective_price'] ?? null);
        $exactLocalStock = $this->toFloat($variant?->local_stock_quantity ?? $product->local_stock_quantity ?? null) ?? 0.0;
        $exactSupplierStock = $this->toFloat($variant?->supplier_stock_quantity ?? $product->supplier_stock_quantity ?? null) ?? 0.0;
        $exactFallbackStock = $this->toFloat($variant?->stock_quantity ?? $product->stock_quantity ?? $product->total_stock_quantity ?? null) ?? 0.0;
        $currentStock = $this->toFloat($this->sellableTruthService->resolveEffectiveStock(
            $exactLocalStock,
            $exactSupplierStock,
            $exactFallbackStock,
            (bool) ($product->local_stock_priority ?? true)
        ));
        $selectionSellable = $variant ? true : (bool) $product->is_sellable;
        $localStockPresentation = $this->tenantLocalStockPresentationService->forCatalogSelection($tenant, $product, $variant);

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
        $stockChanged = $this->valuesDiffer($snapshotStock, $currentStock);

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
        $priceSnapshot = (array) data_get($variant?->meta, 'price_snapshot', data_get($product->meta, 'price_snapshot', []));
        $catalogPriceSnapshot = $this->prepareCatalogPriceSnapshot(
            $tenant,
            $priceSnapshot,
            $variant?->currency ?: $product->currency,
            $variant?->display_price !== null ? (float) $variant->display_price : ($product->display_price !== null ? (float) $product->display_price : null),
            $requestedDate
        );
        $currencyPayload = $this->productHubCurrencyService->buildBrowserCurrencyPayload(
            $tenant,
            $user,
            (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot)
        );
        $quotePayload = $this->quoteCurrencyPricingService->buildQuoteDisplayPayload(
            $tenant,
            $documentCurrency,
            (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot),
            ['manual_unit_price' => false],
            $requestedDate
        );
        $quotePriceStatus = (string) ($quotePayload['quote_price_status'] ?? 'unavailable');
        $quotePriceValue = $quotePayload['quote_price_value'] ?? null;
        $quotePriceReady = $quotePriceValue !== null && in_array($quotePriceStatus, ['ready', 'not_required'], true);
        $priceComparisonValue = $quotePriceReady ? $this->toFloat($quotePriceValue) : $currentPriceValue;
        $priceChanged = $this->valuesDiffer($snapshotPrice, $priceComparisonValue);
        $manualUnitPrice = $this->toFloat($input['manual_unit_price'] ?? null);
        $manualUnitPriceCurrency = strtoupper(trim((string) ($input['manual_unit_price_currency'] ?? '')));
        $manualUnitPriceCurrency = $manualUnitPriceCurrency === 'TL' ? 'TRY' : $manualUnitPriceCurrency;
        $manualQuotePayload = $manualUnitPrice !== null
            ? $this->quoteCurrencyPricingService->convertManualDocumentPrice(
                $tenant,
                $manualUnitPrice,
                $manualUnitPriceCurrency ?: $documentCurrency,
                $documentCurrency,
                $requestedDate
            )
            : null;

        if ($priceChanged) {
            $warnings[] = 'Bu ürünün güncel fiyatı teklif satırındaki fiyattan farklı.';
        }


        $freshness = app(ProductHubFreshnessDiagnosticService::class)->buildQuoteFreshnessPayload($product, $variant);

        return [
            'status' => 200,
            'body' => [
                'ok' => $isSellable,
                'product_name' => $product->display_name,
                'variant_name' => $variant?->display_name,
                'display_code' => $variant?->variant_code ?: $product->display_code,
                'current_stock' => $currentStock,
                'stock_label' => $this->formatStock($currentStock),
                'local_stock_quantity' => $localStockPresentation['local_stock_value'],
                'local_stock_source' => $localStockPresentation['local_stock_source'],
                'local_stock_scope' => $localStockPresentation['local_stock_scope'],
                'local_stock_reason_code' => $localStockPresentation['local_stock_reason_code'],
                'local_stock_label' => $localStockPresentation['local_stock_label'],
                'local_stock_note' => $localStockPresentation['local_stock_note'],
                'local_stock_projection_quantity' => $localStockPresentation['local_stock_projection_value'],
                'local_stock_operational' => $localStockPresentation['local_stock_operational'],
                'supplier_stock_quantity' => $exactSupplierStock,
                'fallback_stock_quantity' => $exactFallbackStock,
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
                'source_price' => $currencyPayload['source_price'],
                'source_currency' => $currencyPayload['source_currency'],
                'base_price' => $currencyPayload['base_price'],
                'base_currency' => $currencyPayload['base_currency'],
                'conversion_available' => $currencyPayload['conversion_available'],
                'conversion_status' => $currencyPayload['conversion_status'],
                'applied_rate' => $currencyPayload['applied_rate'],
                'rate_date' => $currencyPayload['rate_date'],
                'rate_source' => $currencyPayload['rate_source'],
                'source_to_base_rate' => $currencyPayload['applied_rate'],
                'source_to_base_rate_date' => $currencyPayload['rate_date'],
                'source_to_base_rate_source' => $currencyPayload['rate_source'],
                'rate_type' => $currencyPayload['rate_type'],
                'is_fallback_rate' => $currencyPayload['is_fallback_rate'],
                'is_stale_rate' => $currencyPayload['is_stale_rate'],
                'currency_origin' => $currencyPayload['currency_origin'],
                'currency_status' => $currencyPayload['currency_status'],
                'multi_currency_enabled' => $currencyPayload['multi_currency_enabled'],
                'can_view_currency_details' => $currencyPayload['can_view_currency_details'],
                'can_use_foreign_document_currency' => $currencyPayload['can_use_foreign_document_currency'],
                'can_use_manual_rate' => $currencyPayload['can_use_manual_rate'],
                'quote_price_value' => $quotePayload['quote_price_value'],
                'quote_currency' => $quotePayload['quote_currency'],
                'quote_price_status' => $quotePayload['quote_price_status'],
                'quote_price_reason_code' => $quotePayload['quote_price_reason_code'],
                'quote_price_message' => $quotePayload['quote_price_message'],
                'quote_price_blocking' => $quotePayload['quote_price_blocking'],
                'quote_price_snapshot' => $quotePayload['quote_price_snapshot'],
                'manual_quote_price_value' => $manualQuotePayload && in_array((string) ($manualQuotePayload['conversion_status'] ?? ''), ['converted', 'not_required', 'stale_rate'], true)
                    ? $manualQuotePayload['converted_amount']
                    : null,
                'manual_quote_currency' => $manualQuotePayload['document_currency'] ?? $documentCurrency,
                'manual_quote_price_status' => $manualQuotePayload['conversion_status'] ?? null,
                'freshness' => $freshness,
                'manual_quote_price_snapshot' => $manualQuotePayload ? [
                    'source_document_currency' => $manualQuotePayload['source_document_currency'] ?? null,
                    'document_currency' => $manualQuotePayload['document_currency'] ?? null,
                    'converted_amount' => $manualQuotePayload['converted_amount'] ?? null,
                    'conversion_status' => $manualQuotePayload['conversion_status'] ?? null,
                    'applied_rate' => $manualQuotePayload['applied_rate'] ?? null,
                    'rate_date' => $manualQuotePayload['rate_date'] ?? null,
                    'rate_source' => $manualQuotePayload['rate_source'] ?? null,
                    'rate_type' => $manualQuotePayload['rate_type'] ?? null,
                ] : null,
            ],
        ];
    }


    private function prepareCatalogPriceSnapshot(
        TenantAccount $tenant,
        array $priceSnapshot,
        ?string $fallbackCurrency,
        ?float $fallbackDisplayPrice,
        ?string $requestedDate = null,
    ): array {
        $sourceCurrency = $priceSnapshot['source_currency'] ?? $priceSnapshot['currency'] ?? $fallbackCurrency;
        $normalized = array_merge($priceSnapshot, [
            'source_price' => $priceSnapshot['source_price'] ?? $priceSnapshot['list_price'] ?? $fallbackDisplayPrice,
            'source_currency' => $sourceCurrency,
            'currency' => $priceSnapshot['currency'] ?? $fallbackCurrency,
            'currency_status' => $priceSnapshot['currency_status'] ?? ($sourceCurrency ? 'resolved' : 'missing'),
        ]);
        $existingProjection = (array) ($priceSnapshot['currency_snapshot'] ?? []);
        $projection = ($existingProjection['base_price'] ?? null) !== null
            ? array_merge($normalized, $existingProjection, [
                'source_price' => $existingProjection['source_price'] ?? $normalized['source_price'],
                'source_currency' => $existingProjection['source_currency'] ?? $normalized['source_currency'],
                'currency_origin' => $existingProjection['currency_origin'] ?? ($normalized['currency_origin'] ?? null),
                'currency_status' => $existingProjection['currency_status'] ?? $normalized['currency_status'],
            ])
            : $this->productHubCurrencyService->buildProjectionCurrencySnapshot(
                $tenant,
                $normalized,
                $requestedDate ?: now()->format('Y-m-d')
            );

        return array_merge($normalized, $projection, [
            'currency_snapshot' => $projection,
        ]);
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
