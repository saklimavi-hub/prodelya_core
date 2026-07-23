<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Services\ProductDataHub\ProductHubSellableTruthService;
use App\Services\ProductDataHub\ProductHubCurrencyService;
use App\Services\ProductDataHub\ProductHubFreshnessDiagnosticService;
use App\Services\ProductDataHub\SupplierWarningLabelService;
use App\Services\PromotionQuote\QuoteCurrencyAccessService;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use App\Services\Stock\TenantLocalStockPresentationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogSearchController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly SupplierWarningLabelService $supplierWarningLabelService,
        private readonly ProductHubSellableTruthService $sellableTruthService,
        private readonly ProductHubCurrencyService $productHubCurrencyService,
        private readonly ProductHubFreshnessDiagnosticService $productHubFreshnessDiagnosticService,
        private readonly QuoteCurrencyAccessService $quoteCurrencyAccessService,
        private readonly QuoteCurrencyPricingService $quoteCurrencyPricingService,
        private readonly TenantLocalStockPresentationService $tenantLocalStockPresentationService,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant) {
            return response()->json([], 200);
        }

        $queryText = trim($request->string('q')->toString());
        $categoryId = $request->integer('category_id') ?: null;
        $onlyVisible = $request->boolean('only_visible', true);
        $onlyQuoteVisible = $request->boolean('only_quote_visible', true);
        $requestedCurrency = $request->input('currency');
        $requestedDate = trim((string) $request->input('quote_date', now()->format('Y-m-d')));

        $query = TenantCatalogProduct::query()
            ->with(['category', 'standardProduct', 'variants.standardVariant'])
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->when($onlyVisible, fn ($builder) => $builder->where('visible_in_catalog', true))
            ->when($categoryId, fn ($builder) => $builder->where('standard_category_id', $categoryId))
            ->when($queryText !== '', function ($builder) use ($queryText) {
                $builder->where(function ($inner) use ($queryText) {
                    $inner->where('product_name', 'like', '%' . $queryText . '%')
                        ->orWhere('product_code', 'like', '%' . $queryText . '%')
                        ->orWhere('source_summary', 'like', '%' . $queryText . '%')
                        ->orWhere('meta', 'like', '%' . $queryText . '%')
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                            ->where('name', 'like', '%' . $queryText . '%')
                            ->orWhere('path', 'like', '%' . $queryText . '%'))
                        ->orWhereHas('variants', fn ($variantQuery) => $variantQuery
                            ->where('variant_code', 'like', '%' . $queryText . '%')
                            ->orWhere('variant_name', 'like', '%' . $queryText . '%')
                            ->orWhere('variant_color', 'like', '%' . $queryText . '%')
                            ->orWhere('source_summary', 'like', '%' . $queryText . '%')
                            ->orWhere('meta', 'like', '%' . $queryText . '%'));
                });
            })
            ->orderBy('product_name')
            ->limit(80);

        $results = $query->get()
            ->flatMap(fn (TenantCatalogProduct $product) => $this->expandSellableSearchResults($tenant, $request->user(), $product, $queryText, $onlyQuoteVisible, $requestedCurrency, $requestedDate))
            ->sortBy(fn (array $entry) => $this->resolveSearchRank($entry, $queryText))
            ->take(20)
            ->map(fn (array $entry) => $this->stripTenantHiddenGroupFields($entry))
            ->values();

        return response()->json($results);
    }

    private function resolveRequestedQuoteCurrency(TenantAccount $tenant, $user, ?string $requestedCurrency): string
    {
        $access = $this->quoteCurrencyAccessService->build($tenant, $user);
        $requested = strtoupper(trim((string) ($requestedCurrency ?? '')));
        $requested = $requested === 'TL' ? 'TRY' : $requested;

        if ($requested !== '' && ! in_array($requested, ['TRY', 'USD', 'EUR'], true)) {
            $requested = null;
        }

        if (($access['multi_currency_enabled'] ?? false) === false) {
            return 'TRY';
        }

        if (in_array($requested, ['USD', 'EUR'], true) && ! ($access['can_use_foreign_document_currency'] ?? false)) {
            return 'TRY';
        }

        return $this->quoteCurrencyPricingService->normalizeDocumentCurrency($tenant, $access, $requested ?: null);
    }

    private function safeQuotePriceSnapshot(array $pricingSnapshot): array
    {
        return $this->quoteCurrencyPricingService
            ->buildQuoteDisplayPayloadFromSnapshot((string) ($pricingSnapshot['document_currency'] ?? 'TRY'), $pricingSnapshot)['quote_price_snapshot'];
    }

    private function buildQuoteDocumentPricePayload(TenantAccount $tenant, $user, array $catalogPriceSnapshot, ?string $requestedCurrency, ?string $requestedDate): array
    {
        $documentCurrency = $this->resolveRequestedQuoteCurrency($tenant, $user, $requestedCurrency);

        return $this->quoteCurrencyPricingService->buildQuoteDisplayPayload(
            $tenant,
            $documentCurrency,
            $catalogPriceSnapshot,
            ['manual_unit_price' => false],
            $requestedDate ?: now()->format('Y-m-d')
        );
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
    private function expandSellableSearchResults($tenant, $user, TenantCatalogProduct $product, string $queryText, bool $onlyQuoteVisible, ?string $requestedCurrency = null, ?string $requestedDate = null): array
    {
        $variants = $product->variants
            ->filter(fn ($variant) => (bool) $variant->is_active && (bool) $variant->visible_in_catalog)
            ->values();

        if ($variants->isEmpty()) {
            $truth = $this->sellableTruthService->resolve($product, null, $tenant);

            if ($product->is_parent_group || !($truth['selection_allowed'] ?? false)) {
                return [];
            }

            if ($onlyQuoteVisible && !($truth['quote_visible'] ?? false)) {
                return [];
            }

            return [$this->serializeSellableProduct($tenant, $user, $product, $requestedCurrency, $requestedDate, $truth)];
        }

        $matchesParent = $queryText === '' || $this->matchesParentSearch($product, $queryText);

        return $variants
            ->map(function ($variant) use ($tenant, $product) {
                return [
                    'variant' => $variant,
                    'truth' => $this->sellableTruthService->resolve($product, $variant, $tenant),
                ];
            })
            ->filter(function (array $entry) use ($matchesParent, $product, $queryText, $onlyQuoteVisible) {
                $variant = $entry['variant'];
                $truth = $entry['truth'];

                if (!($matchesParent || $this->matchesVariantSearch($product, $variant, $queryText))) {
                    return false;
                }

                if (!($truth['selection_allowed'] ?? false)) {
                    return false;
                }

                if ($onlyQuoteVisible && !($truth['quote_visible'] ?? false)) {
                    return false;
                }

                return true;
            })
            ->map(fn (array $entry) => $this->serializeSellableVariant($tenant, $user, $product, $entry['variant'], $requestedCurrency, $requestedDate, $entry['truth']))
            ->sortBy(fn (array $entry) => $this->resolveSearchRank($entry, $queryText))
            ->values()
            ->all();
    }

    private function serializeSellableProduct($tenant, $user, TenantCatalogProduct $product, ?string $requestedCurrency = null, ?string $requestedDate = null, ?array $truth = null): array
    {
        $truth ??= $this->sellableTruthService->resolve($product, null, $tenant);
        $warningFlag = (bool) ($product->standardProduct?->warning_flag ?? data_get($product->meta, 'warning_flag', false));
        $productSourceSummary = collect($product->source_summary ?? []);
        $productPriceSnapshot = (array) data_get($product->meta, 'price_snapshot', []);
        $productSupplierId = data_get($productSourceSummary->first(), 'supplier_id');
        $productSupplierName = $this->isLocalProduct($product) ? 'Local Ürün' : $this->resolveSupplierName($productSupplierId);
        $effectiveProductStock = (float) ($truth['effective_stock'] ?? 0);
        $productWarnings = $this->buildWarningPayload($productSupplierName, [
            'net_price_warning' => (bool) data_get($product->meta, 'net_price_warning', false),
            'price_policy_warning' => (bool) data_get($product->meta, 'price_policy_warning', false),
            'pricing_policy_type' => data_get($product->meta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) data_get($product->meta, 'supplier_warning_flag', false),
            'supplier_warning_type' => data_get($product->meta, 'supplier_warning_type'),
            'missing_price' => is_null(data_get($product->meta, 'price_snapshot.list_price')) && is_null($product->display_price),
            'missing_image' => blank($product->image_url),
            'missing_category' => blank($product->standard_category_id)
                || (bool) data_get($product->meta, 'category_missing_warning', false)
                || data_get($product->meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
            'out_of_stock' => $effectiveProductStock <= 0,
            'warnings' => array_values(array_filter(array_merge(
                data_get($product->meta, 'supplier_warnings', []),
                data_get($product->meta, 'warnings', [])
            ))),
        ]);
        $warningSummary = implode(' • ', array_slice($productWarnings['badges'], 0, 3));
        $warningTone = in_array('Kırmızı Ürün', $productWarnings['badges'], true) ? 'red' : 'amber';
        $catalogPriceSnapshot = $this->prepareCatalogPriceSnapshot(
            $tenant,
            $productPriceSnapshot,
            $product->currency,
            $product->display_price !== null ? (float) $product->display_price : null,
            $requestedDate
        );
        $browserPriceSnapshot = $this->productHubCurrencyService->sanitizePriceSnapshotForBrowser($catalogPriceSnapshot, $tenant, $user);

        $currencyPayload = $this->productHubCurrencyService->buildBrowserCurrencyPayload(
            $tenant,
            $user,
            (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot)
        );
        $quotePayload = $this->buildQuoteDocumentPricePayload(
            $tenant,
            $user,
            (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot),
            $requestedCurrency,
            $requestedDate
        );
        $freshness = $this->productHubFreshnessDiagnosticService->buildQuoteFreshnessPayload($product);
        $selectionAllowed = (bool) ($truth['selection_allowed'] ?? false);
        $localStockPresentation = $this->tenantLocalStockPresentationService->forCatalogSelection($tenant, $product, null);
        $effectiveProductLocalStock = (bool) ($localStockPresentation['local_stock_operational'] ?? false)
            ? (float) ($localStockPresentation['local_stock_value'] ?? 0)
            : (float) ($product->local_stock_quantity ?? 0);
        $effectiveProductStock = $this->sellableTruthService->resolveEffectiveStock(
            $effectiveProductLocalStock,
            (float) ($product->supplier_stock_quantity ?? 0),
            (float) ($product->total_stock_quantity ?? 0),
            (bool) ($product->local_stock_priority ?? true)
        );

        return [
            'id' => $product->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'standard_product_id' => $product->standard_product_id,
            'standard_product_variant_id' => null,
            'product_code' => $product->display_code,
            'product_name' => $product->display_name,
            'image_url' => $product->image_url,
            'supplier_name' => $productSupplierName,
            'display_price' => $quotePayload['quote_price_value'],
            'list_price' => $quotePayload['quote_price_value'],
            'currency' => $quotePayload['quote_currency'],
            'vat_rate' => (float) (data_get($productSourceSummary->first(), 'vat_rate') ?? 0),
            'total_stock_quantity' => (float) ($product->total_stock_quantity ?? 0),
            'local_stock_quantity' => (float) ($localStockPresentation['local_stock_value'] ?? 0),
            'local_stock_source' => $localStockPresentation['local_stock_source'],
            'local_stock_scope' => $localStockPresentation['local_stock_scope'],
            'local_stock_reason_code' => $localStockPresentation['local_stock_reason_code'],
            'local_stock_label' => $localStockPresentation['local_stock_label'],
            'local_stock_note' => $localStockPresentation['local_stock_note'],
            'local_stock_projection_quantity' => (float) ($localStockPresentation['local_stock_projection_value'] ?? 0),
            'local_stock_operational' => (bool) ($localStockPresentation['local_stock_operational'] ?? false),
            'supplier_stock_quantity' => (float) ($product->supplier_stock_quantity ?? 0),
            'safe_stock_quantity' => (int) ($product->safe_stock_quantity ?? 0),
            'visible_stock_quantity' => $effectiveProductStock,
            'local_stock_priority' => (bool) ($product->local_stock_priority ?? true) && $effectiveProductLocalStock > 0,
            'catalog_source' => $this->isLocalProduct($product) ? 'local_product' : 'supplier_projection',
            'visible_in_quote' => (bool) ($truth['quote_visible'] ?? false),
            'sellable' => $selectionAllowed,
            'selection_reason_code' => $truth['reason_code'] ?? null,
            'warning_flag' => $warningFlag,
            'net_price_warning' => (bool) data_get($product->meta, 'net_price_warning', false),
            'price_policy_warning' => (bool) data_get($product->meta, 'price_policy_warning', false),
            'pricing_policy_type' => data_get($product->meta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) data_get($product->meta, 'supplier_warning_flag', false),
            'supplier_warning_type' => data_get($product->meta, 'supplier_warning_type'),
            'warning_badges' => $productWarnings['badges'],
            'warning_messages' => $productWarnings['messages'],
            'is_warning_sellable' => !empty($productWarnings['badges']),
            'warning_summary' => $warningSummary,
            'warning_tone' => $warningSummary !== '' ? $warningTone : null,
            'category_name' => $product->category_display_name,
            'source_price' => $currencyPayload['source_price'],
            'source_currency' => $currencyPayload['source_currency'],
            'base_price' => $currencyPayload['base_price'],
            'base_currency' => $currencyPayload['base_currency'],
            'conversion_available' => $currencyPayload['conversion_available'],
            'conversion_status' => $currencyPayload['conversion_status'],
            'applied_rate' => $currencyPayload['applied_rate'],
            'rate_date' => $currencyPayload['rate_date'],
            'rate_source' => $currencyPayload['rate_source'],
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
            'quote_price_snapshot' => array_merge($quotePayload['quote_price_snapshot'], ['freshness' => $freshness]),
            'freshness' => $freshness,
            'source_summary' => $product->source_summary,
            'product_snapshot' => [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => null,
                'standard_product_id' => $product->standard_product_id,
                'standard_product_variant_id' => null,
                'product_code' => $product->display_code,
                'product_name' => $product->display_name,
                'category_name' => $product->category_display_name,
                'source_summary' => $product->source_summary,
                'supplier_name' => $productSupplierName,
                'is_parent' => false,
                'is_variant' => false,
                'is_sellable' => $selectionAllowed,
                'quote_search_visible' => $selectionAllowed,
                'is_warning_sellable' => !empty($productWarnings['badges']),
                'warning_summary' => $warningSummary,
                'warning_tone' => $warningSummary !== '' ? $warningTone : null,
            ],
            'price_snapshot' => array_merge($browserPriceSnapshot, [
                'list_price' => $quotePayload['quote_price_value'],
                'display_price' => $quotePayload['quote_price_value'],
                'currency' => $quotePayload['quote_currency'],
                'quote_price_value' => $quotePayload['quote_price_value'],
                'quote_currency' => $quotePayload['quote_currency'],
                'quote_price_status' => $quotePayload['quote_price_status'],
                'quote_price_snapshot' => array_merge($quotePayload['quote_price_snapshot'], ['freshness' => $freshness]),
                'freshness_summary' => $freshness,
                'warning_badges' => $productWarnings['badges'],
                'warning_messages' => $productWarnings['messages'],
                'net_price_warning' => (bool) data_get($product->meta, 'net_price_warning', false),
                'price_policy_warning' => (bool) data_get($product->meta, 'price_policy_warning', false),
                'pricing_policy_type' => data_get($product->meta, 'pricing_policy_type'),
                'supplier_warning_flag' => (bool) data_get($product->meta, 'supplier_warning_flag', false),
                'supplier_warning_type' => data_get($product->meta, 'supplier_warning_type'),
            ]),
            'stock_snapshot' => [
                'total_stock_quantity' => (float) ($product->total_stock_quantity ?? 0),
                'local_stock_quantity' => (float) ($localStockPresentation['local_stock_value'] ?? 0),
            'local_stock_source' => $localStockPresentation['local_stock_source'],
            'local_stock_scope' => $localStockPresentation['local_stock_scope'],
            'local_stock_reason_code' => $localStockPresentation['local_stock_reason_code'],
            'local_stock_label' => $localStockPresentation['local_stock_label'],
            'local_stock_note' => $localStockPresentation['local_stock_note'],
            'local_stock_projection_quantity' => (float) ($localStockPresentation['local_stock_projection_value'] ?? 0),
            'local_stock_operational' => (bool) ($localStockPresentation['local_stock_operational'] ?? false),
                'supplier_stock_quantity' => (float) ($product->supplier_stock_quantity ?? 0),
                'visible_stock_quantity' => $effectiveProductStock,
                'safe_stock_quantity' => (int) ($product->safe_stock_quantity ?? 0),
                'local_stock_priority' => (bool) ($product->local_stock_priority ?? true) && $effectiveProductLocalStock > 0,
                'warning_flag' => $warningFlag,
            ],
        ];
    }

    private function serializeSellableVariant($tenant, $user, TenantCatalogProduct $product, $variant, ?string $requestedCurrency = null, ?string $requestedDate = null, ?array $truth = null): array
    {
        $truth ??= $this->sellableTruthService->resolve($product, $variant, $tenant);
        $supplierName = $this->isLocalProduct($product)
            ? 'Local Ürün'
            : $this->resolveSupplierName(data_get($product->source_summary, '0.supplier_id'));
        $localStock = (float) ($variant->local_stock_quantity ?? 0);
        $supplierStock = (float) ($variant->supplier_stock_quantity ?? 0);
        $fallbackStock = (float) ($variant->stock_quantity ?? 0);
        $localStockPresentation = $this->tenantLocalStockPresentationService->forCatalogSelection($tenant, $product, $variant);
        $effectiveLocalStock = (bool) ($localStockPresentation['local_stock_operational'] ?? false)
            ? (float) ($localStockPresentation['local_stock_value'] ?? 0)
            : $localStock;
        $visibleStock = $this->sellableTruthService->resolveEffectiveStock(
            $effectiveLocalStock,
            $supplierStock,
            $fallbackStock,
            (bool) ($product->local_stock_priority ?? true)
        );
        $warnings = $this->buildWarningPayload($supplierName, [
            'net_price_warning' => (bool) data_get($variant->meta, 'net_price_warning', false),
            'price_policy_warning' => (bool) data_get($variant->meta, 'price_policy_warning', false),
            'pricing_policy_type' => data_get($variant->meta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) data_get($variant->meta, 'supplier_warning_flag', false),
            'supplier_warning_type' => data_get($variant->meta, 'supplier_warning_type'),
            'missing_price' => is_null(data_get($variant->meta, 'price_snapshot.list_price')) && is_null($variant->display_price),
            'missing_image' => blank($variant->image_url),
            'missing_category' => blank($product->standard_category_id)
                || (bool) data_get($product->meta, 'category_missing_warning', false)
                || data_get($product->meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
            'out_of_stock' => $visibleStock <= 0,
            'warnings' => data_get($variant->meta, 'warnings', []),
        ]);
        $priceSnapshot = (array) data_get($variant->meta, 'price_snapshot', []);
        $visibleInQuote = (bool) ($truth['quote_visible'] ?? false);
        $warningSummary = implode(' • ', array_slice($warnings['badges'], 0, 3));
        $warningTone = in_array('Kırmızı Ürün', $warnings['badges'], true) ? 'red' : 'amber';
        $catalogPriceSnapshot = $this->prepareCatalogPriceSnapshot(
            $tenant,
            $priceSnapshot,
            $variant->currency ?: $product->currency,
            $variant->display_price !== null ? (float) $variant->display_price : ($product->display_price !== null ? (float) $product->display_price : null),
            $requestedDate
        );
        $browserPriceSnapshot = $this->productHubCurrencyService->sanitizePriceSnapshotForBrowser($catalogPriceSnapshot, $tenant, $user);

        $currencyPayload = $this->productHubCurrencyService->buildBrowserCurrencyPayload(
            $tenant,
            $user,
            (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot)
        );
        $quotePayload = $this->buildQuoteDocumentPricePayload($tenant, $user, (array) data_get($catalogPriceSnapshot, 'currency_snapshot', $catalogPriceSnapshot), $requestedCurrency, $requestedDate);
        $freshness = $this->productHubFreshnessDiagnosticService->buildQuoteFreshnessPayload($product, $variant);
        $selectionAllowed = (bool) ($truth['selection_allowed'] ?? false);
        return [
            'id' => $product->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'standard_product_id' => $product->standard_product_id,
            'standard_product_variant_id' => $variant->standard_product_variant_id,
            'product_code' => $variant->variant_code,
            'product_name' => $variant->display_name,
            'image_url' => $variant->image_url ?: $product->image_url,
            'supplier_name' => $supplierName,
            'display_price' => $quotePayload['quote_price_value'],
            'list_price' => $quotePayload['quote_price_value'],
            'currency' => $quotePayload['quote_currency'],
            'vat_rate' => (float) (data_get($variant->source_summary, 'vat_rate') ?? data_get($product->source_summary, '0.vat_rate') ?? 0),
            'total_stock_quantity' => $fallbackStock,
            'local_stock_quantity' => (float) ($localStockPresentation['local_stock_value'] ?? 0),
            'local_stock_source' => $localStockPresentation['local_stock_source'],
            'local_stock_scope' => $localStockPresentation['local_stock_scope'],
            'local_stock_reason_code' => $localStockPresentation['local_stock_reason_code'],
            'local_stock_label' => $localStockPresentation['local_stock_label'],
            'local_stock_note' => $localStockPresentation['local_stock_note'],
            'local_stock_projection_quantity' => (float) ($localStockPresentation['local_stock_projection_value'] ?? 0),
            'local_stock_operational' => (bool) ($localStockPresentation['local_stock_operational'] ?? false),
            'supplier_stock_quantity' => $supplierStock,
            'safe_stock_quantity' => (int) ($variant->safe_stock_quantity ?? 0),
            'visible_stock_quantity' => $visibleStock,
            'local_stock_priority' => (bool) ($product->local_stock_priority ?? true) && $effectiveLocalStock > 0,
            'catalog_source' => $this->isLocalProduct($product) ? 'local_product' : 'supplier_projection',
            'visible_in_quote' => $visibleInQuote,
            'sellable' => $selectionAllowed,
            'selection_reason_code' => $truth['reason_code'] ?? null,
            'warning_flag' => !empty($warnings['badges']),
            'net_price_warning' => (bool) data_get($variant->meta, 'net_price_warning', false),
            'price_policy_warning' => (bool) data_get($variant->meta, 'price_policy_warning', false),
            'pricing_policy_type' => data_get($variant->meta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) data_get($variant->meta, 'supplier_warning_flag', false),
            'supplier_warning_type' => data_get($variant->meta, 'supplier_warning_type'),
            'warning_badges' => $warnings['badges'],
            'warning_messages' => $warnings['messages'],
            'is_warning_sellable' => !empty($warnings['badges']),
            'warning_summary' => $warningSummary,
            'warning_tone' => $warningSummary !== '' ? $warningTone : null,
            'category_name' => $product->category_display_name,
            'source_price' => $currencyPayload['source_price'],
            'source_currency' => $currencyPayload['source_currency'],
            'base_price' => $currencyPayload['base_price'],
            'base_currency' => $currencyPayload['base_currency'],
            'conversion_available' => $currencyPayload['conversion_available'],
            'conversion_status' => $currencyPayload['conversion_status'],
            'applied_rate' => $currencyPayload['applied_rate'],
            'rate_date' => $currencyPayload['rate_date'],
            'rate_source' => $currencyPayload['rate_source'],
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
            'quote_price_snapshot' => array_merge($quotePayload['quote_price_snapshot'], ['freshness' => $freshness]),
            'freshness' => $freshness,
            'source_summary' => $variant->source_summary ?: $product->source_summary,
            'product_snapshot' => [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'standard_product_id' => $product->standard_product_id,
                'standard_product_variant_id' => $variant->standard_product_variant_id,
                'product_code' => $variant->variant_code,
                'product_name' => $variant->display_name,
                'category_name' => $product->category_display_name,
                'source_summary' => $variant->source_summary ?: $product->source_summary,
                'supplier_name' => $supplierName,
                'is_parent' => false,
                'is_variant' => true,
                'is_sellable' => $selectionAllowed,
                'quote_search_visible' => $selectionAllowed,
                'is_warning_sellable' => !empty($warnings['badges']),
                'warning_summary' => $warningSummary,
                'warning_tone' => $warningSummary !== '' ? $warningTone : null,
            ],
            'price_snapshot' => array_merge($browserPriceSnapshot, [
                'list_price' => $quotePayload['quote_price_value'],
                'display_price' => $quotePayload['quote_price_value'],
                'currency' => $quotePayload['quote_currency'],
                'quote_price_value' => $quotePayload['quote_price_value'],
                'quote_currency' => $quotePayload['quote_currency'],
                'quote_price_status' => $quotePayload['quote_price_status'],
                'quote_price_snapshot' => array_merge($quotePayload['quote_price_snapshot'], ['freshness' => $freshness]),
                'freshness_summary' => $freshness,
                'warning_badges' => $warnings['badges'],
                'warning_messages' => $warnings['messages'],
                'net_price_warning' => (bool) data_get($variant->meta, 'net_price_warning', false),
                'price_policy_warning' => (bool) data_get($variant->meta, 'price_policy_warning', false),
                'pricing_policy_type' => data_get($variant->meta, 'pricing_policy_type'),
                'supplier_warning_flag' => (bool) data_get($variant->meta, 'supplier_warning_flag', false),
                'supplier_warning_type' => data_get($variant->meta, 'supplier_warning_type'),
            ]),
            'stock_snapshot' => [
                'total_stock_quantity' => $fallbackStock,
                'local_stock_quantity' => (float) ($localStockPresentation['local_stock_value'] ?? 0),
            'local_stock_source' => $localStockPresentation['local_stock_source'],
            'local_stock_scope' => $localStockPresentation['local_stock_scope'],
            'local_stock_reason_code' => $localStockPresentation['local_stock_reason_code'],
            'local_stock_label' => $localStockPresentation['local_stock_label'],
            'local_stock_note' => $localStockPresentation['local_stock_note'],
            'local_stock_projection_quantity' => (float) ($localStockPresentation['local_stock_projection_value'] ?? 0),
            'local_stock_operational' => (bool) ($localStockPresentation['local_stock_operational'] ?? false),
                'supplier_stock_quantity' => $supplierStock,
                'visible_stock_quantity' => $visibleStock,
                'safe_stock_quantity' => (int) ($variant->safe_stock_quantity ?? 0),
                'local_stock_priority' => (bool) ($product->local_stock_priority ?? true) && $effectiveLocalStock > 0,
                'warning_flag' => !empty($warnings['badges']),
            ],
        ];
    }

    private function matchesParentSearch(TenantCatalogProduct $product, string $queryText): bool
    {
        $needle = mb_strtolower(trim($queryText));

        return $this->buildSearchHaystack([
            $product->display_name,
            $product->display_code,
            $product->product_name,
            data_get($product->meta, 'parent_product_code'),
            data_get($product->meta, 'supplier_group_code'),
            data_get($product->source_summary, '0.supplier_group_code'),
            data_get($product->source_summary, '0.supplier_product_code'),
            $product->category_display_name,
        ])->contains($needle);
    }

    private function matchesVariantSearch(TenantCatalogProduct $product, $variant, string $queryText): bool
    {
        $needle = mb_strtolower(trim($queryText));

        return $this->buildSearchHaystack([
            $variant->display_name,
            $variant->variant_code,
            $variant->variant_name,
            $variant->variant_color,
            $variant->variant_size,
            data_get($variant->meta, 'variant_attributes.measure'),
            data_get($variant->source_summary, 'variant_stock_code'),
            data_get($variant->source_summary, 'variant_id'),
            data_get($variant->meta, 'parent_product_code'),
            data_get($variant->meta, 'supplier_group_code'),
            data_get($variant->meta, 'supplier_product_code'),
            data_get($variant->source_summary, 'supplier_group_code'),
            data_get($product->meta, 'supplier_group_code'),
            $product->display_code,
            $product->product_name,
        ])->contains($needle);
    }

    private function buildSearchHaystack(array $parts): \Illuminate\Support\Stringable
    {
        return str(implode(' ', collect($parts)->filter(fn ($value) => filled($value))->all()))->lower();
    }

    private function resolveSearchRank(array $entry, string $queryText): int
    {
        $needle = mb_strtolower(trim($queryText));
        if ($needle === '') {
            return 50;
        }

        $code = mb_strtolower((string) ($entry['product_code'] ?? ''));
        $name = mb_strtolower((string) ($entry['product_name'] ?? ''));

        return match (true) {
            $code === $needle => 0,
            str_starts_with($code, $needle) => 1,
            str_contains($code, $needle) => 3,
            str_contains($name, $needle) => 5,
            default => 20,
        };
    }

    private function stripTenantHiddenGroupFields(array $entry): array
    {
        unset($entry['group_product_code'], $entry['group_supplier_code']);
        $entry['source_summary'] = $this->sanitizeTenantSourceSummary($entry['source_summary'] ?? []);

        if (isset($entry['product_snapshot']) && is_array($entry['product_snapshot'])) {
            unset($entry['product_snapshot']['group_product_code'], $entry['product_snapshot']['group_supplier_code']);
            $entry['product_snapshot']['source_summary'] = $this->sanitizeTenantSourceSummary($entry['product_snapshot']['source_summary'] ?? []);
        }

        return $entry;
    }

    private function sanitizeTenantSourceSummary(array $sourceSummary): array
    {
        return collect($sourceSummary)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return $row;
                }

                unset(
                    $row['supplier_group_code'],
                    $row['generated_group_code'],
                    $row['parent_product_code'],
                    $row['parent_group_code']
                );

                return $row;
            })
            ->values()
            ->all();
    }

    private function resolveSupplierName(?int $supplierId): ?string
    {
        if (!$supplierId) {
            return null;
        }

        return Supplier::query()->whereKey($supplierId)->value('name');
    }

    private function buildWarningPayload(?string $supplierName, array $payload): array
    {
        $badges = [];
        $messages = [];

        $snapshot = [
            'net_price_warning' => (bool) ($payload['net_price_warning'] ?? false),
            'pricing_policy_type' => $payload['pricing_policy_type'] ?? null,
            'supplier_warning_flag' => (bool) ($payload['supplier_warning_flag'] ?? false),
            'supplier_warning_type' => $payload['supplier_warning_type'] ?? null,
        ];
        $badges = array_merge($badges, $this->supplierWarningLabelService->supplierSpecificBadges($supplierName, $snapshot));
        $messages = array_merge($messages, $this->supplierWarningLabelService->supplierSpecificMessages($supplierName, $snapshot));

        if ($payload['price_policy_warning'] ?? false) {
            $badges[] = 'Fiyat kontrolü gerekli';
            $messages = array_merge($messages, (array) ($payload['warnings'] ?? []));
            if (empty($payload['warnings'] ?? [])) {
                $messages[] = 'Bu ürünün fiyat politikası kontrol edilmelidir.';
            }
        }

        if ($payload['missing_price'] ?? false) {
            $badges[] = 'Fiyat eksik';
            $messages[] = 'Bu ürünün liste fiyatı eksik. Teklif öncesinde manuel fiyat kontrolü yapılmalıdır.';
        }

        if ($payload['missing_image'] ?? false) {
            $badges[] = 'Görsel eksik';
            $messages[] = 'Bu ürünün katalog görseli eksik.';
        }

        if ($payload['missing_category'] ?? false) {
            $badges[] = 'Kategori eşleşmemiş';
            $badges[] = 'Kategori uyarısı';
            $messages[] = 'Genel kategori henüz bağlanmadı. Ürün teklif aramasında görünmeye devam eder.';
        }

        if ($payload['out_of_stock'] ?? false) {
            $badges[] = 'Stok yok';
            $messages[] = 'Satışta kullanılacak stok görünmüyor.';
        }

        return [
            'badges' => array_values(array_unique(array_filter($badges))),
            'messages' => array_values(array_unique(array_filter($messages))),
        ];
    }

    private function resolveEffectiveStock(float $localStock, float $supplierStock, float $fallbackStock, bool $localStockPriority = true): float
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

    private function isLocalProduct(TenantCatalogProduct $product): bool
    {
        return $product->catalog_source === 'local_product'
            || data_get($product->meta, 'catalog_source') === 'local_product';
    }

    private function variantIsQuoteVisible(TenantCatalogProduct $product, $variant): bool
    {
        if (data_get($variant->meta, 'quote_search_visible') !== null) {
            return (bool) data_get($variant->meta, 'quote_search_visible');
        }

        return (bool) $product->visible_in_quote;
    }
}
