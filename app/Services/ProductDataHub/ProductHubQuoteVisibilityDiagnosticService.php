<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Collection;

class ProductHubQuoteVisibilityDiagnosticService
{
    public function audit(array $filters): array
    {
        $tenant = $this->resolveTenant($filters);
        $sources = $this->resolveSources($filters);
        $sample = max(1, min(200, (int) ($filters['sample'] ?? 50)));

        $sourceAudits = $sources->map(fn (SupplierSource $source) => $this->auditSource($tenant, $source, $sample))->values();

        return [
            'tenant' => $tenant,
            'sources' => $sourceAudits,
            'summary' => [
                'source_count' => $sourceAudits->count(),
                'standard_products' => $sourceAudits->sum('standard_product_count'),
                'standard_variants' => $sourceAudits->sum('standard_variant_count'),
                'tenant_catalog_products' => $sourceAudits->sum('tenant_catalog_product_count'),
                'tenant_catalog_variants' => $sourceAudits->sum('tenant_catalog_variant_count'),
                'quote_visible_products' => $sourceAudits->sum('quote_visible_product_count'),
                'quote_visible_variants' => $sourceAudits->sum('quote_visible_variant_count'),
                'invisible_items' => $sourceAudits->sum('invisible_count'),
            ],
        ];
    }

    public function auditSource(TenantAccount $tenant, SupplierSource $source, int $sample = 50): array
    {
        $accessRecord = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $source->supplier_id)
            ->latest('id')
            ->first();

        $standardProducts = StandardProduct::query()
            ->with(['variants'])
            ->where('supplier_id', $source->supplier_id)
            ->where(function ($query) use ($source) {
                $query->whereHas('rawProducts', fn ($rawQuery) => $rawQuery->where('supplier_source_id', $source->id))
                    ->orWhereHas('rawProduct', fn ($rawQuery) => $rawQuery->where('supplier_source_id', $source->id))
                    ->orWhere('source_summary', 'like', '%"supplier_source_id":' . $source->id . '%')
                    ->orWhere('source_summary', 'like', '%"supplier_source_id": ' . $source->id . '%');
            })
            ->orderBy('standard_product_code')
            ->get();

        $standardProductIds = $standardProducts->pluck('id')->filter()->values();
        $tenantCatalogProducts = TenantCatalogProduct::query()
            ->with(['variants'])
            ->where('tenant_account_id', $tenant->id)
            ->when(
                $standardProductIds->isNotEmpty(),
                fn ($query) => $query->whereIn('standard_product_id', $standardProductIds->all()),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->get()
            ->keyBy('standard_product_id');

        $stats = [
            'tenant_name' => $tenant->name,
            'supplier_name' => $source->supplier?->name,
            'source_name' => $source->source_name,
            'access' => [
                'exists' => $accessRecord !== null,
                'is_active' => (bool) ($accessRecord?->is_active ?? false),
                'can_view_products' => (bool) ($accessRecord?->can_view_products ?? false),
                'visible_in_catalog' => (bool) ($accessRecord?->visible_in_catalog ?? false),
                'can_use_in_quotes' => (bool) ($accessRecord?->can_use_in_quotes ?? false),
            ],
            'standard_product_count' => $standardProducts->count(),
            'standard_variant_count' => $standardProducts->sum(fn (StandardProduct $product) => $product->variants->count()),
            'tenant_catalog_product_count' => $tenantCatalogProducts->count(),
            'tenant_catalog_variant_count' => $tenantCatalogProducts->sum(fn (TenantCatalogProduct $product) => $product->variants->count()),
            'quote_visible_product_count' => 0,
            'quote_visible_variant_count' => 0,
            'invisible_count' => 0,
            'category_pending_count' => 0,
            'missing_price_count' => 0,
            'stock_zero_count' => 0,
            'projection_missing_count' => 0,
            'projection_stale_count' => 0,
            'parent_variant_visibility_problem_count' => 0,
            'reason_counts' => [],
            'samples' => [],
        ];

        foreach ($standardProducts as $standardProduct) {
            $catalogProduct = $tenantCatalogProducts->get($standardProduct->id);
            $hasVariants = $standardProduct->variants->isNotEmpty();
            $categoryPending = blank($standardProduct->standard_category_id)
                || (bool) data_get($standardProduct->meta, 'category_missing_warning', false);
            $missingPrice = is_null(data_get($standardProduct->meta, 'price_snapshot.list_price'))
                && is_null($standardProduct->min_purchase_price);
            $stockZero = ((float) ($standardProduct->total_stock_quantity ?? 0)) <= 0;

            if ($categoryPending) {
                $stats['category_pending_count']++;
            }
            if ($missingPrice) {
                $stats['missing_price_count']++;
            }
            if ($stockZero) {
                $stats['stock_zero_count']++;
            }

            if (!$catalogProduct) {
                $stats['projection_missing_count']++;
            } elseif ($catalogProduct->last_synced_at && $catalogProduct->last_synced_at->lt($standardProduct->updated_at)) {
                $stats['projection_stale_count']++;
            }

            if ($hasVariants) {
                $visibleVariantCount = 0;
                $visibleVariantFound = false;

                foreach ($standardProduct->variants as $standardVariant) {
                    $catalogVariant = $catalogProduct?->variants->firstWhere('standard_product_variant_id', $standardVariant->id);
                    $diagnosis = $this->diagnoseVariant($source, $accessRecord, $catalogProduct, $catalogVariant);

                    if ($diagnosis['visible']) {
                        $visibleVariantFound = true;
                        $visibleVariantCount++;
                        continue;
                    }

                    $stats['invisible_count']++;
                    $this->pushReason($stats, $diagnosis['reason_key'], $sample, [
                        'type' => 'variant',
                        'standard_product_id' => $standardProduct->id,
                        'standard_variant_id' => $standardVariant->id,
                        'product_code' => $standardProduct->standard_product_code,
                        'variant_code' => $standardVariant->variant_code ?: $standardVariant->generated_variant_code,
                        'product_name' => $standardProduct->display_name,
                        'variant_name' => $standardVariant->display_name,
                        'message' => $diagnosis['message'],
                    ]);
                }

                $stats['quote_visible_variant_count'] += $visibleVariantCount;

                if ($catalogProduct && !$catalogProduct->visible_in_quote && $visibleVariantFound) {
                    $stats['parent_variant_visibility_problem_count']++;
                }

                continue;
            }

            $diagnosis = $this->diagnoseFlatProduct($source, $accessRecord, $catalogProduct);
            if ($diagnosis['visible']) {
                $stats['quote_visible_product_count']++;
                continue;
            }

            $stats['invisible_count']++;
            $this->pushReason($stats, $diagnosis['reason_key'], $sample, [
                'type' => 'product',
                'standard_product_id' => $standardProduct->id,
                'product_code' => $standardProduct->standard_product_code,
                'product_name' => $standardProduct->display_name,
                'message' => $diagnosis['message'],
            ]);
        }

        arsort($stats['reason_counts']);
        $stats['primary_reason'] = array_key_first($stats['reason_counts']) ?? null;

        return $stats;
    }

