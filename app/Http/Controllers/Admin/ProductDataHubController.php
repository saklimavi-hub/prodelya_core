<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Models\FeedSyncLog;
use App\Models\FeedSyncError;
use App\Models\ProductDataHubSyncRun;
use App\Models\TenantAccount;
use App\Services\ProductDataHub\VariantHealthScanner;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class ProductDataHubController extends Controller
{
    /**
     * Product Data Hub ana dashboard
     */
    public function index(VariantHealthScanner $variantHealthScanner): View
    {
        $this->abortTenantAccess();

        $tenant = $this->currentTenant();
        $healthRows = collect(Cache::remember(
            'admin.product_data_hub.variant_health_rows.v2.tenant_' . ($tenant?->id ?: 'global'),
            now()->addMinutes(10),
            fn () => $variantHealthScanner->scan($tenant, 25)->values()->all()
        ));
        $healthSummary = $variantHealthScanner->summarize($healthRows);
        $fallbackCategoryId = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->value('id');

        $stats = [
            'active_sources' => SupplierSource::query()->visibleInProductDataHub()->count(),
            'total_raw_products' => SupplierProductRaw::query()->count(),
            'standard_products' => StandardProduct::query()->count(),
            'common_products' => StandardProduct::query()->count(),
            'parent_products' => StandardProduct::query()->has('variants')->count(),
            'variant_products' => StandardProductVariant::query()->count(),
            'flat_products' => StandardProduct::query()->doesntHave('variants')->count(),
            'tenant_catalog_products' => TenantCatalogProduct::query()->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))->count(),
            'tenant_catalog_visible' => TenantCatalogProduct::query()->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))->where('visible_in_catalog', true)->count(),
            'quote_visible' => TenantCatalogProduct::query()->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))->where('visible_in_quote', true)->count(),
            'category_missing' => StandardProduct::query()
                ->where(function ($query) use ($fallbackCategoryId) {
                    $query->whereNull('standard_category_id')
                        ->when($fallbackCategoryId, fn ($builder) => $builder->orWhere('standard_category_id', $fallbackCategoryId))
                        ->orWhere('meta->category_missing_warning', true);
                })
                ->count(),
            'category_mapped_products' => StandardProduct::query()
                ->whereNotNull('standard_category_id')
                ->when($fallbackCategoryId, fn ($query) => $query->where('standard_category_id', '!=', $fallbackCategoryId))
                ->count(),
            'fallback_category_products' => TenantCatalogProduct::query()
                ->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))
                ->whereHas('category', fn ($query) => $query->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'))
                ->count(),
            'category_pending_visible' => TenantCatalogProduct::query()
                ->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))
                ->where('visible_in_catalog', true)
                ->where(function ($query) {
                    $query->where('meta->category_missing_warning', true)
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'));
                })
                ->count(),
            'price_missing' => StandardProduct::query()->whereNull('min_purchase_price')->whereNull('purchase_price')->count(),
            'projection_blocked' => TenantCatalogProduct::query()->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))->where('catalog_status', 'like', '%blocked%')->count(),
            'category_blocked_products' => TenantCatalogProduct::query()->when($tenant, fn ($query) => $query->where('tenant_account_id', $tenant->id))->where('catalog_status', 'missing_category')->count(),
            'variant_health_review' => $healthSummary['review_groups'],
            'safe_repair_candidates' => $healthSummary['safe_repair_groups'],
            'pending_mappings' => SupplierCategoryMapping::query()->whereNull('standard_category_id')->count(),
            'sync_errors' => FeedSyncError::query()->count(),
            'sync_success' => ProductDataHubSyncRun::query()->whereIn('status', ['success', ProductDataHubSyncRun::STATUS_COMPLETED])->count(),
            'sync_failed' => ProductDataHubSyncRun::query()->whereIn('status', ['failed', ProductDataHubSyncRun::STATUS_STUCK, ProductDataHubSyncRun::STATUS_RECOVERED])->count(),
            'sync_partial' => ProductDataHubSyncRun::query()->whereIn('status', ['partial', ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS])->count(),
            'last_sync' => ProductDataHubSyncRun::query()->latest('finished_at')->value('finished_at')
                ?? SupplierSource::query()->latest('last_sync_at')->value('last_sync_at'),
        ];

        $recentSources = SupplierSource::query()
            ->with('supplier')
            ->visibleInProductDataHub()
            ->latest('last_sync_at')
            ->take(8)
            ->get()
            ->map(fn (SupplierSource $source) => [
                'id' => $source->id,
                'name' => $source->source_name,
                'supplier' => $source->supplier?->name ?: '-',
                'type' => $source->getSourceTypeDisplayName(),
                'status' => $source->status,
                'last_sync' => $source->last_sync_at,
                'products' => SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count(),
            ]);

        $pendingMappings = SupplierCategoryMapping::query()
            ->with('supplier')
            ->whereNull('standard_category_id')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (SupplierCategoryMapping $mapping) => [
                'source' => $mapping->supplier_category_name ?: $mapping->supplier_category_code ?: '-',
                'target' => $mapping->supplier?->name ?: 'Kategori eşleme',
                'confidence' => 0,
            ]);

        $recentConflicts = $healthRows
            ->where('status', 'needs_review')
            ->take(5)
            ->map(fn (array $row) => [
                'product_code' => $row['generated_group_code'] ?: $row['group_code'],
                'conflict_type' => implode(', ', $row['mismatch_types']),
                'suppliers' => [$row['supplier_name']],
                'resolution' => $row['repair_candidate'] ? 'safe_repair' : 'manual_review',
            ]);

        return view('admin.product-data-hub.index', compact(
            'stats',
            'recentSources',
            'pendingMappings',
            'recentConflicts'
        ));
    }

    /**
     * Tedarikçi kaynakları listesi
     */
    public function sources(): View
    {
        $this->abortTenantAccess();

        // Demo veriler
        $sources = [
            [
                'id' => 1,
                'supplier' => 'Etkin Promosyon',
                'source_name' => 'Etkin Promosyon XML',
                'source_type' => 'XML',
                'url' => 'https://etkin.example.com/feed.xml',
                'format' => 'XML',
                'product_node' => 'products/product',
                'last_sync' => now()->subMinutes(45),
                'product_count' => 3250,
                'status' => 'active',
            ],
            [
                'id' => 2,
                'supplier' => 'Akdeniz Promosyon',
                'source_name' => 'Akdeniz Promosyon API',
                'source_type' => 'API',
                'url' => 'https://api.akdeniz.com/products',
                'format' => 'JSON',
                'product_node' => 'data.products',
                'last_sync' => now()->subHours(2),
                'product_count' => 2100,
                'status' => 'active',
            ],
            [
                'id' => 3,
                'supplier' => 'Yeni Nesil',
                'source_name' => 'Yeni Nesil CSV',
                'source_type' => 'CSV',
                'url' => 'https://yeninesil.com/products.csv',
                'format' => 'CSV',
                'product_node' => null,
                'last_sync' => now()->subHours(6),
                'product_count' => 4500,
                'status' => 'warning',
            ],
            [
                'id' => 4,
                'supplier' => 'Demo Tedarikçi',
                'source_name' => 'Demo JSON API',
                'source_type' => 'JSON',
                'url' => 'https://demo.example.com/api/feed',
                'format' => 'JSON',
                'product_node' => 'products',
                'last_sync' => now()->subDays(1),
                'product_count' => 2650,
                'status' => 'inactive',
            ],
        ];

        return view('admin.product-data-hub.sources', compact('sources'));
    }

    /**
     * Alan eşleme ekranı placeholder
     */
    public function fieldMappings(): View
    {
        $this->abortTenantAccess();

        $mappings = [
            [
                'id' => 1,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'kod',
                'target_field' => 'supplier_product_code',
                'mapping_type' => 'direct',
                'is_required' => true,
                'default_value' => null,
                'validation_rules' => ['required', 'max:50'],
                'transform_function' => null,
            ],
            [
                'id' => 2,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'kodgrup',
                'target_field' => 'supplier_group_code',
                'mapping_type' => 'direct',
                'is_required' => false,
                'default_value' => null,
                'validation_rules' => ['max:50'],
                'transform_function' => null,
            ],
            [
                'id' => 3,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'urunadi',
                'target_field' => 'product_name',
                'mapping_type' => 'transform',
                'is_required' => true,
                'default_value' => null,
                'validation_rules' => ['required', 'max:255'],
                'transform_function' => 'cleanProductName',
            ],
            [
                'id' => 4,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'stok',
                'target_field' => 'stock_quantity',
                'mapping_type' => 'transform',
                'is_required' => false,
                'default_value' => 0,
                'validation_rules' => ['integer', 'min:0'],
                'transform_function' => 'parseStockQuantity',
            ],
            [
                'id' => 5,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'fiyat',
                'target_field' => 'purchase_price',
                'mapping_type' => 'transform',
                'is_required' => false,
                'default_value' => 0,
                'validation_rules' => ['numeric', 'min:0'],
                'transform_function' => 'parsePrice',
            ],
            [
                'id' => 6,
                'supplier' => 'Etkin Promosyon',
                'source_field' => 'resim',
                'target_field' => 'image_url',
                'mapping_type' => 'direct',
                'is_required' => false,
                'default_value' => null,
                'validation_rules' => ['url'],
                'transform_function' => null,
            ],
        ];

        $fieldDictionary = config('prodelya_product_data_hub.standard_field_dictionary', []);
        $supplierProfiles = config('prodelya_product_data_hub.supplier_profiles', []);

        return view('admin.product-data-hub.field-mappings', compact('mappings', 'fieldDictionary', 'supplierProfiles'));
    }

    /**
     * Kategori eşleme ekranı placeholder
     */
    public function categoryMappings(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Global kategori eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function updateCategoryMapping(Request $request, SupplierCategoryMapping $mapping): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global kategori eşleme ekranı Super Admin tarafından yönetilir.');
    }

    public function bulkUpdateCategoryMappings(Request $request): RedirectResponse
    {
        $this->abortTenantAccess();
        abort(403, 'Global kategori eşleme ekranı Super Admin tarafından yönetilir.');
    }

    /**
     * Ürün eşleme / conflict ekranı placeholder
     */
    public function productMappings(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Ürün eşleme inceleme ekranı tenant kullanımına henüz açılmadı. Bu alan Super Admin veri kalitesi akışıyla yönetilir.');
    }

    /**
     * Ham ürünler / staging ürünleri listesi
     */
    public function rawProducts(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Ham ürün havuzu Super Admin tarafından yönetilir.');
    }

    /**
     * Standart ürünler listesi placeholder
     */
    public function standardProducts(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Standart ürün havuzu Super Admin tarafından yönetilir.');
    }

    /**
     * Tenant tedarikçi erişimi ekranı placeholder
     */
    public function tenantAccess(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Tedarikçi erişim yönetimi tenant panelinde henüz açılmadı. Erişimler Super Admin tarafından tanımlanır.');
    }

    /**
     * Export / Web Feed ekranı placeholder
     */
    public function exports(): View
    {
        $this->abortTenantAccess();

        $exports = [
            [
                'id' => 1,
                'name' => 'XML Export',
                'description' => 'XML formatında ürün dışa aktarımı',
                'type' => 'xml',
                'status' => 'active',
                'is_premium' => false,
                'last_export' => now()->subHours(2),
                'export_count' => 8930,
            ],
            [
                'id' => 2,
                'name' => 'JSON Export',
                'description' => 'JSON formatında ürün dışa aktarımı',
                'type' => 'json',
                'status' => 'active',
                'is_premium' => false,
                'last_export' => now()->subHours(1),
                'export_count' => 8930,
            ],
            [
                'id' => 3,
                'name' => 'CSV Export',
                'description' => 'CSV formatında ürün dışa aktarımı',
                'type' => 'csv',
                'status' => 'active',
                'is_premium' => false,
                'last_export' => now()->subMinutes(30),
                'export_count' => 8930,
            ],
            [
                'id' => 4,
                'name' => 'API Feed',
                'description' => 'REST API üzerinden ürün feed\'i',
                'type' => 'api',
                'status' => 'premium',
                'is_premium' => true,
                'last_export' => null,
                'export_count' => 0,
            ],
            [
                'id' => 5,
                'name' => 'Real-time Sync',
                'description' => 'Gerçek zamanlı ürün senkronizasyonu',
                'type' => 'realtime',
                'status' => 'premium',
                'is_premium' => true,
                'last_export' => null,
                'export_count' => 0,
            ],
        ];

        return view('admin.product-data-hub.exports', compact('exports'));
    }

    /**
     * Senkron logları ve hata logları placeholder
     */
    public function logs(): View
    {
        $this->abortTenantAccess();
        abort(403, 'Tenant senkron log ekranı henüz açılmadı. Ayrıntılı loglar Super Admin veri operasyon alanından izlenir.');
    }

    /**
     * Şimdilik gerçek senkron yapma
     */
    public function sync(Request $request): RedirectResponse
    {
        $this->abortTenantAccess();
    }

    private function currentTenant()
    {
        return request()->attributes->get('current_tenant')
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->where('status', 'active')->first()
            ?? TenantAccount::query()->whereIn('status', ['active', 'trial'])->orderBy('created_at')->first();
    }

    private function allowedSupplierIds(?TenantAccount $tenant)
    {
        if (!$tenant) {
            return collect();
        }

        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->pluck('supplier_id');
    }

    private function tenantCanAccessSupplier(?TenantAccount $tenant, int $supplierId): bool
    {
        return $this->allowedSupplierIds($tenant)->contains($supplierId);
    }

    private function ensureCategoryMappingAccess(SupplierCategoryMapping $mapping, ?TenantAccount $tenant): void
    {
        if (!$this->tenantCanAccessSupplier($tenant, $mapping->supplier_id)) {
            abort(403, 'Bu kategori eşleme kaydına erişim izniniz yok.');
        }

        // TODO: supplier_category_mappings global tabloda tutuluyor.
        // Super Admin tarafında global mapping yönetimi ayrı policy ile ele alınmalı.
    }

    private function abortTenantAccess(): never
    {
        abort(403, 'Product Data Hub teknik ekranı yalnız Super Admin tarafından yönetilir.');
    }
}
