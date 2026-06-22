<?php

namespace App\Console\Commands;

use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Services\ProductDataHub\FallbackCategoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProductDataHubAuditFallbackCategoriesCommand extends Command
{
    protected $signature = 'product-data-hub:audit-fallback-categories';

    protected $description = 'Fallback kategori projection durumunu denetler; veri değiştirmez.';

    public function handle(): int
    {
        $fallback = app(FallbackCategoryService::class)->pendingCategory();
        $fallbackId = $fallback->id;
        $fallbackProducts = TenantCatalogProduct::query()
            ->with('standardProduct.supplier')
            ->where('standard_category_id', $fallbackId)
            ->get();

        $this->info('Fallback kategori audit tamamlandı; veri değiştirilmedi.');
        $this->line('Fallback kategori: ' . $fallback->full_path . ' [' . $fallback->code . ']');
        $this->line('fallback_standard_product_count: ' . StandardProduct::query()->where('standard_category_id', $fallbackId)->count());
        $this->line('fallback_tenant_catalog_count: ' . $fallbackProducts->count());
        $this->line('catalog_visible_fallback_count: ' . $fallbackProducts->where('visible_in_catalog', true)->count());
        $this->line('quote_visible_fallback_count: ' . $fallbackProducts->where('visible_in_quote', true)->count());
        $this->line('category_pending_standard_product_count: ' . StandardProduct::query()
            ->where(function ($query) use ($fallbackId) {
                $query->whereNull('standard_category_id')
                    ->orWhere('standard_category_id', $fallbackId)
                    ->orWhere('meta->category_missing_warning', true);
            })
            ->count());
        $this->line('category_pending_tenant_catalog_count: ' . TenantCatalogProduct::query()
            ->where(function ($query) use ($fallbackId) {
                $query->where('catalog_status', 'category_pending')
                    ->orWhere('standard_category_id', $fallbackId)
                    ->orWhere('meta->category_missing_warning', true);
            })
            ->count());
        $this->line('fallback_with_category_missing_warning_count: ' . $fallbackProducts->filter(fn (TenantCatalogProduct $product) => (bool) data_get($product->meta, 'category_missing_warning', false))->count());
        $this->line('mapping_approved_count: ' . SupplierCategoryMapping::query()->approved()->count());
        $this->line('mapping_pending_or_review_count: ' . SupplierCategoryMapping::query()->whereIn('mapping_status', ['pending', 'needs_review', 'conflict', 'cancelled'])->count());
        $this->line('tmp_demo_source_count: ' . $this->tmpDemoSourceCount());

        $this->table(
            ['Tedarikçi', 'Fallback tenant', 'Quote visible fallback', 'Catalog visible fallback', 'Mapping bekleyen'],
            $this->supplierRows($fallbackProducts)
        );

        return self::SUCCESS;
    }

    private function supplierRows(Collection $fallbackProducts): array
    {
        $pendingMappings = SupplierCategoryMapping::query()
            ->whereIn('mapping_status', ['pending', 'needs_review', 'conflict', 'cancelled'])
            ->get()
            ->groupBy('supplier_id');

        return $fallbackProducts
            ->groupBy(fn (TenantCatalogProduct $product) => $product->standardProduct?->supplier?->name ?? 'Tedarikçi yok')
            ->map(function (Collection $products, string $supplier) use ($pendingMappings) {
                $supplierId = $products->first()?->standardProduct?->supplier_id;

                return [
                    $supplier,
                    $products->count(),
                    $products->where('visible_in_quote', true)->count(),
                    $products->where('visible_in_catalog', true)->count(),
                    $supplierId ? $pendingMappings->get($supplierId, collect())->count() : 0,
                ];
            })
            ->sortBy(fn (array $row) => $row[0])
            ->values()
            ->all();
    }

    private function tmpDemoSourceCount(): int
    {
        return SupplierSource::query()
            ->where(function ($query) {
                $query->where('source_name', 'like', '%TMP%')
                    ->orWhere('source_name', 'like', '%Demo%')
                    ->orWhere('source_name', 'like', '%Test%')
                    ->orWhere('config->profile_key', 'like', 'TMP-%')
                    ->orWhere('config->profile_key', 'like', 'DEMO-%')
                    ->orWhere('config->profile_key', 'like', 'TEST-%');
            })
            ->count();
    }
}