    private function diagnoseFlatProduct(
        SupplierSource $source,
        ?TenantSupplierAccess $access,
        ?TenantCatalogProduct $catalogProduct
    ): array {
        if ($source->status !== 'active') {
            return $this->diagnosis(false, 'source_inactive', 'Ürün teklif ekranında görünmüyor çünkü kaynak pasif.');
        }

        if ($access === null) {
            return $this->diagnosis(false, 'tenant_supplier_access_missing', 'Ürün teklif ekranında görünmüyor çünkü tenant supplier access kaydı yok.');
        }

        if (!(bool) $access->is_active) {
            return $this->diagnosis(false, 'tenant_supplier_access_inactive', 'Ürün teklif ekranında görünmüyor çünkü supplier access pasif.');
        }

        if (!(bool) $access->can_view_products) {
            return $this->diagnosis(false, 'tenant_supplier_access_view_closed', 'Ürün teklif ekranında görünmüyor çünkü supplier access can_view_products=false.');
        }

        if (!(bool) $access->visible_in_catalog) {
            return $this->diagnosis(false, 'tenant_supplier_access_catalog_closed', 'Ürün teklif ekranında görünmüyor çünkü supplier access visible_in_catalog=false.');
        }

        if (!(bool) $access->can_use_in_quotes) {
            return $this->diagnosis(false, 'tenant_supplier_access_quote_closed', 'Ürün teklif ekranında görünmüyor çünkü supplier access can_use_in_quotes=false.');
        }

        if (!$catalogProduct) {
            return $this->diagnosis(false, 'tenant_catalog_product_missing', 'Ürün teklif ekranında görünmüyor çünkü tenant_catalog_products kaydı yok.');
        }

        if (!(bool) $catalogProduct->is_active) {
            return $this->diagnosis(false, 'tenant_catalog_product_inactive', 'Ürün teklif ekranında görünmüyor çünkü tenant catalog ürün kaydı pasif.');
        }

        if (!(bool) $catalogProduct->visible_in_catalog) {
            return $this->diagnosis(false, 'tenant_catalog_product_hidden', 'Ürün teklif ekranında görünmüyor çünkü tenant_catalog_products.visible_in_catalog=false.');
        }

        if (!(bool) $catalogProduct->visible_in_quote) {
            return $this->diagnosis(false, 'tenant_catalog_product_quote_closed', 'Ürün teklif ekranında görünmüyor çünkü tenant_catalog_products.visible_in_quote=false.');
        }

        return $this->diagnosis(true, 'visible', 'Ürün teklif ekranında görünür.');
    }

