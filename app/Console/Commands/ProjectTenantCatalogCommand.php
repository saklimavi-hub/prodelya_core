<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProjectTenantCatalogCommand extends Command
{
    protected $signature = 'prodelya:project-tenant-catalog
        {--tenant= : Hedef abone firma slug/panel_subdomain/name}
        {--tenant-id= : Hedef abone firma numeric id}
        {--supplier= : Supplier code veya isim filtresi}
        {--supplier-id=* : Supplier id filtresi}
        {--dry-run : Veri yazmadan projection planini raporlar}';

    protected $description = 'Mevcut standard tedarikçi ürünlerinden hedef abone firma için tenant katalog projection/backfill üretir.';

    public function handle(TenantCatalogProjectionService $projectionService): int
    {
        $tenant = $this->resolveTenant();

        if (!$tenant) {
            return self::FAILURE;
        }

        $allAccessRows = $this->supplierAccessRows($tenant);
        $eligibleAccessRows = $allAccessRows
            ->filter(fn (TenantSupplierAccess $access) => $this->isProjectionEligibleAccess($access))
            ->values();

        $selectedAccessRows = $this->applySupplierFilters($eligibleAccessRows);
        if ($selectedAccessRows === null) {
            return self::FAILURE;
        }

        $selectedSupplierIds = $selectedAccessRows
            ->pluck('supplier_id')
            ->filter()
            ->unique()
            ->values();

        $beforeCounts = $this->catalogCounts($tenant, $selectedSupplierIds);
        $analysis = $projectionService->analyzeForTenant($tenant, [
            'supplier_ids' => $selectedSupplierIds->all(),
        ]);

        $this->line('Abone Firma Hesabı: ' . $tenant->name);
        $this->line('Slug: ' . $tenant->slug);
        $this->line('Tüm hazır tedarikçi erişimleri: ' . $allAccessRows->count());
        $this->line('Projection için uygun erişimler: ' . $eligibleAccessRows->count());
        $this->line('Seçilen hazır tedarikçi kaynakları: ' . $selectedAccessRows->count());
        $this->line('Aday standard ürün: ' . $analysis['candidate_products']);
        $this->line('Aday standard varyant: ' . $analysis['candidate_variants']);
        $this->line('Project edilebilir ürün: ' . $analysis['projectable_products']);
        $this->line('Project edilebilir varyant: ' . $analysis['projectable_variants']);
        $this->line('Oluşturulacak katalog ürünü: ' . $analysis['would_create_products']);
        $this->line('Güncellenecek katalog ürünü: ' . $analysis['would_update_products']);
        $this->line('Oluşturulacak katalog varyantı: ' . $analysis['would_create_variants']);
        $this->line('Güncellenecek katalog varyantı: ' . $analysis['would_update_variants']);
        $this->line('Access nedeniyle atlanan ürün: ' . $analysis['access_denied_products']);
        $this->line('Kategori beklediği için bloklu: ' . $analysis['blocked_missing_category']);
        $this->line('Fiyat eksikliği nedeniyle bloklu: ' . $analysis['blocked_missing_price']);
        $this->line('Kategori çakışması nedeniyle bloklu: ' . $analysis['blocked_conflict_category']);
        $this->line('Diğer projection blokları: ' . $analysis['blocked_projection_errors']);
        $this->line('Hold-state güncellemesi bekleyen ürün: ' . $analysis['hold_state_updates']);
        $this->line('Mevcut katalog ürünü (filtrelenen supplier seti): ' . $beforeCounts['products']);
        $this->line('Mevcut katalog varyantı (filtrelenen supplier seti): ' . $beforeCounts['variants']);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: Veri yazılmadı.');

            return self::SUCCESS;
        }

        if ($selectedSupplierIds->isEmpty()) {
            $this->warn('Projection için uygun hazır tedarikçi kaynağı bulunamadı. Veri yazılmadı.');

            return self::SUCCESS;
        }

        $result = $projectionService->projectForTenant($tenant, [
            'supplier_ids' => $selectedSupplierIds->all(),
        ]);
        $afterCounts = $this->catalogCounts($tenant, $selectedSupplierIds);

        $this->info('Projection tamamlandı.');
        $this->line('Oluşturulan/güncellenen katalog ürünü: ' . ($result['products'] ?? 0));
        $this->line('Oluşturulan/güncellenen katalog varyantı: ' . ($result['variants'] ?? 0));
        $this->line('Yeni oluşturulan katalog ürünü: ' . ($result['created_products'] ?? 0));
        $this->line('Güncellenen katalog ürünü: ' . ($result['updated_products'] ?? 0));
        $this->line('Önce katalog ürün sayısı: ' . $beforeCounts['products']);
        $this->line('Sonra katalog ürün sayısı: ' . $afterCounts['products']);
        $this->line('Önce katalog varyant sayısı: ' . $beforeCounts['variants']);
        $this->line('Sonra katalog varyant sayısı: ' . $afterCounts['variants']);

        return self::SUCCESS;
    }

    private function resolveTenant(): ?TenantAccount
    {
        $tenantId = $this->option('tenant-id');
        $tenantKey = trim((string) $this->option('tenant'));

        if ($tenantId === null && $tenantKey === '') {
            $this->error('--tenant veya --tenant-id zorunludur.');

            return null;
        }

        if ($tenantId !== null && $tenantKey !== '') {
            $this->error('Aynı anda yalnız bir hedef verin: --tenant veya --tenant-id.');

            return null;
        }

        if ($tenantId !== null) {
            $tenant = TenantAccount::query()->find((int) $tenantId);
        } else {
            $tenant = TenantAccount::query()
                ->where(function ($query) use ($tenantKey) {
                    $query->where('slug', $tenantKey)
                        ->orWhere('panel_subdomain', $tenantKey)
                        ->orWhere('name', $tenantKey);
                })
                ->first();
        }

        if (!$tenant) {
            $this->error('Hedef abone firma hesabı bulunamadı.');
        }

        return $tenant;
    }

    private function supplierAccessRows(TenantAccount $tenant): Collection
    {
        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->with('supplier:id,name,code,status')
            ->orderBy('supplier_id')
            ->get();
    }

    private function isProjectionEligibleAccess(TenantSupplierAccess $access): bool
    {
        return (bool) $access->is_active
            && (bool) $access->can_view_products
            && (bool) $access->visible_in_catalog;
    }

    private function applySupplierFilters(Collection $accessRows): ?Collection
    {
        $selected = $accessRows;
        $supplierIds = collect((array) $this->option('supplier-id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();
        $supplierTerm = trim((string) $this->option('supplier'));

        if ($supplierIds->isNotEmpty()) {
            $selected = $selected
                ->filter(fn (TenantSupplierAccess $access) => $supplierIds->contains((int) $access->supplier_id))
                ->values();
        }

        if ($supplierTerm !== '') {
            $needle = Str::lower($supplierTerm);
            $selected = $selected
                ->filter(function (TenantSupplierAccess $access) use ($needle) {
                    $supplier = $access->supplier;
                    $name = Str::lower((string) ($supplier?->name ?? ''));
                    $code = Str::lower((string) ($supplier?->code ?? ''));

                    return Str::contains($name, $needle) || Str::contains($code, $needle);
                })
                ->values();
        }

        if (($supplierIds->isNotEmpty() || $supplierTerm !== '') && $selected->isEmpty()) {
            $this->error('Verilen supplier filtresi ile eşleşen uygun hazır tedarikçi kaynağı bulunamadı.');

            return null;
        }

        return $selected;
    }

    private function catalogCounts(TenantAccount $tenant, Collection $supplierIds): array
    {
        $productQuery = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id);

        if ($supplierIds->isNotEmpty()) {
            $productQuery->where(function ($query) use ($supplierIds) {
                foreach ($supplierIds as $supplierId) {
                    $query->orWhereJsonContains('source_summary->supplier_id', (int) $supplierId)
                        ->orWhereHas('standardProduct', fn ($builder) => $builder->where('supplier_id', (int) $supplierId));
                }
            });
        }

        $productIds = (clone $productQuery)->pluck('id');

        return [
            'products' => (clone $productQuery)->count(),
            'variants' => $productIds->isEmpty()
                ? 0
                : TenantCatalogProductVariant::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->whereIn('tenant_catalog_product_id', $productIds->all())
                    ->count(),
        ];
    }
}
