<?php

namespace App\Console\Commands;

use App\Models\StandardProduct;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Services\ProductDataHub\FallbackCategoryService;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProductDataHubProjectUnmappedProductsCommand extends Command
{
    protected $signature = 'product-data-hub:project-unmapped-products
        {--dry-run : Sadece rapor üretir, veri değiştirmez}
        {--apply : Kategori bekleyen ürünleri tenant projection’a alır}
        {--confirm= : Apply için güvenli onay anahtarı}';

    protected $description = 'Kategori eşlemesi olmayan ürünleri fallback kategori ve uyarı ile tenant projection’a hazırlayıp kontrollü projekte eder.';

    public function handle(
        FallbackCategoryService $fallbackCategoryService,
        TenantCatalogProjectionService $projectionService
    ): int {
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('confirm') !== 'PROJECT-UNMAPPED-PRODUCTS') {
            $this->error('Apply durduruldu. --confirm=PROJECT-UNMAPPED-PRODUCTS zorunludur.');

            return self::FAILURE;
        }

        $fallbackCategory = $fallbackCategoryService->pendingCategory();
        $products = $this->unmappedProducts();
        $allTenantTargets = $this->tenantTargets($products, $fallbackCategory->id, includeAlreadyFallback: true);
        $tenantTargets = $this->tenantTargets($products, $fallbackCategory->id);
        $targetStats = $this->tenantTargetStats($allTenantTargets, $tenantTargets);

        $tenantAccessOpenProducts = $products->filter(fn (StandardProduct $product) => $allTenantTargets->has($product->id));
        $projectionableProducts = $products->filter(fn (StandardProduct $product) => $tenantTargets->has($product->id));
        $projected = [
            'tenant_catalog_products' => 0,
            'updated_products' => 0,
            'created_products' => 0,
            'variants' => 0,
        ];

        if ($apply) {
            foreach ($tenantTargets as $productId => $tenants) {
                foreach ($tenants as $tenant) {
                    $result = $projectionService->projectForTenant($tenant, [
                        'standard_product_ids' => [(int) $productId],
                    ]);

                    $projected['tenant_catalog_products'] += (int) ($result['products'] ?? 0);
                    $projected['updated_products'] += (int) ($result['updated_products'] ?? 0);
                    $projected['created_products'] += (int) ($result['created_products'] ?? 0);
                    $projected['variants'] += (int) ($result['variants'] ?? 0);
                }
            }
        }

        $tenantCatalogFallbackCount = TenantCatalogProduct::query()
            ->where('standard_category_id', $fallbackCategory->id)
            ->count();

        $this->info($apply
            ? 'Apply tamamlandı: kategori bekleyen ürünler fallback kategori ve uyarı ile projection’a alındı.'
            : 'Dry-run tamamlandı: veri değiştirilmedi.');
        $this->line('Fallback kategori: ' . $fallbackCategory->full_path . ' [' . $fallbackCategory->code . ']');
        $this->line('Kategori eksik standard product: ' . $products->count());
        $this->line('Tenant access açık projection adayı: ' . $tenantAccessOpenProducts->count());
        $this->line('Fallback kategoriye düşecek ürün: ' . $projectionableProducts->count());
        $this->line('already_fallback_count: ' . $targetStats['already_fallback_count']);
        $this->line('would_create_count: ' . $targetStats['would_create_count']);
        $this->line('would_update_count: ' . $targetStats['would_update_count']);
        $this->line('would_skip_count: ' . $targetStats['would_skip_count']);
        $this->line('Fiyat uyarılı aday: ' . $projectionableProducts->filter(fn (StandardProduct $product) => blank($product->min_purchase_price) && blank(data_get($product->meta, 'price_snapshot.list_price')))->count());
        $this->line('Görsel uyarılı aday: ' . $projectionableProducts->filter(fn (StandardProduct $product) => blank($product->image_url))->count());
        $this->line('Stok uyarılı aday: ' . $projectionableProducts->filter(fn (StandardProduct $product) => (float) ($product->total_stock_quantity ?? 0) <= 0)->count());
        $this->line('Mevcut fallback tenant catalog product: ' . $tenantCatalogFallbackCount);

        if ($apply) {
            $this->line('Oluşturulan tenant catalog product: ' . $projected['created_products']);
            $this->line('Güncellenen tenant catalog product: ' . $projected['updated_products']);
            $this->line('Toplam projekte edilen parent/flat product: ' . $projected['tenant_catalog_products']);
            $this->line('Projeksiyona alınan variant: ' . $projected['variants']);
        } else {
            $this->line('Apply yapılmadı. Çalıştırmak için --apply --confirm=PROJECT-UNMAPPED-PRODUCTS gerekir.');
        }

        $this->table(
            ['Tedarikçi', 'Kategori eksik ürün', 'Tenant access açık', 'Fallback adayı'],
            $this->sourceRows($products, $tenantAccessOpenProducts, $projectionableProducts)
        );

        return self::SUCCESS;
    }

    private function unmappedProducts(): Collection
    {
        return StandardProduct::query()
            ->with(['rawProducts.source.supplier'])
            ->whereNull('standard_category_id')
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->get();
    }

    private function tenantTargets(Collection $products, int $fallbackCategoryId, bool $includeAlreadyFallback = false): Collection
    {
        $supplierIds = $products->pluck('supplier_id')->filter()->unique()->values();

        $accesses = TenantSupplierAccess::query()
            ->with('tenant')
            ->whereIn('supplier_id', $supplierIds->all())
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->get()
            ->groupBy('supplier_id');

        return $products
            ->mapWithKeys(function (StandardProduct $product) use ($accesses, $fallbackCategoryId, $includeAlreadyFallback) {
                $tenants = $accesses->get($product->supplier_id, collect())
                    ->pluck('tenant')
                    ->filter(fn (?TenantAccount $tenant) => $tenant !== null)
                    ->unique('id')
                    ->values();

                if ($includeAlreadyFallback) {
                    return $tenants->isEmpty() ? [] : [$product->id => $tenants];
                }

                $remainingTenants = $tenants->reject(function (TenantAccount $tenant) use ($product, $fallbackCategoryId) {
                    return TenantCatalogProduct::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('standard_product_id', $product->id)
                        ->where('standard_category_id', $fallbackCategoryId)
                        ->where('catalog_status', 'category_pending')
                        ->exists();
                })->values();

                return $remainingTenants->isEmpty() ? [] : [$product->id => $remainingTenants];
            });
    }

    private function tenantTargetStats(Collection $allTenantTargets, Collection $remainingTenantTargets): array
    {
        $allCount = $allTenantTargets->sum(fn (Collection $tenants) => $tenants->count());
        $remainingCount = $remainingTenantTargets->sum(fn (Collection $tenants) => $tenants->count());
        $remainingProductIds = $remainingTenantTargets->keys()->map(fn ($id) => (int) $id)->values();
        $existingPairs = TenantCatalogProduct::query()
            ->whereIn('standard_product_id', $remainingProductIds->all())
            ->get(['tenant_account_id', 'standard_product_id'])
            ->mapWithKeys(fn (TenantCatalogProduct $product) => [
                $product->tenant_account_id . ':' . $product->standard_product_id => true,
            ]);

        $wouldUpdate = 0;

        foreach ($remainingTenantTargets as $productId => $tenants) {
            foreach ($tenants as $tenant) {
                if ($existingPairs->has($tenant->id . ':' . $productId)) {
                    $wouldUpdate++;
                }
            }
        }

        return [
            'already_fallback_count' => max(0, $allCount - $remainingCount),
            'would_create_count' => max(0, $remainingCount - $wouldUpdate),
            'would_update_count' => $wouldUpdate,
            'would_skip_count' => max(0, $allCount - $remainingCount),
        ];
    }

    private function sourceRows(Collection $products, Collection $tenantAccessOpenProducts, Collection $projectionableProducts): array
    {
        $accessOpenIds = $tenantAccessOpenProducts->pluck('id')->flip();
        $projectionableIds = $projectionableProducts->pluck('id')->flip();

        return $products
            ->groupBy(fn (StandardProduct $product) => $this->sourceLabel($product))
            ->map(function (Collection $group, string $supplier) use ($accessOpenIds, $projectionableIds) {
                $open = $group->filter(fn (StandardProduct $product) => $accessOpenIds->has($product->id))->count();
                $fallback = $group->filter(fn (StandardProduct $product) => $projectionableIds->has($product->id))->count();

                return [
                    $supplier,
                    $group->count(),
                    $open,
                    $fallback,
                ];
            })
            ->values()
            ->all();
    }

    private function sourceLabel(StandardProduct $product): string
    {
        $raw = $product->relationLoaded('rawProducts') ? $product->rawProducts->first() : null;

        return $raw?->source?->supplier?->name
            ?: $raw?->source?->source_name
            ?: $product->supplier?->name
            ?: 'Tedarikçi yok';
    }
}