    private function diagnoseVariant(
        SupplierSource $source,
        ?TenantSupplierAccess $access,
        ?TenantCatalogProduct $catalogProduct,
        ?TenantCatalogProductVariant $catalogVariant
    ): array {
        if ($source->status !== 'active') {
            return $this->diagnosis(false, 'source_inactive', 'Varyant teklif ekranında görünmüyor çünkü kaynak pasif.');
        }

        if ($access === null) {
            return $this->diagnosis(false, 'tenant_supplier_access_missing', 'Varyant teklif ekranında görünmüyor çünkü tenant supplier access kaydı yok.');
        }

        if (!(bool) $access->is_active) {
            return $this->diagnosis(false, 'tenant_supplier_access_inactive', 'Varyant teklif ekranında görünmüyor çünkü supplier access pasif.');
        }

        if (!(bool) $access->can_view_products) {
            return $this->diagnosis(false, 'tenant_supplier_access_view_closed', 'Varyant teklif ekranında görünmüyor çünkü supplier access can_view_products=false.');
        }

        if (!(bool) $access->visible_in_catalog) {
            return $this->diagnosis(false, 'tenant_supplier_access_catalog_closed', 'Varyant teklif ekranında görünmüyor çünkü supplier access visible_in_catalog=false.');
        }

        if (!(bool) $access->can_use_in_quotes) {
            return $this->diagnosis(false, 'tenant_supplier_access_quote_closed', 'Varyant teklif ekranında görünmüyor çünkü supplier access can_use_in_quotes=false.');
        }

        if (!$catalogProduct) {
            return $this->diagnosis(false, 'tenant_catalog_product_missing', 'Varyant teklif ekranında görünmüyor çünkü tenant_catalog_products kaydı yok.');
        }

        if (!(bool) $catalogProduct->is_active) {
            return $this->diagnosis(false, 'tenant_catalog_product_inactive', 'Varyant teklif ekranında görünmüyor çünkü parent tenant catalog ürün kaydı pasif.');
        }

        if (!(bool) $catalogProduct->visible_in_catalog) {
            return $this->diagnosis(false, 'tenant_catalog_product_hidden', 'Varyant teklif ekranında görünmüyor çünkü parent tenant_catalog_products.visible_in_catalog=false.');
        }

        if (!$catalogVariant) {
            return $this->diagnosis(false, 'tenant_catalog_variant_missing', 'Ürün teklif ekranında görünmüyor çünkü tenant_catalog_product_variants kaydı yok.');
        }

        if (!(bool) $catalogVariant->is_active) {
            return $this->diagnosis(false, 'tenant_catalog_variant_inactive', 'Ürün teklif ekranında görünmüyor çünkü tenant catalog varyant kaydı pasif.');
        }

        if (!(bool) $catalogVariant->visible_in_catalog) {
            return $this->diagnosis(false, 'tenant_catalog_variant_hidden', 'Ürün teklif ekranında görünmüyor çünkü tenant_catalog_product_variants.visible_in_catalog=false.');
        }

        if ((bool) data_get($catalogVariant->meta, 'quote_search_visible', true) === false) {
            return $this->diagnosis(false, 'tenant_catalog_variant_quote_closed', 'Ürün teklif ekranında görünmüyor çünkü tenant catalog varyant meta.quote_search_visible=false.');
        }

        return $this->diagnosis(true, 'visible', 'Varyant teklif ekranında görünür.');
    }

    private function diagnosis(bool $visible, string $reasonKey, string $message): array
    {
        return [
            'visible' => $visible,
            'reason_key' => $reasonKey,
            'message' => $message,
        ];
    }

    private function pushReason(array &$stats, string $reasonKey, int $sample, array $sampleRow): void
    {
        $stats['reason_counts'][$reasonKey] = (int) ($stats['reason_counts'][$reasonKey] ?? 0) + 1;

        if (count($stats['samples']) < $sample) {
            $stats['samples'][] = $sampleRow;
        }
    }

    private function resolveTenant(array $filters): TenantAccount
    {
        if (!empty($filters['tenant_id'])) {
            return TenantAccount::query()->findOrFail((int) $filters['tenant_id']);
        }

        $tenantValue = trim((string) ($filters['tenant'] ?? ''));
        if ($tenantValue !== '') {
            return TenantAccount::query()
                ->where('slug', $tenantValue)
                ->orWhere('panel_subdomain', $tenantValue)
                ->orWhere('name', $tenantValue)
                ->firstOrFail();
        }

        return TenantAccount::query()->firstOrFail();
    }

    private function resolveSources(array $filters): Collection
    {
        $query = SupplierSource::query()
            ->with('supplier')
            ->orderBy('source_name');

        if (!empty($filters['source_id'])) {
            $query->whereKey((int) $filters['source_id']);
        } elseif (!empty($filters['source'])) {
            $sourceValue = trim((string) $filters['source']);
            $query->where(function ($inner) use ($sourceValue) {
                $inner->where('source_name', $sourceValue)
                    ->orWhere('source_name', 'like', '%' . $sourceValue . '%');
            });
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        } elseif (!empty($filters['supplier'])) {
            $supplier = Supplier::query()
                ->where('code', trim((string) $filters['supplier']))
                ->orWhere('name', trim((string) $filters['supplier']))
                ->orWhere('name', 'like', '%' . trim((string) $filters['supplier']) . '%')
                ->first();

            if ($supplier) {
                $query->where('supplier_id', $supplier->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->get();
    }
}
