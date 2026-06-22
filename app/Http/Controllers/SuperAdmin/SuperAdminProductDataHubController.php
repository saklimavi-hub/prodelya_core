<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\FeedSyncLog;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardProduct;
use App\Models\StandardCategory;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantSupplierAccess;
use App\Support\ProductDisplayNameFormatter;
use App\Services\ProductDataHub\PreviewParserService;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SuperAdminProductDataHubController extends Controller
{
    public function __construct()
    {
        // TODO: Add middleware for super admin product data hub
        // $this->middleware('permission:manage_product_data_hub');
    }

    /**
     * Display Super Admin Product Data Hub management
     */
    public function index(): View
    {
        $globalSources = $this->getGlobalSources();
        $tenants = $this->getTenants();
        $accessMatrix = $this->buildAccessMatrix($tenants, $globalSources);
        $pipelineCounts = $this->pipelineCounts();
        $processSteps = $this->pipelineSteps($pipelineCounts);
        $catalogOutput = $this->catalogOutputStats();

        $platformStats = [
            'global_sources' => $globalSources->count(),
            'active_tenants' => $tenants->where('status', 'active')->count(),
            'total_access' => $this->calculateTotalAccess($accessMatrix),
            'feed_limit_warnings' => $accessMatrix->where('feed_limit_warning', true)->count(),
            'export_addons' => $tenants->filter(fn ($tenant) => $this->moduleEnabled($tenant, 'export_web_feed'))->count(),
            'permanent_categories' => $pipelineCounts['permanent_categories'],
            'archived_categories' => $pipelineCounts['archived_categories'],
            'pending_category_mappings' => $pipelineCounts['category_mapping_pending'],
            'pending_standard_product_categories' => $pipelineCounts['pending_standard_product_categories'],
            'pending_tenant_catalog_categories' => $pipelineCounts['pending_tenant_catalog_categories'],
            'last_category_reset_backup' => $this->latestCategoryBackupPath(),
        ];

        $packageRules = [
            'local_products' => ['Starter' => true, 'Promotion' => true, 'Suite' => true, 'Enterprise' => true],
            'global_feeds' => ['Starter' => false, 'Promotion' => true, 'Suite' => true, 'Enterprise' => true],
            'advanced_catalog' => ['Starter' => false, 'Promotion' => false, 'Suite' => true, 'Enterprise' => true],
            'export_feed' => ['Starter' => false, 'Promotion' => false, 'Suite' => false, 'Enterprise' => true],
        ];

        $tenantEditableSettings = [
            'price_multiplier' => true,
            'safety_stock' => true,
            'catalog_visibility' => true,
            'local_product_import' => true,
        ];

        $superAdminSettings = [
            'field_mapping_templates' => true,
            'category_tree' => true,
            'sync_policy' => true,
            'export_modules' => true,
        ];

        $summary = [
            'global_sources' => $globalSources->count(),
            'active_tenants' => $tenants->where('status', 'active')->count(),
            'tenant_access' => $this->calculateTotalAccess($accessMatrix),
            'export_permission' => $platformStats['export_addons'],
            'limit_warnings' => $platformStats['feed_limit_warnings'],
        ];

        return view('super-admin.product-data-hub.index', compact(
            'globalSources',
            'tenants',
            'accessMatrix',
            'platformStats',
            'packageRules',
            'tenantEditableSettings',
            'superAdminSettings',
            'summary',
            'processSteps',
            'catalogOutput'
        ));
    }

    public function pipeline(): View
    {
        $globalSources = $this->getGlobalSources();
        $tenants = $this->getTenants();
        $accessMatrix = $this->buildAccessMatrix($tenants, $globalSources);
        $counts = $this->pipelineCounts();
        $steps = $this->pipelineSteps($counts);
        $supplierPipelineRows = $this->supplierPipelineRows();

        $summary = [
            'global_sources' => $counts['supplier_sources'],
            'active_sources' => $counts['active_supplier_sources'],
            'preview_attempts' => $counts['preview_attempts'],
            'raw_products' => $counts['supplier_products_raw'],
            'standard_products' => $counts['standard_products'],
            'tenant_catalog_products' => $counts['tenant_catalog_products'],
            'category_mapping_pending' => $counts['category_mapping_pending'],
            'field_mapping_missing' => $counts['field_mapping_missing'],
        ];

        $superAdminActions = [
            'Global tedarikçi kaynaklarını ekler',
            'XML / JSON / CSV / API kaynaklarını test eder',
            'Alan eşleme şablonlarını yönetir',
            'Standart kategori ağacını yönetir',
            'Tedarikçi kategori eşlemelerini yapar',
            'Ham ürünleri standart ürüne dönüştürür',
            'Tenant’a tedarikçi erişimi verir',
            'Paket/modül ve feed limitlerini belirler',
            'Export / Web Feed izinlerini açar',
        ];

        $tenantUsage = [
            'Sadece kendisine açılan tedarikçileri görür',
            'Gelişmiş Ürün ve Katalog ekranını kullanır',
            'Local / kendi ürünlerini ekler',
            'Local stoklarını yönetir',
            'Güvenli stok ve katalog görünürlüğü gibi izinli ayarları yönetir',
            'Teklif oluştururken sadece izinli katalog ürünlerini görür',
            'Global XML/API kaynaklarını değiştiremez',
        ];

        $sourceOnboardingSteps = [
            'Super Admin → Global Tedarikçi Kaynakları',
            'Yeni Kaynak Ekle',
            'Tedarikçi seç: Etkin / Akdeniz / İlpen / Yeni Nesil',
            'Kaynak tipi seç: XML / JSON / CSV / API',
            'Kaynak URL / dosya yolu gir',
            'Ürün node path gir: Akdeniz → RECORD, Yeni Nesil → urunler, İlpen → Urun',
            'Prefix belirle: ET / AK / IL / YN',
            'Kaynak test et',
            'Preview al',
            'Alan ve kategori eşlemeyi tamamla',
        ];

        $imageRules = [
            'Ana ürün görseli image_url / parent_image_url olarak alınır.',
            'Varyasyon görseli variant_image_url olarak alınır.',
            'Akdeniz’de stokresim varsa varyasyon görselidir.',
            'Akdeniz’de urunresim, urunresim1-13 galeri olarak saklanabilir.',
            'İlpen’de VaryasyonResim boşsa ResimUrl fallback olarak kullanılır.',
            'Görsel fallback kullanıldıysa uyarı oluşur.',
            'Çoklu ürün görseli ve varyasyon galeri yönetimi sonraki Product Media aşamasında standard_product_images ve tenant_catalog_product_images yapısıyla geliştirilecek.',
        ];

        return view('super-admin.product-data-hub.pipeline', compact(
            'counts',
            'steps',
            'summary',
            'superAdminActions',
            'tenantUsage',
            'sourceOnboardingSteps',
            'imageRules',
            'accessMatrix',
            'globalSources',
            'supplierPipelineRows'
        ));
    }

    public function catalogOutput(): View
    {
        $catalogOutput = $this->catalogOutputStats();
        $counts = $this->pipelineCounts();
        $processSteps = $this->pipelineSteps($counts);
        $supplierRows = $this->supplierPipelineRows();

        return view('super-admin.product-data-hub.catalog-output', [
            'catalogOutput' => $catalogOutput,
            'processSteps' => $processSteps,
            'supplierRows' => $supplierRows,
        ]);
    }

    public function commonProducts(Request $request): View
    {
        $limit = $this->normalizeProductHubLimit($request->string('limit')->toString() ?: '50');
        $page = max(1, $request->integer('page', 1));

        $baseQuery = StandardProduct::query();
        $this->applyCommonProductQueryFilters($baseQuery, $request);

        $perPage = $limit === 'all' ? 2000 : (int) $limit;
        $productPaginator = (clone $baseQuery)
            ->with([
                'category',
                'supplier',
                'variants.tenantCatalogVariants',
                'tenantCatalogProducts.variants',
                'primaryImage',
            ])
            ->latest('updated_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $rows = $productPaginator->getCollection()
            ->flatMap(fn (StandardProduct $product) => $this->buildCommonProductRows($product))
            ->values();

        $supplierOptions = $this->commonProductSupplierOptions();

        $filtered = $rows
            ->when($request->filled('product_type'), fn (Collection $collection) => $collection->where('product_type', $request->string('product_type')->toString()))
            ->when($request->filled('sellable'), function (Collection $collection) use ($request) {
                return match ($request->string('sellable')->toString()) {
                    'sellable' => $collection->where('satilabilir_mi', true),
                    'catalog_group' => $collection->where('product_type', 'parent'),
                    'quote_hidden' => $collection->where('teklifte_gorunur_mu', false),
                    default => $collection,
                };
            })
            ->when($request->filled('category_status'), fn (Collection $collection) => $collection->where('kategori_esleme_durumu', $request->string('category_status')->toString()))
            ->when($request->filled('price_status'), fn (Collection $collection) => $collection->filter(fn (array $row) => in_array($request->string('price_status')->toString(), $row['price_status_tags'], true)))
            ->when($request->filled('stock_status'), fn (Collection $collection) => $collection->where('stok_durumu', $request->string('stock_status')->toString()))
            ->when($request->filled('warning_status'), fn (Collection $collection) => $collection->filter(fn (array $row) => in_array($request->string('warning_status')->toString(), $row['warning_tags'], true)))
            ->when($request->filled('tenant_output'), fn (Collection $collection) => $collection->where('tenant_katalog_durumu', $request->string('tenant_output')->toString()))
            ->when($request->filled('q'), function (Collection $collection) use ($request) {
                $needle = mb_strtolower(trim($request->string('q')->toString()));

                return $collection->filter(function (array $row) use ($needle) {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $row['urun_kodu'] ?? null,
                        $row['urun_adi'] ?? null,
                        $row['parent_grup_kodu'] ?? null,
                        $row['varyant_kodu'] ?? null,
                        $row['urun_renk'] ?? null,
                        $row['urun_olcu'] ?? null,
                        $row['urun_ebat'] ?? null,
                        $row['supplier_product_code'] ?? null,
                        $row['supplier_group_code'] ?? null,
                        $row['generated_group_code'] ?? null,
                    ])));

                    return str_contains($haystack, $needle);
                });
            })
            ->values();

        $totalCommonProducts = StandardProduct::query()->count();
        $projectedCommonProducts = TenantCatalogProduct::query()
            ->whereNotNull('standard_product_id')
            ->distinct('standard_product_id')
            ->count('standard_product_id');

        $stats = [
            'total' => $totalCommonProducts,
            'parent' => StandardProduct::query()->has('variants')->count(),
            'variant' => StandardProductVariant::query()->count(),
            'flat' => StandardProduct::query()->doesntHave('variants')->count(),
            'sellable' => StandardProduct::query()->doesntHave('variants')->count() + StandardProductVariant::query()->count(),
            'catalog_only' => StandardProduct::query()->has('variants')->count(),
            'projected' => $projectedCommonProducts,
            'blocked' => TenantCatalogProduct::query()->whereIn('catalog_status', ['missing_category', 'missing_price', 'blocked', 'inactive_candidate', 'missing_from_feed'])->count(),
        ];

        $paginator = new LengthAwarePaginator(
            $filtered,
            $productPaginator->total(),
            $productPaginator->perPage(),
            $productPaginator->currentPage(),
            [
                'path' => url()->current(),
                'query' => $request->query(),
            ]
        );

        $selectedKey = $request->string('selected')->toString();
        $selectedRow = $filtered->firstWhere('row_key', $selectedKey) ?: $filtered->first();

        return view('super-admin.product-data-hub.common-products', [
            'rows' => $paginator,
            'stats' => $stats,
            'supplierOptions' => $supplierOptions,
            'filters' => $request->only([
                'supplier', 'product_type', 'sellable', 'category_status', 'price_status', 'stock_status', 'warning_status', 'tenant_output', 'q', 'limit',
            ]),
            'selectedRow' => $selectedRow,
            'showAllWarning' => $limit === 'all',
        ]);
    }

    public function productPanel(Request $request): View
    {
        $filters = $this->superProductPanelFilters($request);
        $query = DB::query()->fromSub($this->superProductPanelBaseRowsQuery(), 'product_panel_rows');

        if ($filters['search'] !== '') {
            $term = '%' . Str::lower($filters['search']) . '%';
            $query->where(function ($inner) use ($term) {
                foreach (['product_code', 'raw_product_name', 'supplier_name', 'supplier_category_name', 'matched_category_name', 'source_summary_json', 'meta_json'] as $column) {
                    $inner->orWhereRaw('LOWER(COALESCE(' . $column . ", '')) LIKE ?", [$term]);
                }
            });
        }

        if ($filters['supplier']) {
            $query->where('supplier_id', $filters['supplier']);
        }

        if ($filters['category']) {
            $query->where('standard_category_id', $filters['category']);
        }

        if ($filters['category_status'] === 'matched') {
            $query->whereNotNull('standard_category_id')
                ->where('matched_category_name', 'not like', '%Bekleyen%')
                ->where('meta_json', 'not like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%');
        }

        if ($filters['category_status'] === 'category_waiting') {
            $query->where(function ($inner) {
                $inner->whereNull('standard_category_id')
                    ->orWhere('matched_category_name', 'like', '%Bekleyen%')
                    ->orWhere('meta_json', 'like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%')
                    ->orWhere('meta_json', 'like', '%category_missing%');
            });
        }

        if ($filters['category_status'] === 'target_missing') {
            $query->where(function ($inner) {
                $inner->where('meta_json', 'like', '%category_conflict%')
                    ->orWhere('meta_json', 'like', '%target_missing%')
                    ->orWhere('meta_json', 'like', '%archived%');
            });
        }

        if ($filters['category_status'] === 'warning') {
            $this->applySuperProductPanelWarningWhere($query);
        }

        if ($filters['stock_state'] === 'in_stock') {
            $query->where('stock_quantity', '>', 0);
        }

        if ($filters['stock_state'] === 'out_of_stock') {
            $query->where('stock_quantity', '<=', 0);
        }

        if ($filters['price_state'] === 'available') {
            $query->whereNotNull('price_value')->where('price_value', '>', 0);
        }

        if ($filters['price_state'] === 'missing') {
            $query->where(function ($inner) {
                $inner->whereNull('price_value')->orWhere('price_value', '<=', 0);
            });
        }

        if ($filters['image_state'] === 'available') {
            $query->whereNotNull('image_url');
        }

        if ($filters['image_state'] === 'missing') {
            $query->whereNull('image_url');
        }

        if ($filters['warning_state'] === 'warning') {
            $this->applySuperProductPanelWarningWhere($query);
        }

        if ($filters['warning_state'] === 'clean') {
            $query->where(function ($inner) {
                $inner->whereNull('warning_flag')->orWhere('warning_flag', false);
            })
                ->whereNotNull('image_url')
                ->whereNotNull('standard_category_id')
                ->whereNotNull('price_value')
                ->where('price_value', '>', 0)
                ->where('stock_quantity', '>', 0);
        }

        $total = (clone $query)->count();
        $page = max(1, $request->integer('page', 1));
        $rows = $query
            ->orderByDesc('updated_at')
            ->offset(($page - 1) * $filters['limit'])
            ->limit($filters['limit'])
            ->get();

        $hydratedRows = $this->hydrateSuperProductPanelRows($rows);
        $paginator = new LengthAwarePaginator(
            $hydratedRows,
            $total,
            $filters['limit'],
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $stats = [
            'total' => $total,
            'sellable' => StandardProduct::query()->doesntHave('variants')->count()
                + StandardProductVariant::query()->where('is_active', true)->where('visible_in_catalog', true)->count(),
            'with_warning' => (clone DB::query()->fromSub($this->superProductPanelBaseRowsQuery(), 'warning_rows'))
                ->where(function ($inner) {
                    $this->applySuperProductPanelWarningWhere($inner);
                })
                ->count(),
        ];

        return view('super-admin.product-data-hub.product-panel', [
            'rows' => $paginator,
            'filters' => $filters,
            'stats' => $stats,
            'suppliers' => Supplier::query()->whereHas('standardProducts')->orderBy('name')->get(),
            'categories' => StandardCategory::query()->permanentBackbone()->orderBy('path')->get(),
            'categoryMappingDrawer' => $this->resolveProductPanelCategoryMappingDrawer($request, $hydratedRows),
        ]);
    }

    public function saveProductPanelCategoryMapping(Request $request, SupplierCategoryMapping $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'standard_category_id' => 'required|integer|exists:standard_categories,id',
        ]);

        $category = StandardCategory::query()
            ->permanentBackbone()
            ->findOrFail((int) $validated['standard_category_id']);

        $previousCategoryId = $mapping->standard_category_id;
        $note = 'Ürün paneli hızlı eşleme ile kaydedildi.';

        $mapping->forceFill([
            'standard_category_id' => $category->id,
            'target_category' => $category->full_path,
            'mapping_status' => 'approved',
            'decision_type' => 'map',
            'decision_note' => $note,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'is_active' => true,
        ])->save();

        SupplierCategoryMappingLog::query()->create([
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => $previousCategoryId,
            'new_standard_category_id' => $category->id,
            'action' => 'approved',
            'reason' => $note,
            'changed_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.product-panel', $this->productPanelRedirectQuery($request))
            ->with('success', 'Kategori eşlendi. Ürün listesine yansıtma ayrı adımda yapılır.')
            ->with('product_panel_category_mapping_saved', true);
    }

    private function applyCommonProductQueryFilters($query, Request $request): void
    {
        $query
            ->when($request->filled('supplier'), function ($query) use ($request) {
                $supplier = $request->string('supplier')->toString();

                $query->where(function ($query) use ($supplier) {
                    $query->whereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', $supplier))
                        ->orWhereHas('rawProducts.supplier', fn ($supplierQuery) => $supplierQuery->where('name', $supplier));
                });
            })
            ->when($request->filled('product_type'), function ($query) use ($request) {
                match ($request->string('product_type')->toString()) {
                    'parent', 'variant' => $query->has('variants'),
                    'flat' => $query->doesntHave('variants'),
                    default => null,
                };
            })
            ->when($request->filled('sellable'), function ($query) use ($request) {
                match ($request->string('sellable')->toString()) {
                    'sellable' => $query->where(function ($query) {
                        $query->doesntHave('variants')->orWhereHas('variants');
                    }),
                    'catalog_group', 'quote_hidden' => $query->has('variants'),
                    default => null,
                };
            })
            ->when($request->filled('category_status'), function ($query) use ($request) {
                match ($request->string('category_status')->toString()) {
                    'mapped' => $query->whereNotNull('standard_category_id')
                        ->whereHas('category', fn ($categoryQuery) => $categoryQuery
                            ->permanentBackbone()
                            ->where('code', '!=', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')),
                    'category_missing' => $query->where(function ($query) {
                        $query->whereNull('standard_category_id')
                            ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                                ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
                                ->orWhere(fn ($nested) => $nested->archived()));
                    }),
                    'override' => $query->where(function ($query) {
                        $query->where('meta->category_override_name', '!=', null)
                            ->orWhere('meta->category_override_standard_category_id', '!=', null);
                    }),
                    'conflict' => $query->where('meta->projection_status', 'category_conflict'),
                    default => null,
                };
            })
            ->when($request->filled('price_status'), function ($query) use ($request) {
                match ($request->string('price_status')->toString()) {
                    'price_available' => $query->whereNotNull('min_purchase_price'),
                    'price_missing' => $query->whereNull('min_purchase_price'),
                    'net_price' => $query->where('meta->price_snapshot->net_price_warning', true),
                    'fixed_price' => $query->where(function ($query) {
                        $query->where('meta->price_snapshot->price_policy_warning', true)
                            ->orWhere('meta->price_snapshot->supplier_warning_flag', true);
                    }),
                    default => null,
                };
            })
            ->when($request->filled('stock_status'), function ($query) use ($request) {
                match ($request->string('stock_status')->toString()) {
                    'in_stock' => $query->where('total_stock_quantity', '>', 0),
                    'out_of_stock' => $query->where(function ($query) {
                        $query->where('total_stock_quantity', '<=', 0)->orWhereNull('total_stock_quantity');
                    }),
                    'stock_unknown' => $query->whereNull('total_stock_quantity'),
                    default => null,
                };
            })
            ->when($request->filled('warning_status'), function ($query) use ($request) {
                match ($request->string('warning_status')->toString()) {
                    'red_product' => $query->where('warning_flag', true),
                    'net_price' => $query->where('meta->price_snapshot->net_price_warning', true),
                    'clean' => $query->where(function ($query) {
                        $query->where('warning_flag', false)->orWhereNull('warning_flag');
                    }),
                    default => null,
                };
            })
            ->when($request->filled('tenant_output'), function ($query) use ($request) {
                match ($request->string('tenant_output')->toString()) {
                    'projected' => $query->whereHas('tenantCatalogProducts'),
                    'not_projected' => $query->doesntHave('tenantCatalogProducts'),
                    'blocked' => $query->whereHas('tenantCatalogProducts', fn ($catalogQuery) => $catalogQuery->whereIn('catalog_status', ['missing_category', 'missing_price', 'blocked', 'inactive_candidate', 'missing_from_feed'])),
                    default => null,
                };
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $needle = '%' . trim($request->string('q')->toString()) . '%';

                $query->where(function ($query) use ($needle) {
                    $query->where('standard_product_code', 'like', $needle)
                        ->orWhere('sku', 'like', $needle)
                        ->orWhere('product_name', 'like', $needle)
                        ->orWhere('base_product_name', 'like', $needle)
                        ->orWhere('name', 'like', $needle)
                        ->orWhere('category', 'like', $needle)
                        ->orWhereHas('variants', function ($variantQuery) use ($needle) {
                            $variantQuery->where('variant_code', 'like', $needle)
                                ->orWhere('generated_variant_code', 'like', $needle)
                                ->orWhere('variant_color', 'like', $needle)
                                ->orWhere('variant_size', 'like', $needle);
                        });
                });
            });
    }

    private function normalizeProductHubLimit(string $limit): string
    {
        return in_array($limit, ['50', '100', '250', '500', 'all'], true) ? $limit : '50';
    }

    private function superProductPanelFilters(Request $request): array
    {
        $limit = (int) $this->normalizeProductPanelLimit($request->string('limit')->toString() ?: '50');

        return [
            'search' => trim($request->string('search')->toString()),
            'supplier' => $request->integer('supplier') ?: null,
            'category' => $request->integer('category') ?: null,
            'category_status' => $request->string('category_status')->toString(),
            'stock_state' => $request->string('stock_state')->toString(),
            'price_state' => $request->string('price_state')->toString(),
            'image_state' => $request->string('image_state')->toString(),
            'warning_state' => $request->string('warning_state')->toString(),
            'limit' => $limit,
            'technical_columns' => $request->boolean('technical_columns'),
        ];
    }

    private function normalizeProductPanelLimit(string $limit): string
    {
        return in_array($limit, ['50', '100', '250', '500'], true) ? $limit : '50';
    }

    private function superProductPanelBaseRowsQuery()
    {
        $flatRows = DB::table('standard_products as sp')
            ->leftJoin('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('standard_categories as sc', 'sc.id', '=', 'sp.standard_category_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('standard_product_variants as spv')
                    ->whereColumn('spv.standard_product_id', 'sp.id')
                    ->where('spv.is_active', true)
                    ->where('spv.visible_in_catalog', true);
            })
            ->selectRaw("
                'flat' as row_type,
                sp.id as standard_product_id,
                null as standard_product_variant_id,
                sp.supplier_id as supplier_id,
                s.name as supplier_name,
                coalesce(sp.standard_product_code, sp.sku) as product_code,
                coalesce(sp.product_name, sp.base_product_name, sp.name) as raw_product_name,
                coalesce(sp.base_product_name, sp.product_name, sp.name) as parent_product_name,
                null as variant_name,
                null as color,
                null as size,
                null as variant_attributes_json,
                sp.image_url as image_url,
                sp.standard_category_id as standard_category_id,
                coalesce(sc.path, sc.name) as matched_category_name,
                coalesce(sp.category, '') as supplier_category_name,
                coalesce(sp.total_stock_quantity, 0) as stock_quantity,
                sp.min_purchase_price as price_value,
                coalesce(sp.currency, 'TL') as currency,
                sp.warning_flag as warning_flag,
                sp.source_summary as source_summary_json,
                sp.meta as meta_json,
                sp.visible_in_catalog as visible_in_catalog,
                sp.is_active as is_active,
                sp.updated_at as updated_at
            ");

        $variantRows = DB::table('standard_product_variants as spv')
            ->join('standard_products as sp', 'sp.id', '=', 'spv.standard_product_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('standard_categories as sc', 'sc.id', '=', 'sp.standard_category_id')
            ->where('spv.is_active', true)
            ->where('spv.visible_in_catalog', true)
            ->selectRaw("
                'variant' as row_type,
                sp.id as standard_product_id,
                spv.id as standard_product_variant_id,
                sp.supplier_id as supplier_id,
                s.name as supplier_name,
                coalesce(spv.generated_variant_code, spv.variant_code, sp.standard_product_code, sp.sku) as product_code,
                coalesce(sp.product_name, sp.base_product_name, sp.name) as raw_product_name,
                coalesce(sp.base_product_name, sp.product_name, sp.name) as parent_product_name,
                spv.variant_name as variant_name,
                spv.variant_color as color,
                spv.variant_size as size,
                spv.variant_attributes as variant_attributes_json,
                coalesce(spv.image_url, sp.image_url) as image_url,
                sp.standard_category_id as standard_category_id,
                coalesce(sc.path, sc.name) as matched_category_name,
                coalesce(sp.category, '') as supplier_category_name,
                coalesce(spv.stock_quantity, 0) as stock_quantity,
                coalesce(spv.min_purchase_price, sp.min_purchase_price) as price_value,
                coalesce(sp.currency, 'TL') as currency,
                sp.warning_flag as warning_flag,
                coalesce(spv.source_summary, sp.source_summary) as source_summary_json,
                coalesce(spv.meta, sp.meta) as meta_json,
                spv.visible_in_catalog as visible_in_catalog,
                spv.is_active as is_active,
                spv.updated_at as updated_at
            ");

        return $flatRows->unionAll($variantRows);
    }

    private function hydrateSuperProductPanelRows(Collection $rows): Collection
    {
        return $rows->map(function (object $row) {
            $sourceSummary = $this->decodePanelJson($row->source_summary_json);
            $primarySource = array_is_list($sourceSummary) ? ($sourceSummary[0] ?? []) : $sourceSummary;
            $meta = $this->decodePanelJson($row->meta_json);
            $variantAttributes = $this->decodePanelJson($row->variant_attributes_json);
            $productCode = (string) $row->product_code;
            $displayCode = ProductDisplayNameFormatter::format([
                'product_code' => $productCode,
                'sku' => $productCode,
            ])['display_code'];

            $displayName = $row->row_type === 'variant'
                ? ProductDisplayNameFormatter::variant(
                    $productCode,
                    $row->parent_product_name,
                    $row->variant_name ?: $row->raw_product_name,
                    $row->color,
                    $row->size,
                    data_get($variantAttributes, 'measure'),
                    data_get($variantAttributes, 'capacity'),
                    data_get($variantAttributes, 'option'),
                    [
                        data_get($primarySource, 'variant_stock_code'),
                        data_get($primarySource, 'supplier_product_code'),
                        data_get($primarySource, 'supplier_group_code'),
                        data_get($meta, 'parent_product_code'),
                    ]
                )
                : ProductDisplayNameFormatter::format([
                    'supplier_name' => $row->supplier_name,
                    'product_code' => $productCode,
                    'sku' => $productCode,
                    'supplier_product_code' => data_get($primarySource, 'supplier_product_code'),
                    'supplier_group_code' => data_get($primarySource, 'supplier_group_code'),
                    'raw_product_name' => $row->raw_product_name,
                    'category_name' => $row->matched_category_name ?: $row->supplier_category_name,
                    'source_summary' => $sourceSummary,
                    'meta' => $meta,
                ])['display_name'];

            $warnings = [];

            if (blank($row->matched_category_name) || blank($row->standard_category_id) || data_get($meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN') {
                $warnings[] = 'Kategori Bekliyor';
            }

            if (blank($row->price_value) || (float) $row->price_value <= 0) {
                $warnings[] = 'Fiyat Eksik';
            }

            if ((float) $row->stock_quantity <= 0) {
                $warnings[] = 'Stok Yok';
            }

            if (blank($row->image_url)) {
                $warnings[] = 'Resim Eksik';
            }

            if ((bool) $row->warning_flag || !empty(data_get($meta, 'warning_snapshot', [])) || !empty(data_get($meta, 'warnings', [])) || (bool) data_get($meta, 'net_price_warning', false) || (bool) data_get($meta, 'supplier_warning_flag', false)) {
                $warnings[] = 'Uyarılı';
            }

            $categoryStatus = 'Eşleşmiş';

            if (blank($row->standard_category_id) || in_array('Kategori Bekliyor', $warnings, true)) {
                $categoryStatus = 'Kategori Bekliyor';
            } elseif ((bool) data_get($meta, 'category_conflict', false) || str_contains((string) json_encode($meta, JSON_UNESCAPED_UNICODE), 'target_missing')) {
                $categoryStatus = 'Hedef Bulunamayan';
            } elseif (in_array('Uyarılı', $warnings, true)) {
                $categoryStatus = 'Uyarılı';
            }

            return [
                'row_type' => $row->row_type,
                'standard_product_id' => (int) $row->standard_product_id,
                'standard_product_variant_id' => $row->standard_product_variant_id ? (int) $row->standard_product_variant_id : null,
                'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                'supplier_name' => $row->supplier_name ?: '-',
                'product_code' => $productCode,
                'display_code' => $displayCode,
                'display_name' => $displayName,
                'image_url' => $row->image_url,
                'supplier_category_name' => $row->supplier_category_name ?: '-',
                'matched_category_name' => $row->matched_category_name ?: '-',
                'price' => $row->price_value,
                'currency' => $row->currency ?: 'TL',
                'stock_quantity' => (float) $row->stock_quantity,
                'color' => $row->color,
                'size' => $row->size,
                'measure' => data_get($variantAttributes, 'measure') ?: data_get($variantAttributes, 'capacity') ?: data_get($variantAttributes, 'option'),
                'warnings' => array_values(array_unique($warnings)),
                'category_status' => $categoryStatus,
                'status_label' => (bool) $row->is_active ? 'Aktif' : 'Pasif',
                'group_code' => data_get($primarySource, 'supplier_group_code'),
                'supplier_source_id' => data_get($primarySource, 'supplier_source_id'),
                'supplier_category_path' => data_get($primarySource, 'supplier_category_path', data_get($meta, 'supplier_category_path')),
                'category_action_required' => in_array($categoryStatus, ['Kategori Bekliyor', 'Hedef Bulunamayan'], true),
                'detail_link' => route('admin.super.product-data-hub.common-products', ['q' => $productCode]),
                'standard_link' => route('admin.super.product-data-hub.standard-products.index', ['q' => $productCode]),
            ];
        });
    }

    private function resolveProductPanelCategoryMappingDrawer(Request $request, Collection $rows): ?array
    {
        $productId = $request->integer('category_mapping_product_id');

        if ($productId <= 0) {
            return null;
        }

        $row = $rows->first(fn (array $item) => (int) ($item['standard_product_id'] ?? 0) === $productId);

        if (!$row) {
            $rawRow = DB::query()
                ->fromSub($this->superProductPanelBaseRowsQuery(), 'product_panel_rows')
                ->where('standard_product_id', $productId)
                ->orderByDesc('updated_at')
                ->first();

            if (!$rawRow) {
                return null;
            }

            $row = $this->hydrateSuperProductPanelRows(collect([$rawRow]))->first();
        }

        if (!$row || empty($row['supplier_category_name']) || $row['supplier_category_name'] === '-') {
            return null;
        }

        $mapping = $this->resolveProductPanelSupplierCategoryMapping($row);

        if (!$mapping) {
            return null;
        }

        return [
            'product' => $row,
            'mapping' => $mapping,
            'suggestion_path' => $mapping->standardCategory?->full_path ?: $mapping->target_category,
            'suggestion_reason' => data_get($mapping->suggestion_meta, 'reason')
                ?: data_get($mapping->suggestion_meta, 'suggestion_reason_text')
                ?: $mapping->description
                ?: 'Sistem önerisi mevcutsa hızlı eşleme için kullanılabilir.',
            'confidence_label' => $mapping->confidence_score !== null ? number_format((float) $mapping->confidence_score, 0, ',', '.') . '/100' : 'Skor yok',
            'sample_products' => array_slice((array) ($mapping->sample_product_names ?? []), 0, 3),
            'sample_images' => array_slice((array) ($mapping->sample_image_urls ?? []), 0, 3),
            'advanced_link' => route('admin.super.product-data-hub.category-mappings.index', [
                'mode' => 'simple',
                'q' => $row['product_code'],
            ]),
            'save_action' => route('admin.super.product-data-hub.product-panel.category-mappings.store', $mapping),
            'cancel_link' => route('admin.super.product-data-hub.product-panel', $this->productPanelRedirectQuery($request)),
        ];
    }

    private function resolveProductPanelSupplierCategoryMapping(array $row): ?SupplierCategoryMapping
    {
        $query = SupplierCategoryMapping::query()
            ->with(['standardCategory', 'supplier', 'source'])
            ->where('supplier_id', $row['supplier_id'] ?? null)
            ->where('source_category', $row['supplier_category_name']);

        if (!empty($row['supplier_source_id'])) {
            $query->where(function ($builder) use ($row) {
                $builder->whereNull('supplier_source_id')
                    ->orWhere('supplier_source_id', $row['supplier_source_id']);
            });
        }

        $path = trim((string) ($row['supplier_category_path'] ?? ''));

        if ($path !== '') {
            $query->orderByRaw('CASE WHEN supplier_category_path = ? THEN 0 ELSE 1 END', [$path]);
        }

        return $query
            ->orderByDesc('confidence_score')
            ->orderByDesc('id')
            ->first();
    }

    private function productPanelRedirectQuery(Request $request): array
    {
        return Arr::except($request->query(), ['category_mapping_product_id']);
    }

    private function applySuperProductPanelWarningWhere($query): void
    {
        $query->where(function ($inner) {
            $inner->whereNull('price_value')
                ->orWhere('price_value', '<=', 0)
                ->orWhereNull('image_url')
                ->orWhereNull('standard_category_id')
                ->orWhere('matched_category_name', 'like', '%Bekleyen%')
                ->orWhere('meta_json', 'like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%')
                ->orWhere('meta_json', 'like', '%warning%')
                ->orWhere('meta_json', 'like', '%net_price%')
                ->orWhere('meta_json', 'like', '%supplier_warning%')
                ->orWhere('stock_quantity', '<=', 0)
                ->orWhere('warning_flag', true);
        });
    }

    private function decodePanelJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function commonProductSupplierOptions(): Collection
    {
        return Supplier::query()
            ->whereHas('standardProducts')
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->values();
    }

    private function buildCommonProductRows(StandardProduct $product): Collection
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->get();

        $tenantCatalogProducts = $product->relationLoaded('tenantCatalogProducts')
            ? $product->tenantCatalogProducts
            : $product->tenantCatalogProducts()->with('variants')->get();

        $supplierName = $this->resolveCommonSupplierName($product);
        $baseRow = $this->buildBaseCommonRow($product, $supplierName);

        if ($variants->isEmpty()) {
            $catalogProduct = $tenantCatalogProducts->first();

            return collect([
                array_merge($baseRow, [
                    'row_key' => 'product-' . $product->id,
                    'product_type' => 'flat',
                    'satilabilir_mi' => true,
                    'teklifte_gorunur_mu' => (bool) ($catalogProduct?->visible_in_quote ?? true),
                    'tenant_katalog_durumu' => $this->resolveTenantOutputStatus($catalogProduct),
                    'tenant_output_label' => $this->resolveTenantOutputLabel($catalogProduct),
                    'visible_in_catalog' => (bool) ($catalogProduct?->visible_in_catalog ?? $product->visible_in_catalog),
                    'visible_in_quote' => (bool) ($catalogProduct?->visible_in_quote ?? true),
                    'tenant_projection_count' => $tenantCatalogProducts->count(),
                    'urun_stok' => (float) ($catalogProduct?->total_stock_quantity ?? $product->total_stock_quantity ?? 0),
                    'urun_fiyat' => $catalogProduct?->display_price ?? data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price ?? $product->purchase_price),
                    'urun_kdv' => data_get($catalogProduct?->meta, 'price_snapshot.vat_rate', $product->vat_rate),
                    'urun_renk' => null,
                    'urun_olcu' => null,
                    'urun_ebat' => null,
                    'tenant_projection_label' => $catalogProduct?->catalog_status ?: 'Projection bekliyor',
                ]),
            ]);
        }

        $rows = collect([
            array_merge($baseRow, [
                'row_key' => 'product-' . $product->id,
                'product_type' => 'parent',
                'satilabilir_mi' => false,
                'teklifte_gorunur_mu' => false,
                'tenant_katalog_durumu' => $this->resolveTenantOutputStatus($tenantCatalogProducts->first()),
                'tenant_output_label' => 'Grup ürün',
                'visible_in_catalog' => (bool) ($tenantCatalogProducts->first()?->visible_in_catalog ?? $product->visible_in_catalog),
                'visible_in_quote' => false,
                'tenant_projection_count' => $tenantCatalogProducts->count(),
                'tenant_projection_label' => 'Katalog grup',
                'urun_stok' => (float) ($product->total_stock_quantity ?? 0),
                'urun_fiyat' => data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price ?? $product->purchase_price),
                'urun_kdv' => $product->vat_rate,
            ]),
        ]);

        foreach ($variants as $variant) {
            $catalogVariant = $variant->relationLoaded('tenantCatalogVariants')
                ? $variant->tenantCatalogVariants->first()
                : $variant->tenantCatalogVariants()->first();

            $rows->push(array_merge($baseRow, [
                'row_key' => 'variant-' . $variant->id,
                'product_type' => 'variant',
                'satilabilir_mi' => true,
                'teklifte_gorunur_mu' => (bool) data_get($catalogVariant?->meta, 'quote_search_visible', true),
                'tenant_katalog_durumu' => $this->resolveTenantOutputStatus($catalogVariant),
                'tenant_output_label' => $this->resolveTenantOutputLabel($catalogVariant),
                'visible_in_catalog' => (bool) ($catalogVariant?->visible_in_catalog ?? $variant->visible_in_catalog),
                'visible_in_quote' => (bool) data_get($catalogVariant?->meta, 'quote_search_visible', true),
                'tenant_projection_count' => $catalogVariant ? 1 : 0,
                'tenant_projection_label' => $catalogVariant ? 'Tenant varyant' : 'Projection bekliyor',
                'urun_kodu' => $variant->generated_variant_code ?: $variant->variant_code ?: $product->standard_product_code,
                'urun_adi' => $variant->display_name,
                'urun_resim' => $catalogVariant?->image_url ?: $variant->image_url ?: $product->image_url,
                'urun_stok' => (float) ($catalogVariant?->stock_quantity ?? $variant->stock_quantity ?? 0),
                'urun_fiyat' => $catalogVariant?->display_price ?? $variant->min_purchase_price ?? $product->min_purchase_price,
                'urun_renk' => $variant->variant_color,
                'urun_olcu' => $variant->variant_size,
                'urun_ebat' => data_get($variant->variant_attributes, 'measure') ?: data_get($variant->variant_attributes, 'capacity'),
                'urun_kdv' => data_get($catalogVariant?->meta, 'price_snapshot.vat_rate', data_get($variant->meta, 'price_snapshot.vat_rate', $product->vat_rate)),
                'varyant_kodu' => $variant->generated_variant_code ?: $variant->variant_code,
                'supplier_product_code' => data_get($variant->source_summary, 'variant_stock_code', data_get($variant->source_summary, 'supplier_product_code', $variant->variant_code)),
                'warning_tags' => $this->resolveWarningTags(
                    array_merge($baseRow['warning_tags'], $this->resolveWarningTagsFromVariant($variant, $catalogVariant))
                ),
                'price_status_tags' => $this->resolvePriceStatusTags($variant, $catalogVariant),
                'stok_durumu' => $this->resolveStockStatus((float) ($catalogVariant?->stock_quantity ?? $variant->stock_quantity ?? 0)),
            ]));
        }

        return $rows;
    }

    private function buildBaseCommonRow(StandardProduct $product, string $supplierName): array
    {
        $warningTags = $this->resolveWarningTagsFromProduct($product);
        $priceStatusTags = $this->resolvePriceStatusTags($product);
        $stockQuantity = (float) ($product->total_stock_quantity ?? 0);
        $sourceSummary = collect($product->source_summary ?? []);

        return [
            'standard_product_id' => $product->id,
            'urun_kodu' => $product->standard_product_code ?: $product->sku ?: '-',
            'urun_adi' => $product->display_name,
            'urun_resim' => $product->primaryImage?->image_url ?: $product->image_url,
            'urun_url' => $product->product_url,
            'urun_detay_url' => $product->detail_url,
            'urun_kategori' => $product->category_display_name,
            'urun_stok' => $stockQuantity,
            'urun_fiyat' => data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price ?? $product->purchase_price),
            'urun_renk' => null,
            'urun_aciklama' => $product->description,
            'urun_kdv' => $product->vat_rate,
            'urun_tedarikci' => $supplierName,
            'urun_olcu' => null,
            'urun_ebat' => null,
            'urun_kirmizi' => in_array('red_product', $warningTags, true),
            'urun_turuncu' => in_array('amber_product', $warningTags, true),
            'net_fiyat_uyarisi' => in_array('net_price', $warningTags, true),
            'kategori_esleme_durumu' => $this->resolveCategoryStatus($product),
            'parent_grup_kodu' => data_get($sourceSummary->first(), 'supplier_group_code') ?: ($product->standard_product_code ?: $product->sku),
            'generated_group_code' => $product->standard_product_code ?: $product->sku,
            'supplier_group_code' => data_get($sourceSummary->first(), 'supplier_group_code'),
            'varyant_kodu' => null,
            'supplier_product_code' => data_get($sourceSummary->first(), 'supplier_product_code'),
            'satilabilir_mi' => !$product->hasVariants(),
            'tenant_katalog_durumu' => 'not_projected',
            'son_sync' => optional($product->updated_at)->format('d.m.Y H:i'),
            'price_status_tags' => $priceStatusTags,
            'stok_durumu' => $this->resolveStockStatus($stockQuantity),
            'warning_tags' => $warningTags,
        ];
    }

    private function resolveCommonSupplierName(StandardProduct $product): string
    {
        return $product->supplier?->name
            ?: (string) data_get($product->source_summary, '0.supplier_name', '-');
    }

    private function resolveCategoryStatus(StandardProduct $product): string
    {
        if (blank($product->standard_category_id)) {
            return 'category_missing';
        }

        $category = $product->relationLoaded('category') ? $product->getRelation('category') : null;
        $categoryCode = $category instanceof StandardCategory
            ? (string) $category->code
            : (string) data_get($product->meta, 'standard_category_code', '');

        if ($categoryCode === '') {
            $rawCategory = $product->getAttributes()['category'] ?? null;
            $categoryCode = is_string($rawCategory) ? trim($rawCategory) : (string) data_get($rawCategory, 'code', '');
        }

        if ($categoryCode === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN' || ($category instanceof StandardCategory && $category->isArchivedCategory())) {
            return 'category_missing';
        }

        if (filled(data_get($product->meta, 'category_override_standard_category_id'))) {
            return 'override';
        }

        if ((bool) data_get($product->meta, 'category_conflict', false)) {
            return 'conflict';
        }

        return 'mapped';
    }

    private function resolvePriceStatusTags(StandardProduct|StandardProductVariant $record, mixed $catalogRecord = null): array
    {
        $snapshot = data_get($catalogRecord?->meta, 'price_snapshot', data_get($record->meta, 'price_snapshot', []));
        $price = data_get($snapshot, 'list_price', $catalogRecord?->display_price ?? $record->min_purchase_price ?? null);
        $tags = [];

        if ($price === null || (float) $price <= 0) {
            $tags[] = 'price_missing';
        } else {
            $tags[] = 'price_available';
        }

        if ((bool) data_get($snapshot, 'net_price_warning', false)) {
            $tags[] = 'net_price';
        }

        if ((bool) data_get($snapshot, 'supplier_warning_flag', false) || (bool) data_get($snapshot, 'price_policy_warning', false)) {
            $tags[] = 'fixed_price';
        }

        return array_values(array_unique($tags));
    }

    private function resolveWarningTagsFromProduct(StandardProduct $product): array
    {
        $tags = [];
        $snapshot = data_get($product->meta, 'price_snapshot', []);
        $warnings = collect(data_get($product->source_summary, '*.warnings', []))->flatten()->filter();

        if ((bool) data_get($snapshot, 'supplier_warning_flag', false) || (bool) $product->warning_flag) {
            $tags[] = 'red_product';
        }

        if ((bool) data_get($snapshot, 'net_price_warning', false)) {
            $tags[] = 'net_price';
            $tags[] = 'amber_product';
        }

        if ((bool) data_get($snapshot, 'price_policy_warning', false)) {
            $tags[] = 'amber_product';
        }

        if (blank($product->standard_category_id)) {
            $tags[] = 'category_missing';
        }

        if (blank($product->image_url)) {
            $tags[] = 'image_missing';
        }

        if ($product->min_purchase_price === null
            || in_array('price_missing', $this->resolvePriceStatusTags($product), true)) {
            $tags[] = 'price_missing';
        }

        if ((float) ($product->total_stock_quantity ?? 0) <= 0) {
            $tags[] = 'stock_missing';
        }

        if ($warnings->isNotEmpty()) {
            $tags[] = 'warning';
        }

        return $this->resolveWarningTags($tags);
    }

    private function resolveWarningTagsFromVariant(StandardProductVariant $variant, mixed $catalogVariant = null): array
    {
        $tags = [];
        $snapshot = data_get($catalogVariant?->meta, 'price_snapshot', data_get($variant->meta, 'price_snapshot', []));

        if ((bool) data_get($snapshot, 'supplier_warning_flag', false)) {
            $tags[] = 'red_product';
        }

        if ((bool) data_get($snapshot, 'net_price_warning', false)) {
            $tags[] = 'net_price';
            $tags[] = 'amber_product';
        }

        if ((bool) data_get($snapshot, 'price_policy_warning', false)) {
            $tags[] = 'amber_product';
        }

        if ((float) ($catalogVariant?->stock_quantity ?? $variant->stock_quantity ?? 0) <= 0) {
            $tags[] = 'stock_missing';
        }

        if (($variant->min_purchase_price === null && $variant->max_purchase_price === null)
            || in_array('price_missing', $this->resolvePriceStatusTags($variant, $catalogVariant), true)) {
            $tags[] = 'price_missing';
        }

        return $tags;
    }

    private function resolveWarningTags(array $tags): array
    {
        return array_values(array_unique(array_filter($tags)));
    }

    private function resolveStockStatus(float $stock): string
    {
        if ($stock > 0) {
            return 'in_stock';
        }

        if ($stock === 0.0) {
            return 'out_of_stock';
        }

        return 'stock_unknown';
    }

    private function resolveTenantOutputStatus(mixed $catalogRecord): string
    {
        if (!$catalogRecord) {
            return 'not_projected';
        }

        $status = (string) ($catalogRecord->catalog_status ?? '');

        if (in_array($status, ['missing_category', 'missing_price', 'blocked', 'inactive_candidate', 'missing_from_feed'], true)) {
            return 'blocked';
        }

        if (($catalogRecord->is_active ?? false) && (($catalogRecord->visible_in_catalog ?? false) || data_get($catalogRecord->meta, 'quote_search_visible', false))) {
            return 'projected';
        }

        return 'hidden';
    }

    private function resolveTenantOutputLabel(mixed $catalogRecord): string
    {
        return match ($this->resolveTenantOutputStatus($catalogRecord)) {
            'projected' => 'Tenant kataloğa çıktı',
            'blocked' => 'Projection blocked',
            'hidden' => 'Teklifte gizli',
            default => 'Tenant kataloğa çıkmadı',
        };
    }

    public function supplierProducts(Request $request): View
    {
        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->orderBy('source_name')
            ->get();

        $sourceId = $request->integer('source_id');
        if ($sourceId && !$sources->contains('id', $sourceId)) {
            $sourceId = null;
        }

        $products = SupplierProductRaw::query()
            ->with([
                'supplier',
                'source',
                'variants',
                'standardProduct.category',
                'standardProduct.tenantCatalogProducts',
            ])
            ->when($sourceId, fn ($query) => $query->where('supplier_source_id', $sourceId))
            ->latest('updated_at')
            ->limit(150)
            ->get();

        $selectedProduct = $products->firstWhere('id', $request->integer('selected_product_id')) ?: $products->first();
        $processSteps = $this->pipelineSteps($this->pipelineCounts());
        $standardCategoryOptions = StandardCategory::query()
            ->permanentBackbone()
            ->orderBy('path')
            ->get(['id', 'name', 'path']);

        $summary = [
            'total_supplier_products' => $products->count(),
            'standardized_products' => $products->filter(fn (SupplierProductRaw $product) => filled($product->standard_product_id))->count(),
            'tenant_catalog_products' => $products->filter(function (SupplierProductRaw $product) {
                return $product->standardProduct?->tenantCatalogProducts->where('is_active', true)->count() > 0;
            })->count(),
            'missing_category' => $products->filter(fn (SupplierProductRaw $product) => blank($product->standard_category_id) && blank(data_get($product->normalized_payload, 'category_override_standard_category_id')))->count(),
            'missing_price' => $products->filter(fn (SupplierProductRaw $product) => blank(data_get($product->normalized_payload, 'list_price')))->count(),
            'stock_changed' => $products->filter(function (SupplierProductRaw $product) {
                return data_get($product->normalized_payload, '_sync_meta.last_sync_status') === 'stock_changed';
            })->count(),
            'missing_from_feed' => $products->filter(function (SupplierProductRaw $product) {
                return in_array(data_get($product->normalized_payload, '_sync_meta.last_sync_status'), ['missing_from_feed', 'inactive_candidate'], true);
            })->count(),
            'warning_products' => $products->filter(fn (SupplierProductRaw $product) => (bool) ($product->warning_flag || data_get($product->normalized_payload, 'net_price_warning', false) || data_get($product->normalized_payload, 'price_policy_warning', false)))->count(),
        ];

        return view('super-admin.product-data-hub.supplier-products', compact(
            'sources',
            'sourceId',
            'products',
            'selectedProduct',
            'processSteps',
            'standardCategoryOptions',
            'summary'
        ));
    }

    public function saveSupplierProductOverride(
        Request $request,
        SupplierProductRaw $rawProduct,
        StandardProductBuilderService $builder,
        TenantCatalogProjectionService $projection
    ): RedirectResponse {
        $validated = $request->validate([
            'standard_category_id' => 'required|exists:standard_categories,id',
            'category_override_note' => 'nullable|string|max:1000',
            'category_override_apply_to_rule' => 'nullable|boolean',
        ]);

        $category = StandardCategory::query()->findOrFail($validated['standard_category_id']);
        abort_if(
            $category->isArchivedCategory() || !$category->isPermanentBackbone(),
            422,
            'Arşiv veya kalıcı omurga dışında kalan kategoriler kategori override hedefi olarak kullanılamaz.'
        );
        $payload = array_merge((array) ($rawProduct->normalized_payload ?? []), [
            'category_override_standard_category_id' => $category->id,
            'category_override_name' => $category->full_path ?: $category->name,
            'category_override_note' => $validated['category_override_note'] ?? null,
            'category_override_apply_to_rule' => (bool) ($validated['category_override_apply_to_rule'] ?? false),
            'category_override_applied_at' => now()->toDateTimeString(),
            'standard_category_id' => $category->id,
        ]);

        $rawProduct->forceFill([
            'standard_category_id' => $category->id,
            'mapping_status' => 'mapped',
            'normalized_payload' => $payload,
        ])->save();

        $build = $builder->buildFromRawProduct($rawProduct->fresh(['variants']));
        $standardProduct = $rawProduct->fresh()->standardProduct;

        if ($standardProduct) {
            $tenantIds = TenantSupplierAccess::query()
                ->where('supplier_id', $rawProduct->supplier_id)
                ->where('is_active', true)
                ->where('can_view_products', true)
                ->where('visible_in_catalog', true)
                ->pluck('tenant_account_id')
                ->unique();

            TenantAccount::query()
                ->whereIn('id', $tenantIds)
                ->get()
                ->each(function (TenantAccount $tenant) use ($projection, $standardProduct) {
                    $projection->projectForTenant($tenant, [
                        'standard_product_ids' => [$standardProduct->id],
                    ]);
                });
        }

        return redirect()
            ->route('admin.super.product-data-hub.supplier-products', [
                'source_id' => $rawProduct->supplier_source_id,
                'selected_product_id' => $rawProduct->id,
            ])
            ->with('success', 'Ürün bazlı kategori override kaydedildi. Standart ürün ve tenant katalog projeksiyonu güncellendi.');
    }

    public function profileComparison(
        PreviewParserService $previewParser,
        SourceFetchService $fetchService,
        SourceParserService $sourceParser
    ): View {
        $profiles = collect([
            'YENI-NESIL' => 'Yeni Nesil',
            'AKDENIZ' => 'Akdeniz',
            'ILPEN' => 'İlpen',
            'ETKIN' => 'Etkin',
        ]);

        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->get();

        $rows = $profiles->map(function (string $displayName, string $profileKey) use ($sources, $previewParser, $fetchService, $sourceParser) {
            $source = $sources->first(function (SupplierSource $candidate) use ($previewParser, $profileKey) {
                return $previewParser->getSupplierProfileKey($candidate) === $profileKey;
            });

            if (!$source) {
                return [
                    'profile_key' => $profileKey,
                    'supplier_name' => $displayName,
                    'model_type' => config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.product_model", '-'),
                    'source_mode' => 'demo_fallback',
                    'product' => null,
                    'variant' => null,
                    'warnings' => ['Bu profil için aktif kaynak bulunamadı. Demo veya kaynak tanımı bekleniyor.'],
                    'errors' => [],
                    'readiness' => 'Eksik Alan Var',
                    'readiness_badge' => 'amber',
                ];
            }

            $parsedRows = null;
            $sourceMode = 'demo_fallback';
            $localFilePath = $source->config['source_file_path'] ?? null;
            $fetchWarnings = [];
            $fetchErrors = [];

            if (filled($localFilePath) && is_file($localFilePath)) {
                $fetchResult = $fetchService->fetch($source);

                if (($fetchResult['ok'] ?? false) === true) {
                    $parseResult = $sourceParser->parse($source, (string) ($fetchResult['content'] ?? ''));
                    if (($parseResult['ok'] ?? false) === true) {
                        $parsedRows = $parseResult['rows'] ?? [];
                        $sourceMode = 'live_source';
                    } else {
                        $fetchErrors = array_merge($fetchErrors, $parseResult['errors'] ?? []);
                    }
                } else {
                    $fetchWarnings = array_merge($fetchWarnings, $fetchResult['warnings'] ?? []);
                    $fetchErrors = array_merge($fetchErrors, $fetchResult['errors'] ?? []);
                }
            }

            $preview = $previewParser->previewSource($source, $parsedRows);
            $product = collect($preview['products'] ?? [])->first();
            $variant = collect($preview['variants'] ?? [])->first();
            $warnings = array_values(array_unique(array_filter(array_merge(
                $fetchWarnings,
                $preview['mapping_warnings'] ?? [],
                $product['warnings'] ?? [],
                $variant['warnings'] ?? []
            ))));
            $errors = array_values(array_unique(array_filter(array_merge(
                $fetchErrors,
                $product['errors'] ?? [],
                $variant['errors'] ?? []
            ))));

            [$readiness, $badge] = $this->resolveProfileReadiness($product, $variant, $warnings, $errors);

            return [
                'profile_key' => $profileKey,
                'supplier_name' => $source->supplier?->name ?? $displayName,
                'model_type' => config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.product_model", '-'),
                'source_mode' => $sourceMode,
                'source' => $source,
                'product' => $product,
                'variant' => $variant,
                'warnings' => $warnings,
                'errors' => $errors,
                'readiness' => $readiness,
                'readiness_badge' => $badge,
            ];
        })->values();

        $summary = [
            'profile_count' => $rows->count(),
            'ready_count' => $rows->where('readiness', 'Hazır')->count(),
            'missing_count' => $rows->where('readiness', 'Eksik Alan Var')->count(),
            'category_pending_count' => $rows->where('readiness', 'Kategori Eşleme Bekliyor')->count(),
            'price_warning_count' => $rows->filter(fn (array $row) => (bool) data_get($row, 'product.net_price_warning', false))->count(),
        ];

        return view('super-admin.product-data-hub.profile-comparison', [
            'rows' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * Get global supplier sources
     */
    private function getGlobalSources()
    {
        $fieldMappingBySource = SupplierFieldMapping::query()
            ->selectRaw('supplier_source_id, COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN target_field IS NOT NULL AND target_field <> '' THEN 1 ELSE 0 END) as mapped_count")
            ->groupBy('supplier_source_id')
            ->get()
            ->keyBy('supplier_source_id');

        $categoryMappingBySource = SupplierCategoryMapping::query()
            ->selectRaw('supplier_source_id, COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN standard_category_id IS NOT NULL THEN 1 ELSE 0 END) as mapped_count")
            ->selectRaw("SUM(CASE WHEN mapping_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN mapping_status IN ('needs_review', 'conflict') THEN 1 ELSE 0 END) as review_count")
            ->groupBy('supplier_source_id')
            ->get()
            ->keyBy('supplier_source_id');

        return SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->orderBy('source_name')
            ->get()
            ->map(function (SupplierSource $source) use ($fieldMappingBySource, $categoryMappingBySource) {
                $fieldStats = $fieldMappingBySource->get($source->id);
                $categoryStats = $categoryMappingBySource->get($source->id);

                return [
                    'id' => $source->id,
                    'supplier_id' => $source->supplier_id,
                    'supplier_name' => $source->supplier?->name ?? 'Tedarikçi',
                    'supplier_code' => $source->supplier?->code ?? '-',
                    'source_type' => $source->source_type,
                    'source_name' => $source->source_name,
                    'field_mapping' => $fieldStats
                        ? sprintf('%d alan / %d eşlenen', (int) $fieldStats->total_count, (int) $fieldStats->mapped_count)
                        : 'Henüz tanımlı değil',
                    'category_mapping' => $categoryStats
                        ? sprintf(
                            '%d kategori / %d bekleyen',
                            (int) $categoryStats->total_count,
                            (int) $categoryStats->pending_count + (int) $categoryStats->review_count
                        )
                        : 'Henüz taranmadı',
                    'last_sync' => $source->last_sync_at?->format('d.m.Y H:i') ?? '-',
                    'status' => $source->status,
                    'tenant_count' => TenantSupplierAccess::query()
                        ->where('supplier_id', $source->supplier_id)
                        ->where('is_active', true)
                        ->count(),
                    'global_status' => $source->status === 'active' ? 'Aktif' : ($source->status === 'error' ? 'Hata' : 'Pasif'),
                ];
            });
    }

    /**
     * Get tenants
     */
    private function getTenants()
    {
        return TenantAccount::query()
            ->with([
                'modules' => fn ($query) => $query->whereIn('module_key', [
                    'product_data_hub',
                    'advanced_catalog',
                    'supplier_feed',
                    'export_web_feed',
                ]),
                'supplierAccesses' => fn ($query) => $query->orderBy('supplier_id'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Build access matrix
     */
    private function buildAccessMatrix($tenants, $globalSources)
    {
        $matrix = [];

        foreach ($tenants as $tenant) {
            $row = [
                'tenant' => $tenant,
                'active' => $tenant->status === 'active',
                'sources' => [],
                'export' => $this->moduleEnabled($tenant, 'export_web_feed'),
                'feed_limit' => $this->moduleLimit($tenant, 'supplier_feed'),
                'product_data_hub' => $this->moduleEnabled($tenant, 'product_data_hub'),
                'advanced_catalog' => $this->moduleEnabled($tenant, 'advanced_catalog'),
                'feed_limit_warning' => false,
            ];

            $activeSources = 0;
            foreach ($globalSources as $source) {
                $access = $tenant->supplierAccesses
                    ->firstWhere('supplier_id', $source['supplier_id']);

                $enabled = (bool) ($access?->isCurrentlyAccessible() ?? false);
                $row['sources'][$source['id']] = $access;
                if ($enabled) {
                    $activeSources++;
                }
            }

            $row['feed_limit_warning'] = $row['feed_limit'] !== null
                && $row['feed_limit'] >= 0
                && $activeSources > $row['feed_limit'];

            $matrix[] = $row;
        }

        return collect($matrix);
    }

    /**
     * Calculate total access
     */
    private function calculateTotalAccess($accessMatrix)
    {
        $total = 0;

        foreach ($accessMatrix as $row) {
            foreach ($row['sources'] as $access) {
                if ($access?->isCurrentlyAccessible()) {
                    $total++;
                }
            }
        }

        return $total;
    }

    private function pipelineCounts(): array
    {
        $visibleSourceIds = SupplierSource::query()->visibleInProductDataHub()->pluck('id');
        $supplierSources = $visibleSourceIds->count();
        $activeSupplierSources = $visibleSourceIds->count();
        $previewAttempts = FeedSyncLog::query()
            ->where('sync_type', 'manual')
            ->whereIn('status', ['completed', 'failed'])
            ->count();
        $fieldMappings = SupplierFieldMapping::query()->count();
        $categoryMappings = SupplierCategoryMapping::query()->count();
        $categoryMappingPending = SupplierCategoryMapping::query()
            ->where(function ($query) {
                $query->whereNull('standard_category_id')
                    ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict']);
            })
            ->count();
        $pendingCategoryId = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->value('id');
        $rawProducts = SupplierProductRaw::query()->count();
        $rawVariants = SupplierProductVariantRaw::query()->count();
        $standardProducts = StandardProduct::query()->count();
        $standardVariants = StandardProductVariant::query()->count();
        $tenantCatalogProducts = TenantCatalogProduct::query()->count();
        $tenantAccess = TenantSupplierAccess::query()->count();
        $activeTenantAccess = TenantSupplierAccess::query()->active()->count();
        $fieldMappingSources = SupplierFieldMapping::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->distinct('supplier_source_id')
            ->count('supplier_source_id');

        return [
            'supplier_sources' => $supplierSources,
            'active_supplier_sources' => $activeSupplierSources,
            'preview_attempts' => $previewAttempts,
            'supplier_field_mappings' => $fieldMappings,
            'supplier_category_mappings' => $categoryMappings,
            'supplier_products_raw' => $rawProducts,
            'supplier_product_variants_raw' => $rawVariants,
            'standard_products' => $standardProducts,
            'standard_product_variants' => $standardVariants,
            'tenant_catalog_products' => $tenantCatalogProducts,
            'tenant_supplier_access' => $tenantAccess,
            'active_tenant_supplier_access' => $activeTenantAccess,
            'category_mapping_pending' => $categoryMappingPending,
            'field_mapping_missing' => max(0, $supplierSources - $fieldMappingSources),
            'permanent_categories' => StandardCategory::query()->permanentBackbone()->count(),
            'archived_categories' => StandardCategory::query()->archived()->count(),
            'pending_standard_product_categories' => $pendingCategoryId
                ? StandardProduct::query()->where('standard_category_id', $pendingCategoryId)->count()
                : 0,
            'pending_tenant_catalog_categories' => $pendingCategoryId
                ? TenantCatalogProduct::query()->where('standard_category_id', $pendingCategoryId)->count()
                : 0,
        ];
    }

    private function latestCategoryBackupPath(): ?string
    {
        $paths = glob(storage_path('app/product-data-hub/category-backups/*'), GLOB_ONLYDIR) ?: [];
        rsort($paths);

        return isset($paths[0])
            ? 'storage/app/product-data-hub/category-backups/' . basename($paths[0])
            : null;
    }

    private function pipelineSteps(array $counts): array
    {
        return [
            [
                'title' => 'Global Kaynak',
                'count' => $counts['supplier_sources'],
                'status' => $counts['supplier_sources'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['supplier_sources'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Kaynakları Aç',
                'action_route' => route('admin.super.product-data-hub.sources.index'),
            ],
            [
                'title' => 'Kaynak Test / Preview',
                'count' => $counts['preview_attempts'],
                'status' => $counts['preview_attempts'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['preview_attempts'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Preview Akışı',
                'action_route' => route('admin.super.product-data-hub.sources.index'),
            ],
            [
                'title' => 'Alan Eşleme',
                'count' => $counts['supplier_field_mappings'],
                'status' => $counts['field_mapping_missing'] === 0 && $counts['supplier_field_mappings'] > 0 ? 'green' : 'amber',
                'status_label' => $counts['field_mapping_missing'] === 0 && $counts['supplier_field_mappings'] > 0 ? 'Tamam' : 'Eksik',
                'action_label' => 'Alan Eşleme',
                'action_route' => route('admin.super.product-data-hub.field-mappings.index'),
            ],
            [
                'title' => 'Kategori Eşleme',
                'count' => $counts['supplier_category_mappings'],
                'status' => $counts['category_mapping_pending'] === 0 && $counts['supplier_category_mappings'] > 0 ? 'green' : 'amber',
                'status_label' => $counts['category_mapping_pending'] === 0 && $counts['supplier_category_mappings'] > 0 ? 'Tamam' : 'Bekliyor',
                'action_label' => 'Kategori Eşleme',
                'action_route' => route('admin.super.product-data-hub.category-mappings.index'),
            ],
            [
                'title' => 'Ham Ürün Staging',
                'count' => $counts['supplier_products_raw'],
                'status' => $counts['supplier_products_raw'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['supplier_products_raw'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Ham Ürünler',
                'action_route' => route('admin.super.product-data-hub.raw-products.index'),
            ],
            [
                'title' => 'Ortak Ürün Havuzu',
                'count' => $counts['standard_products'],
                'status' => $counts['standard_products'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['standard_products'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Ortak Ürün Havuzu',
                'action_route' => route('admin.super.product-data-hub.common-products'),
            ],
            [
                'title' => 'Tenant Erişimi',
                'count' => $counts['active_tenant_supplier_access'],
                'status' => $counts['active_tenant_supplier_access'] > 0 ? 'green' : 'amber',
                'status_label' => $counts['active_tenant_supplier_access'] > 0 ? 'Hazır' : 'Kontrol Et',
                'action_label' => 'Tenant Erişimleri',
                'action_route' => route('admin.super.tenant-supplier-access.index'),
            ],
            [
                'title' => 'Tenant Çıkışı',
                'count' => $counts['tenant_catalog_products'],
                'status' => $counts['tenant_catalog_products'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['tenant_catalog_products'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Tenant Çıkışları',
                'action_route' => route('admin.super.product-data-hub.catalog-output'),
            ],
            [
                'title' => 'Gelişmiş Katalog',
                'count' => $counts['tenant_catalog_products'],
                'status' => $counts['tenant_catalog_products'] > 0 ? 'green' : 'gray',
                'status_label' => $counts['tenant_catalog_products'] > 0 ? 'Hazır' : 'Bekliyor',
                'action_label' => 'Gelişmiş Ürün ve Katalog',
                'action_route' => route('admin.super.product-data-hub.catalog-output'),
            ],
        ];
    }

    private function catalogOutputStats(): array
    {
        $standardProducts = StandardProduct::query();
        $tenantCatalogProducts = TenantCatalogProduct::query();
        $standardProductsCount = (clone $standardProducts)->count();
        $latestRuns = ProductDataHubSyncRun::query()
            ->whereIn('status', ['success', 'partial'])
            ->latest('id')
            ->limit(20)
            ->get();

        $projectionBlockedMissingCategory = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) data_get($run->report_payload, 'projection.blocked_missing_category', 0));
        $projectionBlockedMissingPrice = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) data_get($run->report_payload, 'projection.blocked_missing_price', 0));
        $projectionWarningOutputs = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) data_get($run->report_payload, 'projection.projected_with_warnings', 0));
        $projectionUpdatedProducts = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) data_get($run->report_payload, 'projection.updated_products', 0));
        $stockChangedProducts = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) $run->stock_changed_count);
        $priceChangedProducts = $latestRuns->sum(fn (ProductDataHubSyncRun $run) => (int) $run->price_changed_count);
        $fallbackCategoryId = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->value('id');

        return [
            'total_standard_products' => $standardProductsCount,
            'total_variants' => StandardProductVariant::query()->count(),
            'tenant_open_products' => (clone $tenantCatalogProducts)->where('is_active', true)->where('visible_in_catalog', true)->count(),
            'tenant_closed_products' => (clone $tenantCatalogProducts)->where(function ($query) {
                $query->where('is_active', false)->orWhere('visible_in_catalog', false);
            })->count(),
            'local_stock_priority_products' => (clone $tenantCatalogProducts)->whereColumn('local_stock_quantity', '>', 'supplier_stock_quantity')->count(),
            'supplier_products' => SupplierProductRaw::query()->count(),
            'category_mapped_products' => (clone $standardProducts)
                ->whereNotNull('standard_category_id')
                ->when($fallbackCategoryId, fn ($query) => $query->where('standard_category_id', '!=', $fallbackCategoryId))
                ->count(),
            'category_missing_products' => (clone $standardProducts)
                ->where(function ($query) use ($fallbackCategoryId) {
                    $query->whereNull('standard_category_id')
                        ->when($fallbackCategoryId, fn ($builder) => $builder->orWhere('standard_category_id', $fallbackCategoryId))
                        ->orWhere('meta->category_missing_warning', true);
                })
                ->count(),
            'fallback_category_products' => (clone $tenantCatalogProducts)
                ->whereHas('category', fn ($query) => $query->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'))
                ->count(),
            'category_pending_visible_products' => (clone $tenantCatalogProducts)
                ->where('is_active', true)
                ->where('visible_in_catalog', true)
                ->where(function ($query) {
                    $query->where('meta->category_missing_warning', true)
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'));
                })
                ->count(),
            'category_blocked_products' => (clone $tenantCatalogProducts)->where('catalog_status', 'missing_category')->count(),
            'missing_price_products' => (clone $standardProducts)->whereNull('min_purchase_price')->whereNull('purchase_price')->count(),
            'missing_image_products' => (clone $standardProducts)->whereNull('image_url')->count(),
            'stock_changed_products' => $stockChangedProducts,
            'price_changed_products' => $priceChangedProducts,
            'projection_updated_products' => $projectionUpdatedProducts,
            'projection_blocked_missing_category' => $projectionBlockedMissingCategory,
            'projection_blocked_missing_price' => $projectionBlockedMissingPrice,
            'projection_warning_outputs' => $projectionWarningOutputs,
            'warning_products' => (clone $standardProducts)->where(function ($query) {
                $query->where('warning_flag', true)
                    ->orWhere('source_summary', 'like', '%warning%');
            })->count(),
        ];
    }

    private function supplierPipelineRows()
    {
        $visibleSources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->get(['id', 'supplier_id']);
        $visibleSourceIds = $visibleSources->pluck('id');
        $visibleSupplierIds = $visibleSources->pluck('supplier_id')->unique()->values();

        $sourcesBySupplier = SupplierSource::query()
            ->visibleInProductDataHub()
            ->selectRaw('supplier_id, COUNT(*) as source_count')
            ->groupBy('supplier_id')
            ->pluck('source_count', 'supplier_id');

        $previewBySupplier = FeedSyncLog::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->selectRaw('supplier_id, MAX(total_records) as preview_records, MAX(completed_at) as last_test_at')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $fieldMappingBySupplier = SupplierFieldMapping::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->selectRaw('supplier_id, COUNT(*) as mapping_count')
            ->groupBy('supplier_id')
            ->pluck('mapping_count', 'supplier_id');

        $categoryMappingBySupplier = SupplierCategoryMapping::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->selectRaw('supplier_id, COUNT(*) as mapping_count')
            ->groupBy('supplier_id')
            ->pluck('mapping_count', 'supplier_id');

        $rawBySupplier = SupplierProductRaw::query()
            ->when(
                $visibleSourceIds->isNotEmpty(),
                fn ($query) => $query->whereIn('supplier_source_id', $visibleSourceIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->selectRaw('supplier_id, COUNT(*) as raw_count')
            ->groupBy('supplier_id')
            ->pluck('raw_count', 'supplier_id');

        $standardBySupplier = StandardProduct::query()
            ->selectRaw('supplier_id, COUNT(*) as standard_count')
            ->groupBy('supplier_id')
            ->pluck('standard_count', 'supplier_id');

        $catalogBySupplier = TenantCatalogProduct::query()
            ->join('standard_products', 'tenant_catalog_products.standard_product_id', '=', 'standard_products.id')
            ->selectRaw('standard_products.supplier_id as supplier_id, COUNT(tenant_catalog_products.id) as catalog_count')
            ->groupBy('standard_products.supplier_id')
            ->pluck('catalog_count', 'supplier_id');

        return Supplier::query()
            ->whereIn('id', $visibleSupplierIds)
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier) use (
                $sourcesBySupplier,
                $previewBySupplier,
                $fieldMappingBySupplier,
                $categoryMappingBySupplier,
                $rawBySupplier,
                $standardBySupplier,
                $catalogBySupplier
            ) {
                $previewRow = $previewBySupplier->get($supplier->id);

                return [
                    'supplier' => $supplier,
                    'source_count' => (int) ($sourcesBySupplier[$supplier->id] ?? 0),
                    'last_test' => $previewRow?->last_test_at ? date('d.m.Y H:i', strtotime((string) $previewRow->last_test_at)) : '-',
                    'preview_records' => (int) ($previewRow?->preview_records ?? 0),
                    'field_mapping_status' => ((int) ($fieldMappingBySupplier[$supplier->id] ?? 0)) > 0 ? 'Hazır' : 'Eksik',
                    'category_mapping_status' => ((int) ($categoryMappingBySupplier[$supplier->id] ?? 0)) > 0 ? 'Hazır' : 'Eksik',
                    'raw_products' => (int) ($rawBySupplier[$supplier->id] ?? 0),
                    'standard_products' => (int) ($standardBySupplier[$supplier->id] ?? 0),
                    'tenant_catalog_products' => (int) ($catalogBySupplier[$supplier->id] ?? 0),
                    'last_error' => $supplier->sources()
                        ->visibleInProductDataHub()
                        ->whereNotNull('last_error')
                        ->latest('updated_at')
                        ->value('last_error') ?: '-',
                ];
            });
    }

    private function moduleEnabled(TenantAccount $tenant, string $moduleKey): bool
    {
        return (bool) optional($tenant->modules->firstWhere('module_key', $moduleKey))->is_enabled;
    }

    private function moduleLimit(TenantAccount $tenant, string $moduleKey): ?int
    {
        return optional($tenant->modules->firstWhere('module_key', $moduleKey))->limit_value;
    }

    private function resolveProfileReadiness(?array $product, ?array $variant, array $warnings, array $errors): array
    {
        if (!empty($errors)) {
            return ['Hata Var', 'red'];
        }

        if (!$product) {
            return ['Eksik Alan Var', 'amber'];
        }

        if (blank($product['generated_product_code'] ?? null)) {
            return ['Eksik Alan Var', 'amber'];
        }

        if (blank($product['product_name'] ?? $product['display_product_name'] ?? null)) {
            return ['Eksik Alan Var', 'amber'];
        }

        if (blank($product['supplier_category_name'] ?? null)) {
            return ['Kategori Eşleme Bekliyor', 'amber'];
        }

        if ((float) ($product['purchase_price'] ?? 0) <= 0) {
            return ['Fiyat Kontrol Gerekli', 'amber'];
        }

        if (blank($product['image_url'] ?? null) && blank($variant['variant_image_url'] ?? null)) {
            return ['Görsel Eksik', 'amber'];
        }

        if ($variant && blank($variant['generated_variant_code'] ?? null)) {
            return ['Eksik Alan Var', 'amber'];
        }

        return ['Hazır', 'green'];
    }
}
