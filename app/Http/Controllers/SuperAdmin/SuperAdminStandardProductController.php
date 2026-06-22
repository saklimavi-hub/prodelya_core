<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\TenantCatalogProduct;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SuperAdminStandardProductController extends Controller
{
    public function index(Request $request): View
    {
        $limit = $this->normalizeLimit($request->string('limit')->toString() ?: '50');
        $page = max(1, $request->integer('page', 1));

        $baseQuery = StandardProduct::query();
        $this->applyQueryFilters($baseQuery, $request);

        $perPage = $limit === 'all' ? 2000 : (int) $limit;
        $productPaginator = (clone $baseQuery)
            ->with([
                'category',
                'supplier',
                'images',
                'primaryImage',
                'variants.images',
                'variants.tenantCatalogVariants',
                'tenantCatalogProducts.variants',
            ])
            ->withCount(['variants'])
            ->latest('updated_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $rows = $productPaginator->getCollection()
            ->flatMap(fn (StandardProduct $product) => $this->buildRows($product))
            ->values();

        $filteredRows = $this->applyRowFilters($rows, $request);
        $supplierOptions = $this->supplierOptions();

        $totalProducts = StandardProduct::query()->count();
        $projectedProducts = TenantCatalogProduct::query()
            ->whereNotNull('standard_product_id')
            ->distinct('standard_product_id')
            ->count('standard_product_id');
        $fallbackCategoryId = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->value('id');

        $stats = [
            'total' => $totalProducts,
            'active' => StandardProduct::query()->where('is_active', true)->count(),
            'variants' => StandardProductVariant::query()->count(),
            'catalog_visible' => StandardProduct::query()->where('visible_in_catalog', true)->count(),
            'missing_category' => StandardProduct::query()
                ->where(function ($query) use ($fallbackCategoryId) {
                    $query->whereNull('standard_category_id')
                        ->when($fallbackCategoryId, fn ($builder) => $builder->orWhere('standard_category_id', $fallbackCategoryId))
                        ->orWhere('meta->category_missing_warning', true);
                })
                ->count(),
            'missing_price' => StandardProduct::query()
                ->whereNull('min_purchase_price')
                ->count(),
            'missing_image' => StandardProduct::query()
                ->whereNull('image_url')
                ->whereDoesntHave('images')
                ->count(),
            'warning' => StandardProduct::query()->where('warning_flag', true)->count(),
            'parent' => StandardProduct::query()->has('variants')->count(),
            'variant' => StandardProductVariant::query()->count(),
            'flat' => StandardProduct::query()->doesntHave('variants')->count(),
            'sellable' => StandardProduct::query()->doesntHave('variants')->count() + StandardProductVariant::query()->count(),
            'tenant_pending' => max(0, $totalProducts - $projectedProducts),
        ];

        $products = new LengthAwarePaginator(
            $filteredRows,
            $productPaginator->total(),
            $productPaginator->perPage(),
            $productPaginator->currentPage(),
            [
                'path' => url()->current(),
                'query' => $request->query(),
            ]
        );

        return view('super-admin.product-data-hub.standard-products', [
            'products' => $products,
            'stats' => $stats,
            'supplierOptions' => $supplierOptions,
            'filters' => array_merge([
                'q' => '',
                'supplier' => '',
                'product_type' => '',
                'sellable' => '',
                'category_status' => '',
                'price_status' => '',
                'stock_status' => '',
                'warning_status' => '',
                'tenant_projection_status' => '',
                'limit' => '50',
            ], $request->only([
                'q',
                'supplier',
                'product_type',
                'sellable',
                'category_status',
                'price_status',
                'stock_status',
                'warning_status',
                'tenant_projection_status',
                'limit',
            ])),
            'showAllWarning' => $limit === 'all',
        ]);
    }

    private function applyQueryFilters($query, Request $request): void
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
                    'not_sellable', 'catalog_group' => $query->has('variants'),
                    default => null,
                };
            })
            ->when($request->filled('category_status'), function ($query) use ($request) {
                $fallbackCategoryId = StandardCategory::query()
                    ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
                    ->value('id');

                match ($request->string('category_status')->toString()) {
                    'mapped' => $query
                        ->whereNotNull('standard_category_id')
                        ->when($fallbackCategoryId, fn ($builder) => $builder->where('standard_category_id', '!=', $fallbackCategoryId)),
                    'category_missing' => $query->where(function ($builder) use ($fallbackCategoryId) {
                        $builder->whereNull('standard_category_id')
                            ->when($fallbackCategoryId, fn ($innerBuilder) => $innerBuilder->orWhere('standard_category_id', $fallbackCategoryId))
                            ->orWhere('meta->category_missing_warning', true);
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
                    'clean' => $query->where(function ($query) {
                        $query->where('warning_flag', false)->orWhereNull('warning_flag');
                    }),
                    'red_product' => $query->where('warning_flag', true),
                    'net_price' => $query->where('meta->price_snapshot->net_price_warning', true),
                    default => null,
                };
            })
            ->when($request->filled('tenant_projection_status'), function ($query) use ($request) {
                match ($request->string('tenant_projection_status')->toString()) {
                    'projected' => $query->whereHas('tenantCatalogProducts'),
                    'not_projected', 'pending' => $query->doesntHave('tenantCatalogProducts'),
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

    private function applyRowFilters(Collection $rows, Request $request): Collection
    {
        return $rows
            ->when($request->filled('product_type'), fn (Collection $collection) => $collection->where('product_type', $request->string('product_type')->toString()))
            ->when($request->filled('sellable'), function (Collection $collection) use ($request) {
                return match ($request->string('sellable')->toString()) {
                    'sellable' => $collection->where('is_sellable', true),
                    'not_sellable' => $collection->where('is_sellable', false),
                    'catalog_group' => $collection->where('product_type', 'parent'),
                    default => $collection,
                };
            })
            ->when($request->filled('category_status'), fn (Collection $collection) => $collection->where('category_status', $request->string('category_status')->toString()))
            ->when($request->filled('price_status'), fn (Collection $collection) => $collection->filter(fn (array $row) => in_array($request->string('price_status')->toString(), $row['price_status_tags'], true)))
            ->when($request->filled('stock_status'), fn (Collection $collection) => $collection->where('stock_status', $request->string('stock_status')->toString()))
            ->when($request->filled('warning_status'), function (Collection $collection) use ($request) {
                $status = $request->string('warning_status')->toString();

                return $status === 'clean'
                    ? $collection->filter(fn (array $row) => empty($row['warning_tags']))
                    : $collection->filter(fn (array $row) => in_array($status, $row['warning_tags'], true));
            })
            ->when($request->filled('tenant_projection_status'), fn (Collection $collection) => $collection->where('tenant_projection_status', $request->string('tenant_projection_status')->toString()))
            ->when($request->filled('q'), function (Collection $collection) use ($request) {
                $needle = mb_strtolower(trim($request->string('q')->toString()));

                return $collection->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', array_filter([
                    $row['product_code'] ?? null,
                    $row['product_name'] ?? null,
                    $row['supplier_product_code'] ?? null,
                    $row['group_code'] ?? null,
                    $row['variant_code'] ?? null,
                    $row['color'] ?? null,
                    $row['measure'] ?? null,
                    $row['supplier_name'] ?? null,
                ]))), $needle));
            })
            ->values();
    }

    private function normalizeLimit(string $limit): string
    {
        return in_array($limit, ['50', '100', '250', '500', 'all'], true) ? $limit : '50';
    }

    private function supplierOptions(): Collection
    {
        return StandardProduct::query()
            ->with('supplier')
            ->limit(2000)
            ->get()
            ->map(fn (StandardProduct $product) => $this->supplierName($product))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function buildRows(StandardProduct $product): Collection
    {
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $tenantCatalogProducts = $product->relationLoaded('tenantCatalogProducts') ? $product->tenantCatalogProducts : $product->tenantCatalogProducts()->get();
        $base = $this->baseRow($product, $tenantCatalogProducts);

        if ($variants->isEmpty()) {
            $catalogProduct = $tenantCatalogProducts->first();

            return collect([array_merge($base, [
                'row_key' => 'product-' . $product->id,
                'product_type' => 'flat',
                'type_label' => 'Flat',
                'is_sellable' => true,
                'sellable_label' => (bool) ($catalogProduct?->visible_in_quote ?? true) ? 'Teklifte satılabilir' : 'Teklifte gizli',
                'product_code' => $product->standard_product_code ?: $product->sku ?: '-',
                'variant_code' => null,
                'color' => null,
                'measure' => null,
                'stock' => (float) ($catalogProduct?->total_stock_quantity ?? $product->total_stock_quantity ?? 0),
                'list_price' => $catalogProduct?->display_price ?? data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price ?? $product->purchase_price),
                'vat_rate' => data_get($catalogProduct?->meta, 'price_snapshot.vat_rate', $product->vat_rate),
                'tenant_projection_status' => $this->tenantProjectionStatus($catalogProduct),
                'tenant_projection_label' => $this->tenantProjectionLabel($catalogProduct),
            ])]);
        }

        $rows = collect([array_merge($base, [
            'row_key' => 'product-' . $product->id,
            'product_type' => 'parent',
            'type_label' => 'Parent',
            'is_sellable' => false,
            'sellable_label' => 'Grup ürün',
            'variant_code' => null,
            'color' => null,
            'measure' => null,
            'stock' => (float) ($product->total_stock_quantity ?? 0),
            'list_price' => data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price ?? $product->purchase_price),
            'vat_rate' => $product->vat_rate,
            'tenant_projection_status' => $this->tenantProjectionStatus($tenantCatalogProducts->first()),
            'tenant_projection_label' => 'Grup ürün',
        ])]);

        foreach ($variants as $variant) {
            $catalogVariant = $variant->relationLoaded('tenantCatalogVariants')
                ? $variant->tenantCatalogVariants->first()
                : $variant->tenantCatalogVariants()->first();

            $rows->push(array_merge($base, [
                'row_key' => 'variant-' . $variant->id,
                'product_type' => 'variant',
                'type_label' => 'Varyant',
                'is_sellable' => true,
                'sellable_label' => 'Teklifte satılabilir',
                'product_code' => $variant->generated_variant_code ?: $variant->variant_code ?: $product->standard_product_code ?: '-',
                'product_name' => $variant->display_name,
                'image_url' => $catalogVariant?->image_url ?: $variant->image_url ?: $product->image_url,
                'variant_code' => $variant->generated_variant_code ?: $variant->variant_code,
                'supplier_product_code' => data_get($variant->source_summary, 'variant_stock_code', data_get($variant->source_summary, 'supplier_product_code')),
                'color' => $variant->variant_color,
                'measure' => $variant->variant_size ?: data_get($variant->variant_attributes, 'measure') ?: data_get($variant->variant_attributes, 'capacity'),
                'stock' => (float) ($catalogVariant?->stock_quantity ?? $variant->stock_quantity ?? 0),
                'list_price' => $catalogVariant?->display_price ?? $variant->min_purchase_price ?? $product->min_purchase_price,
                'vat_rate' => data_get($catalogVariant?->meta, 'price_snapshot.vat_rate', data_get($variant->meta, 'price_snapshot.vat_rate', $product->vat_rate)),
                'tenant_projection_status' => $this->tenantProjectionStatus($catalogVariant),
                'tenant_projection_label' => $this->tenantProjectionLabel($catalogVariant),
                'price_status_tags' => $this->priceStatusTags($variant, $catalogVariant),
                'stock_status' => $this->stockStatus((float) ($catalogVariant?->stock_quantity ?? $variant->stock_quantity ?? 0)),
                'warning_tags' => $this->warningTags($variant, $catalogVariant, $product),
            ]));
        }

        return $rows;
    }

    private function baseRow(StandardProduct $product, Collection $tenantCatalogProducts): array
    {
        $supplierName = $this->supplierName($product);
        $catalogProduct = $tenantCatalogProducts->first();
        $warnings = $this->warningTags($product, $catalogProduct);

        return [
            'row_key' => 'product-' . $product->id,
            'product_id' => $product->id,
            'image_url' => $product->primaryImage?->image_url ?: $product->image_url,
            'product_code' => $product->standard_product_code ?: $product->sku ?: '-',
            'product_name' => $product->display_name,
            'supplier_name' => $supplierName,
            'supplier_product_code' => data_get($product->source_summary, '0.supplier_product_code'),
            'group_code' => data_get($product->source_summary, '0.supplier_group_code', $product->standard_product_code ?: $product->sku),
            'category' => $product->category_display_name,
            'category_status' => $this->categoryStatus($product),
            'price_status_tags' => $this->priceStatusTags($product, $catalogProduct),
            'stock_status' => $this->stockStatus((float) ($product->total_stock_quantity ?? 0)),
            'warning_tags' => $warnings,
            'last_sync' => optional($product->updated_at)->format('d.m.Y H:i'),
            'raw_product_id' => data_get($product->source_summary, '0.raw_product_id'),
        ];
    }

    private function supplierName(StandardProduct $product): string
    {
        return $product->supplier?->name
            ?: (string) data_get($product->source_summary, '0.supplier_name', '-');
    }

    private function categoryStatus(StandardProduct $product): string
    {
        if (filled(data_get($product->meta, 'category_override_name')) || filled(data_get($product->meta, 'category_override_standard_category_id'))) {
            return 'override';
        }

        if ((string) data_get($product->meta, 'projection_status') === 'category_conflict') {
            return 'conflict';
        }

        return filled($product->standard_category_id) ? 'mapped' : 'category_missing';
    }

    private function priceStatusTags(StandardProduct|StandardProductVariant $product, mixed $catalog = null): array
    {
        $price = $catalog?->display_price
            ?? data_get($catalog?->meta, 'price_snapshot.list_price')
            ?? data_get($product->meta, 'price_snapshot.list_price')
            ?? $product->min_purchase_price
            ?? null;
        $tags = [$price === null ? 'price_missing' : 'price_available'];

        if ((bool) data_get($product->meta, 'net_price_warning') || (string) data_get($product->meta, 'pricing_policy_type') === 'net_price') {
            $tags[] = 'net_price';
        }

        if ((bool) data_get($product->meta, 'price_policy_warning') || (bool) data_get($product->meta, 'supplier_warning_flag')) {
            $tags[] = 'fixed_price';
        }

        return array_values(array_unique($tags));
    }

    private function stockStatus(float $stock): string
    {
        return match (true) {
            $stock > 0 => 'in_stock',
            $stock === 0.0 => 'out_of_stock',
            default => 'stock_unknown',
        };
    }

    private function warningTags(StandardProduct|StandardProductVariant $product, mixed $catalog = null, ?StandardProduct $parent = null): array
    {
        $tags = [];
        $meta = (array) ($product->meta ?? []);

        if ((bool) ($product->warning_flag ?? false) || (bool) data_get($meta, 'supplier_warning_flag')) {
            $tags[] = 'red_product';
        }

        if ((bool) data_get($meta, 'price_policy_warning')) {
            $tags[] = 'amber_product';
        }

        if ((bool) data_get($meta, 'net_price_warning') || (string) data_get($meta, 'pricing_policy_type') === 'net_price') {
            $tags[] = 'net_price';
        }

        if ($parent && blank($parent->standard_category_id)) {
            $tags[] = 'category_missing';
        }

        if ($catalog && (string) ($catalog->catalog_status ?? '') === 'blocked') {
            $tags[] = 'projection_blocked';
        }

        return array_values(array_unique(array_filter($tags)));
    }

    private function tenantProjectionStatus(mixed $catalog): string
    {
        if (!$catalog) {
            return 'not_projected';
        }

        if (in_array((string) ($catalog->catalog_status ?? ''), ['blocked', 'category_conflict'], true)) {
            return 'blocked';
        }

        return (bool) ($catalog->is_active ?? true) ? 'projected' : 'pending';
    }

    private function tenantProjectionLabel(mixed $catalog): string
    {
        return match ($this->tenantProjectionStatus($catalog)) {
            'projected' => 'Yansıdı',
            'blocked' => 'Bloklandı',
            'pending' => 'Projection bekliyor',
            default => 'Bekliyor',
        };
    }
}
