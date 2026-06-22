<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\SupplierProductVariantRaw;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Collection;

class VariantHealthScanner
{
    public function scan(?TenantAccount $tenant = null, int $limitPerSupplier = 25): Collection
    {
        $tenant ??= TenantAccount::query()->first();

        return Supplier::query()
            ->whereHas('standardProducts')
            ->orderBy('name')
            ->get()
            ->flatMap(fn (Supplier $supplier) => $this->scanSupplier($supplier, $tenant, $limitPerSupplier))
            ->values();
    }

    public function scanSupplier(Supplier $supplier, ?TenantAccount $tenant = null, int $limit = 25, ?string $groupCode = null): Collection
    {
        $tenant ??= TenantAccount::query()->first();

        $query = StandardProduct::query()
            ->with(['variants', 'tenantCatalogProducts.variants'])
            ->where('supplier_id', $supplier->id)
            ->has('variants')
            ->orderByDesc('variant_count');

        if (filled($groupCode)) {
            $query->where(function ($innerQuery) use ($groupCode) {
                $innerQuery
                    ->where('standard_product_code', $groupCode)
                    ->orWhere('sku', $groupCode)
                    ->orWhere('source_summary', 'like', '%' . $groupCode . '%');
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (StandardProduct $product) => $this->buildGroupReport($supplier, $product, $tenant))
            ->values();
    }

    public function summarize(Collection $rows): array
    {
        $needsReview = $rows->where('status', 'needs_review');

        return [
            'total_groups' => $rows->count(),
            'groups_checked' => $rows->count(),
            'healthy_groups' => $rows->where('status', 'healthy')->count(),
            'review_groups' => $needsReview->count(),
            'build_missing_groups' => $this->countRowsWithType($rows, 'build_missing'),
            'projection_missing_groups' => $this->countRowsWithType($rows, 'projection_missing'),
            'search_visibility_missing_groups' => $this->countRowsWithType($rows, 'search_visibility_missing'),
            'category_blocked_groups' => $this->countRowsWithType($rows, 'category_blocked'),
            'price_blocked_groups' => $this->countRowsWithType($rows, 'price_blocked'),
            'source_policy_blocked_groups' => $this->countRowsWithType($rows, 'source_policy_blocked'),
            'raw_standard_review_groups' => $this->countRowsWithType($rows, 'raw_standard_review'),
            'missing_projection_groups' => $this->countRowsWithType($rows, 'projection_missing'),
            'parent_quote_visible_groups' => $rows->where('parent_quote_visible', true)->count(),
            'safe_repair_groups' => $rows->where('repair_candidate', true)->count(),
            'manual_review_groups' => $needsReview->where('repair_candidate', false)->count(),
        ];
    }

    private function buildGroupReport(Supplier $supplier, StandardProduct $product, ?TenantAccount $tenant): array
    {
        $tenantCatalogProducts = $product->tenantCatalogProducts
            ->when($tenant, fn (Collection $collection) => $collection->where('tenant_account_id', $tenant->id))
            ->values();

        $tenantVariants = $tenantCatalogProducts
            ->flatMap(fn (TenantCatalogProduct $catalogProduct) => $catalogProduct->variants)
            ->filter(fn ($variant) => (bool) $variant->is_active && (bool) $variant->visible_in_catalog)
            ->values();

        $quoteVisibleVariants = $tenantVariants
            ->filter(fn ($variant) => (bool) data_get($variant->meta, 'quote_search_visible', true))
            ->values();

        $groupCode = $this->resolveGroupCode($product);
        $source = $this->resolveSource($supplier, $product);
        $tenantAccessOpen = $this->tenantAccessOpen($supplier, $tenant);
        $rawVariantCount = $this->rawVariantCount($supplier, $product, $groupCode);
        $standardVariantCount = $product->variants->count();
        $tenantVariantCount = $tenantVariants->count();
        $quoteSearchVariantCount = $quoteVisibleVariants->count();
        $parentQuoteVisible = $tenantCatalogProducts->contains(fn (TenantCatalogProduct $catalogProduct) => (bool) $catalogProduct->visible_in_quote);
        $missingInStandard = max(0, $rawVariantCount - $standardVariantCount);
        $missingInProjection = max(0, $standardVariantCount - $tenantVariantCount);
        $missingInSearch = max(0, $tenantVariantCount - $quoteSearchVariantCount);
        $categoryBlocked = $this->categoryBlocked($product, $tenantCatalogProducts);
        $priceBlocked = $this->priceBlocked($product, $tenantVariants);
        $sourceBlocked = !$this->sourceIsUsable($supplier, $source) || !$tenantAccessOpen;
        $mismatchTypes = $this->mismatchTypes(
            $rawVariantCount,
            $standardVariantCount,
            $tenantVariantCount,
            $quoteSearchVariantCount,
            $parentQuoteVisible,
            $categoryBlocked,
            $priceBlocked,
            $sourceBlocked
        );
        $repairCandidate = $this->isRepairCandidate(
            $mismatchTypes,
            $standardVariantCount,
            $categoryBlocked,
            $priceBlocked,
            $sourceBlocked,
            $parentQuoteVisible
        );
        $blockedReason = $this->blockedReason($categoryBlocked, $priceBlocked, $sourceBlocked, $tenantAccessOpen, $supplier, $source);
        $warningReason = $this->warningReason($rawVariantCount, $standardVariantCount, $tenantVariantCount, $quoteSearchVariantCount);
        $status = in_array('healthy', $mismatchTypes, true) ? 'healthy' : 'needs_review';

        return [
            'supplier_id' => $supplier->id,
            'source_id' => $source?->id,
            'source_status' => $source?->status,
            'source_active' => $this->sourceIsUsable($supplier, $source),
            'supplier_name' => $supplier->name,
            'supplier_code' => $supplier->code,
            'group_code' => $groupCode,
            'generated_group_code' => $product->standard_product_code ?: $product->sku,
            'standard_product_id' => $product->id,
            'parent_standard_product_id' => $product->id,
            'parent_tenant_catalog_product_id' => $tenantCatalogProducts->first()?->id,
            'parent_standard_product_exists' => true,
            'parent_tenant_catalog_product_exists' => $tenantCatalogProducts->isNotEmpty(),
            'raw_variant_count' => $rawVariantCount,
            'standard_variant_count' => $standardVariantCount,
            'tenant_catalog_variant_count' => $tenantVariantCount,
            'tenant_variant_count' => $tenantVariantCount,
            'quote_search_variant_count' => $quoteSearchVariantCount,
            'parent_quote_visible' => $parentQuoteVisible,
            'parent_in_quote_search' => $parentQuoteVisible,
            'missing_in_standard_count' => $missingInStandard,
            'missing_in_projection_count' => $missingInProjection,
            'missing_in_search_count' => $missingInSearch,
            'has_missing_projection' => $missingInProjection > 0,
            'repair_candidate' => $repairCandidate,
            'blocked_reason' => $blockedReason,
            'warning_reason' => $warningReason,
            'mismatch_type' => $mismatchTypes[0] ?? 'healthy',
            'mismatch_types' => $mismatchTypes,
            'status' => $status,
            'issue_reasons' => $this->issueReasons($mismatchTypes, $blockedReason, $warningReason),
        ];
    }

    private function resolveGroupCode(StandardProduct $product): string
    {
        return (string) (
            data_get($product->source_summary, '0.supplier_group_code')
            ?: $product->standard_product_code
            ?: $product->sku
            ?: 'group-' . $product->id
        );
    }

    private function rawVariantCount(Supplier $supplier, StandardProduct $product, string $groupCode): int
    {
        $rawProductIds = collect($product->source_summary ?? [])
            ->pluck('raw_product_id')
            ->filter()
            ->values();

        return SupplierProductVariantRaw::query()
            ->where('supplier_id', $supplier->id)
            ->where(function ($query) use ($rawProductIds, $groupCode) {
                if ($rawProductIds->isNotEmpty()) {
                    $query->whereIn('supplier_product_raw_id', $rawProductIds);

                    return;
                }

                $query->where('supplier_group_code', $groupCode);
            })
            ->count();
    }

    private function mismatchTypes(
        int $rawVariantCount,
        int $standardVariantCount,
        int $tenantVariantCount,
        int $quoteSearchVariantCount,
        bool $parentQuoteVisible,
        bool $categoryBlocked,
        bool $priceBlocked,
        bool $sourceBlocked
    ): array {
        $types = [];

        if ($rawVariantCount > 0 && $standardVariantCount === 0) {
            $types[] = 'build_missing';
        } elseif ($rawVariantCount > $standardVariantCount) {
            $types[] = 'raw_standard_review';
        }

        if ($standardVariantCount > $tenantVariantCount) {
            $types[] = 'projection_missing';
        }

        if ($tenantVariantCount > $quoteSearchVariantCount) {
            $types[] = 'search_visibility_missing';
        }

        if ($parentQuoteVisible) {
            $types[] = 'parent_quote_visible';
        }

        if ($categoryBlocked) {
            $types[] = 'category_blocked';
        }

        if ($priceBlocked) {
            $types[] = 'price_blocked';
        }

        if ($sourceBlocked) {
            $types[] = 'source_policy_blocked';
        }

        return $types === [] ? ['healthy'] : array_values(array_unique($types));
    }

    private function isRepairCandidate(
        array $mismatchTypes,
        int $standardVariantCount,
        bool $categoryBlocked,
        bool $priceBlocked,
        bool $sourceBlocked,
        bool $parentQuoteVisible
    ): bool {
        $hasRepairableMismatch = (bool) array_intersect($mismatchTypes, ['projection_missing', 'search_visibility_missing']);

        return $hasRepairableMismatch
            && $standardVariantCount > 0
            && !$categoryBlocked
            && !$priceBlocked
            && !$sourceBlocked
            && !$parentQuoteVisible;
    }

    private function categoryBlocked(StandardProduct $product, Collection $tenantCatalogProducts): bool
    {
        if (blank($product->standard_category_id)) {
            return true;
        }

        return $tenantCatalogProducts->contains(function (TenantCatalogProduct $catalogProduct) {
            $status = strtolower((string) ($catalogProduct->catalog_status ?? ''));
            $reason = strtolower((string) ($catalogProduct->hidden_reason ?? ''));

            return str_contains($status, 'category')
                || str_contains($reason, 'kategori')
                || str_contains($reason, 'category');
        });
    }

    private function priceBlocked(StandardProduct $product, Collection $tenantVariants): bool
    {
        $hasProductPrice = filled($product->min_purchase_price)
            || filled($product->max_purchase_price)
            || filled($product->purchase_price)
            || filled($product->selling_price);

        if ($hasProductPrice) {
            return false;
        }

        return $tenantVariants->isEmpty() || $tenantVariants->every(fn ($variant) => blank($variant->display_price));
    }

    private function sourceIsUsable(Supplier $supplier, ?SupplierSource $source): bool
    {
        return $supplier->isActive()
            && $source !== null
            && $source->status === 'active';
    }

    private function tenantAccessOpen(Supplier $supplier, ?TenantAccount $tenant): bool
    {
        if (!$tenant) {
            return false;
        }

        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('can_use_in_quotes', true)
            ->exists();
    }

    private function resolveSource(Supplier $supplier, StandardProduct $product): ?SupplierSource
    {
        $sourceId = collect($product->source_summary ?? [])
            ->pluck('supplier_source_id')
            ->filter()
            ->first();

        if ($sourceId) {
            return SupplierSource::query()->where('supplier_id', $supplier->id)->find($sourceId);
        }

        return SupplierSource::query()
            ->where('supplier_id', $supplier->id)
            ->visibleInProductDataHub()
            ->orderByDesc('id')
            ->first();
    }

    private function blockedReason(
        bool $categoryBlocked,
        bool $priceBlocked,
        bool $sourceBlocked,
        bool $tenantAccessOpen,
        Supplier $supplier,
        ?SupplierSource $source
    ): ?string {
        $reasons = [];

        if ($categoryBlocked) {
            $reasons[] = 'Kategori eşlemesi eksik veya projection kategori nedeniyle bloklu.';
        }

        if ($priceBlocked) {
            $reasons[] = 'Fiyat eksik veya fiyat policy kontrolü gerekiyor.';
        }

        if ($sourceBlocked) {
            if (!$supplier->isActive()) {
                $reasons[] = 'Tedarikçi aktif değil.';
            }

            if (!$source || $source->status !== 'active') {
                $reasons[] = 'Aktif gerçek kaynak bulunamadı.';
            }

            if (!$tenantAccessOpen) {
                $reasons[] = 'Tenant tedarikçi erişimi teklif için açık değil.';
            }
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    private function warningReason(int $rawVariantCount, int $standardVariantCount, int $tenantVariantCount, int $quoteSearchVariantCount): ?string
    {
        $warnings = [];

        if ($rawVariantCount > $standardVariantCount) {
            $warnings[] = sprintf(
                'Raw varyant sayısı standard varyanttan yüksek. Raw: %d, Standard: %d. Bu durum duplicate/dedup veya build eksiği olabilir.',
                $rawVariantCount,
                $standardVariantCount
            );
        }

        if ($tenantVariantCount > $quoteSearchVariantCount) {
            $warnings[] = sprintf(
                'Tenant projection var ama quote search görünürlüğü eksik. Tenant: %d, Search: %d.',
                $tenantVariantCount,
                $quoteSearchVariantCount
            );
        }

        return $warnings === [] ? null : implode(' ', $warnings);
    }

    private function issueReasons(array $mismatchTypes, ?string $blockedReason, ?string $warningReason): array
    {
        $reasons = [];

        foreach ($mismatchTypes as $type) {
            $reasons[] = match ($type) {
                'build_missing' => 'Raw/staging var ama standard product varyantı yok.',
                'raw_standard_review' => 'Raw/standard varyant sayısı uyumsuz, manuel build kontrolü gerekir.',
                'projection_missing' => 'Standard varyant var ama tenant projection varyantı eksik.',
                'search_visibility_missing' => 'Tenant projection var ama quote search görünürlüğü eksik.',
                'parent_quote_visible' => 'Parent ürün quote search için görünür durumda.',
                'category_blocked' => 'Kategori eşleme/projection durumu kontrol edilmeli.',
                'price_blocked' => 'Fiyat bilgisi veya fiyat policy kontrol edilmeli.',
                'source_policy_blocked' => 'Source/tenant access policy kontrol edilmeli.',
                default => 'Sağlıklı.',
            };
        }

        return array_values(array_filter(array_unique([
            ...$reasons,
            $blockedReason,
            $warningReason,
        ])));
    }

    private function countRowsWithType(Collection $rows, string $type): int
    {
        return $rows
            ->filter(fn (array $row) => in_array($type, (array) ($row['mismatch_types'] ?? []), true))
            ->count();
    }
}
