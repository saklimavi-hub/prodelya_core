<?php

namespace App\Services\TenantCatalog;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Support\ProductDisplayNameFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantCatalogListRowQueryService
{
    public function paginate(TenantAccount $tenant, array $filters, Request $request, string $pageName = 'products'): LengthAwarePaginator
    {
        $limit = $filters['limit'] ?? 50;
        $page = max(1, (int) $request->query($pageName === 'products' ? 'page' : $pageName . '_page', 1));
        $perPage = $limit === 'all' ? 500 : (int) $limit;
        $query = $this->filteredRowsQuery($tenant, $filters);
        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('updated_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return (new Paginator(
            $this->hydrateRows($rows),
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName === 'products' ? 'page' : $pageName . '_page',
            ]
        ))->appends($request->query());
    }

    public function warningRows(TenantAccount $tenant, array $filters, Request $request): LengthAwarePaginator
    {
        $warningFilters = $filters;
        $warningFilters['warning_state'] = $warningFilters['warning_state'] ?: 'warning';
        $products = $this->paginate($tenant, $warningFilters, $request, 'warningRows');
        $items = $products->getCollection()
            ->flatMap(fn (TenantCatalogProduct $product) => collect($product->warning_items)->map(fn (string $warning) => [
                'product' => $product,
                'warning_type' => $warning,
                'description' => $this->warningDescription($warning),
            ]))
            ->values();

        $products->setCollection($items);

        return $products;
    }

    public function stats(TenantAccount $tenant): array
    {
        return $this->metrics($tenant)['stats'];
    }

    public function summary(TenantAccount $tenant): array
    {
        return $this->metrics($tenant)['summary'];
    }

    public function metrics(TenantAccount $tenant): array
    {
        $effectiveStockSql = $this->effectiveStockSql();
        $warningSql = "display_price IS NULL OR image_url IS NULL OR standard_category_id IS NULL OR {$effectiveStockSql} <= 0 OR meta_json LIKE '%warning%' OR meta_json LIKE '%net_price%' OR meta_json LIKE '%supplier_warning%'";
        $row = DB::query()
            ->fromSub($this->baseRowsQuery($tenant), 'catalog_rows')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN catalog_source != 'local_product' THEN 1 ELSE 0 END) as supplier_total,
                SUM(CASE WHEN catalog_source = 'local_product' THEN 1 ELSE 0 END) as local_total,
                SUM(CASE WHEN {$effectiveStockSql} > 0 THEN 1 ELSE 0 END) as in_stock_total,
                SUM(CASE WHEN display_price IS NULL THEN 1 ELSE 0 END) as missing_price_total,
                SUM(CASE WHEN {$warningSql} THEN 1 ELSE 0 END) as warning_total,
                SUM(CASE WHEN visible_in_catalog = 1 THEN 1 ELSE 0 END) as visible_total,
                SUM(CASE WHEN visible_in_catalog = 0 THEN 1 ELSE 0 END) as hidden_total,
                SUM(CASE WHEN local_stock_quantity > 0 THEN 1 ELSE 0 END) as local_stock_priority_total
            ")
            ->first();
        $lastSync = optional(TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereNotNull('last_synced_at')
                ->latest('last_synced_at')
                ->value('last_synced_at'))?->format('d.m.Y H:i') ?: 'Henüz yok';

        $stats = [
            'total' => (int) ($row->total ?? 0),
            'supplier' => (int) ($row->supplier_total ?? 0),
            'local' => (int) ($row->local_total ?? 0),
            'in_stock' => (int) ($row->in_stock_total ?? 0),
            'missing_price' => (int) ($row->missing_price_total ?? 0),
            'warning' => (int) ($row->warning_total ?? 0),
            'visible' => (int) ($row->visible_total ?? 0),
            'hidden' => (int) ($row->hidden_total ?? 0),
        ];

        return [
            'stats' => $stats,
            'summary' => [
                'total_products' => $stats['total'],
                'local_stock_priority' => (int) ($row->local_stock_priority_total ?? 0),
                'supplier_products' => $stats['supplier'],
                'missing_price' => $stats['missing_price'],
                'warnings' => $stats['warning'],
                'visible' => $stats['visible'],
                'last_sync' => $lastSync,
            ],
        ];
    }

    public function supplierOptions(TenantAccount $tenant): Collection
    {
        $supplierIds = DB::table('tenant_supplier_access')
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->filter()
            ->values();

        return Supplier::query()
            ->whereIn('id', $supplierIds->all())
            ->orderBy('name')
            ->get();
    }

    private function filteredRowsQuery(TenantAccount $tenant, array $filters): Builder
    {
        $query = DB::query()->fromSub($this->baseRowsQuery($tenant), 'catalog_rows');

        if (($filters['search'] ?? '') !== '') {
            $term = '%' . Str::lower($filters['search']) . '%';
            $query->where(function (Builder $inner) use ($term) {
                foreach (['product_code', 'product_name', 'supplier_name', 'category_name', 'search_blob'] as $column) {
                    $inner->orWhereRaw('LOWER(COALESCE(' . $column . ", '')) LIKE ?", [$term]);
                }
            });
        }

        if (!empty($filters['category'])) {
            $query->where('standard_category_id', (int) $filters['category']);
        }

        if (($filters['category_status'] ?? '') === 'matched') {
            $query->whereNotNull('standard_category_id')
                ->where('meta_json', 'not like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%')
                ->where('meta_json', 'not like', '%Kategori bekliyor%');
        }

        if (($filters['category_status'] ?? '') === 'category_waiting') {
            $query->where(function (Builder $inner) {
                $inner->whereNull('standard_category_id')
                    ->orWhere('meta_json', 'like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%')
                    ->orWhere('meta_json', 'like', '%Kategori bekliyor%')
                    ->orWhere('meta_json', 'like', '%category_missing%');
            });
        }

        if (($filters['category_status'] ?? '') === 'target_missing') {
            $query->where(function (Builder $inner) {
                $inner->where('catalog_status', 'missing_category')
                    ->orWhere('meta_json', 'like', '%category_conflict%')
                    ->orWhere('meta_json', 'like', '%target_missing%');
            });
        }

        if (($filters['category_status'] ?? '') === 'warning') {
            $query->where(function (Builder $inner) {
                $this->applyWarningWhere($inner);
            });
        }

        if (!empty($filters['supplier'])) {
            $supplierId = (int) $filters['supplier'];
            $query->where(function (Builder $inner) use ($supplierId) {
                $inner->where('supplier_id', $supplierId);
                $this->orWhereJsonSupplierId($inner, 'source_summary_json', $supplierId);
            });
        }

        if (($filters['source_type'] ?? '') === 'supplier') {
            $query->where('catalog_source', '!=', 'local_product');
        }

        if (($filters['source_type'] ?? '') === 'local') {
            $query->where(function (Builder $inner) {
                $inner->where('catalog_source', 'local_product')
                    ->orWhere('local_stock_quantity', '>', 0);
            });
        }

        if (($filters['status'] ?? '') === 'active') {
            $query->where('is_active', true);
        }

        if (($filters['status'] ?? '') === 'inactive') {
            $query->where('is_active', false);
        }

        if (($filters['stock_state'] ?? '') === 'in_stock') {
            $query->whereRaw($this->effectiveStockSql() . ' > 0');
        }

        if (($filters['stock_state'] ?? '') === 'out_of_stock') {
            $query->whereRaw($this->effectiveStockSql() . ' <= 0');
        }

        if (($filters['stock_state'] ?? '') === 'local_stock') {
            $query->where('local_stock_quantity', '>', 0);
        }

        if (($filters['stock_state'] ?? '') === 'supplier_stock') {
            $query->where('supplier_stock_quantity', '>', 0);
        }

        if (($filters['visibility'] ?? '') === 'visible') {
            $query->where('visible_in_catalog', true);
        }

        if (($filters['visibility'] ?? '') === 'hidden') {
            $query->where('visible_in_catalog', false);
        }

        if (($filters['quote_visibility'] ?? '') === 'open') {
            $query->where('visible_in_quote', true);
        }

        if (($filters['quote_visibility'] ?? '') === 'closed') {
            $query->where('visible_in_quote', false);
        }

        if (($filters['product_type'] ?? '') === 'flat') {
            $query->whereIn('row_type', ['supplier_flat', 'local_product', 'local_stocked_supplier_product']);
        }

        if (($filters['product_type'] ?? '') === 'variant') {
            $query->where('row_type', 'supplier_variant');
        }

        if (($filters['price_state'] ?? '') === 'available') {
            $query->whereNotNull('display_price');
        }

        if (($filters['price_state'] ?? '') === 'missing') {
            $query->whereNull('display_price');
        }

        if (($filters['image_state'] ?? '') === 'available') {
            $query->whereNotNull('image_url');
        }

        if (($filters['image_state'] ?? '') === 'missing') {
            $query->whereNull('image_url');
        }

        $warningState = $filters['warning_state'] ?? '';
        if ($warningState === 'warning') {
            $query->where(function (Builder $inner) {
                $this->applyWarningWhere($inner);
            });
        }

        if ($warningState === 'missing_price') {
            $query->whereNull('display_price');
        }

        if ($warningState === 'missing_image') {
            $query->whereNull('image_url');
        }

        if ($warningState === 'missing_category') {
            $query->where(function (Builder $inner) {
                $inner->whereNull('standard_category_id')
                    ->orWhere('meta_json', 'like', '%category_missing%')
                    ->orWhere('meta_json', 'like', '%Kategori bekliyor%')
                    ->orWhere('meta_json', 'like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%');
            });
        }

        if ($warningState === 'stock_warning') {
            $query->whereRaw($this->effectiveStockSql() . ' <= 0');
        }

        if ($warningState === 'red_product') {
            $query->where('meta_json', 'like', '%supplier_warning_flag%');
        }

        if ($warningState === 'net_price') {
            $query->where('meta_json', 'like', '%net_price_warning%');
        }

        return $query;
    }

    private function baseRowsQuery(TenantAccount $tenant): Builder
    {
        $allowedSupplierIds = DB::table('tenant_supplier_access')
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $flat = DB::table('tenant_catalog_products as tcp')
            ->leftJoin('standard_products as sp', 'sp.id', '=', 'tcp.standard_product_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('standard_categories as sc', 'sc.id', '=', 'tcp.standard_category_id')
            ->where('tcp.tenant_account_id', $tenant->id)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('tcp.meta')
                    ->orWhere(function (Builder $inner) {
                        $inner
                            ->where('tcp.meta', 'not like', '%"is_parent":true%')
                            ->where('tcp.meta', 'not like', '%"is_parent": true%')
                            ->where('tcp.meta', 'not like', '%"is_sellable":false%')
                            ->where('tcp.meta', 'not like', '%"is_sellable": false%');
                    });
            })
            ->whereNotExists(function (Builder $query) {
                $query->selectRaw('1')
                    ->from('tenant_catalog_product_variants as tcpv')
                    ->whereColumn('tcpv.tenant_catalog_product_id', 'tcp.id')
                    ->where('tcpv.is_active', true)
                    ->where('tcpv.visible_in_catalog', true);
            })
            ->where(function (Builder $query) use ($tenant, $allowedSupplierIds) {
                $query->where('tcp.catalog_source', 'local_product')
                    ->orWhereNull('tcp.source_summary')
                    ->orWhere('tcp.source_summary', '[]')
                    ->orWhereExists(function (Builder $access) use ($tenant) {
                        $access->selectRaw('1')
                            ->from('tenant_supplier_access as tsa')
                            ->whereColumn('tsa.supplier_id', 'sp.supplier_id')
                            ->where('tsa.tenant_account_id', $tenant->id)
                            ->where('tsa.is_active', true)
                            ->where('tsa.can_view_products', true)
                            ->where('tsa.visible_in_catalog', true);
                    });

                foreach ($allowedSupplierIds as $supplierId) {
                    $this->orWhereJsonSupplierId($query, 'tcp.source_summary', $supplierId);
                }
            })
            ->where(function (Builder $query) use ($allowedSupplierIds) {
                $query->where('tcp.catalog_source', 'local_product')
                    ->orWhereNull('tcp.source_summary')
                    ->orWhere('tcp.source_summary', '[]')
                    ->orWhereNotNull('sp.supplier_id');

                foreach ($allowedSupplierIds as $supplierId) {
                    $this->orWhereJsonSupplierId($query, 'tcp.source_summary', $supplierId);
                }
            })
            ->selectRaw($this->flatSelectSql());

        $variants = DB::table('tenant_catalog_product_variants as tcpv')
            ->join('tenant_catalog_products as tcp', 'tcp.id', '=', 'tcpv.tenant_catalog_product_id')
            ->leftJoin('standard_products as sp', 'sp.id', '=', 'tcp.standard_product_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('standard_categories as sc', 'sc.id', '=', 'tcp.standard_category_id')
            ->where('tcp.tenant_account_id', $tenant->id)
            ->where('tcpv.tenant_account_id', $tenant->id)
            ->where('tcpv.is_active', true)
            ->where('tcpv.visible_in_catalog', true)
            ->whereExists(function (Builder $access) use ($tenant) {
                $access->selectRaw('1')
                    ->from('tenant_supplier_access as tsa')
                    ->whereColumn('tsa.supplier_id', 'sp.supplier_id')
                    ->where('tsa.tenant_account_id', $tenant->id)
                    ->where('tsa.is_active', true)
                    ->where('tsa.can_view_products', true)
                    ->where('tsa.visible_in_catalog', true);
            })
            ->orWhere(function (Builder $query) use ($tenant, $allowedSupplierIds) {
                $query->where('tcp.tenant_account_id', $tenant->id)
                    ->where('tcpv.tenant_account_id', $tenant->id)
                    ->where('tcpv.is_active', true)
                    ->where('tcpv.visible_in_catalog', true)
                    ->where(function (Builder $inner) use ($allowedSupplierIds) {
                        foreach ($allowedSupplierIds as $supplierId) {
                            $this->orWhereJsonSupplierId($inner, 'tcpv.source_summary', $supplierId);
                            $this->orWhereJsonSupplierId($inner, 'tcp.source_summary', $supplierId);
                        }
                    });
            })
            ->selectRaw($this->variantSelectSql());

        return $flat->unionAll($variants);
    }

    private function flatSelectSql(): string
    {
        return "
            CASE WHEN tcp.catalog_source = 'local_product' THEN 'local_product' WHEN COALESCE(tcp.local_stock_quantity, 0) > 0 THEN 'local_stocked_supplier_product' ELSE 'supplier_flat' END as row_type,
            tcp.id as id,
            tcp.id as tenant_catalog_product_id,
            NULL as tenant_catalog_product_variant_id,
            sp.supplier_id as supplier_id,
            NULL as supplier_source_id,
            s.name as supplier_name,
            tcp.catalog_source as catalog_source,
            tcp.product_code as product_code,
            COALESCE(tcp.product_name, tcp.name) as product_name,
            tcp.image_url as image_url,
            tcp.standard_category_id as standard_category_id,
            COALESCE(sc.path, sc.name) as category_name,
            COALESCE(tcp.local_stock_quantity, 0) as local_stock_quantity,
            COALESCE(tcp.supplier_stock_quantity, tcp.total_stock_quantity, tcp.stock_quantity, 0) as supplier_stock_quantity,
            tcp.display_price as display_price,
            tcp.currency as currency,
            tcp.visible_in_catalog as visible_in_catalog,
            tcp.visible_in_quote as visible_in_quote,
            tcp.catalog_status as catalog_status,
            tcp.is_active as is_active,
            tcp.local_stock_priority as local_stock_priority,
            tcp.hidden_reason as hidden_reason,
            tcp.is_featured as is_featured,
            NULL as variant_color,
            NULL as variant_size,
            NULL as variant_attributes_json,
            tcp.last_synced_at as last_synced_at,
            tcp.updated_at as updated_at,
            tcp.source_summary as source_summary_json,
            tcp.meta as meta_json,
            COALESCE(tcp.source_summary, sp.source_summary, tcp.meta, '') as search_blob
        ";
    }

    private function variantSelectSql(): string
    {
        return "
            'supplier_variant' as row_type,
            tcp.id as id,
            tcp.id as tenant_catalog_product_id,
            tcpv.id as tenant_catalog_product_variant_id,
            sp.supplier_id as supplier_id,
            NULL as supplier_source_id,
            s.name as supplier_name,
            tcp.catalog_source as catalog_source,
            tcpv.variant_code as product_code,
            COALESCE(tcpv.variant_name, tcp.product_name, tcp.name) as product_name,
            COALESCE(tcpv.image_url, tcp.image_url) as image_url,
            tcp.standard_category_id as standard_category_id,
            COALESCE(sc.path, sc.name) as category_name,
            COALESCE(tcpv.local_stock_quantity, 0) as local_stock_quantity,
            COALESCE(tcpv.supplier_stock_quantity, tcpv.stock_quantity, tcp.supplier_stock_quantity, tcp.total_stock_quantity, 0) as supplier_stock_quantity,
            COALESCE(tcpv.display_price, tcp.display_price) as display_price,
            COALESCE(tcpv.currency, tcp.currency) as currency,
            tcpv.visible_in_catalog as visible_in_catalog,
            tcp.visible_in_quote as visible_in_quote,
            tcp.catalog_status as catalog_status,
            tcpv.is_active as is_active,
            tcp.local_stock_priority as local_stock_priority,
            tcp.hidden_reason as hidden_reason,
            tcp.is_featured as is_featured,
            tcpv.variant_color as variant_color,
            tcpv.variant_size as variant_size,
            COALESCE(tcpv.meta, tcp.meta) as variant_attributes_json,
            COALESCE(tcp.last_synced_at, tcpv.updated_at) as last_synced_at,
            tcpv.updated_at as updated_at,
            COALESCE(tcpv.source_summary, tcp.source_summary) as source_summary_json,
            COALESCE(tcpv.meta, tcp.meta) as meta_json,
            COALESCE(tcpv.source_summary, tcp.source_summary, tcpv.meta, tcp.meta, sp.source_summary, '') as search_blob
        ";
    }

    private function hydrateRows(Collection $rows): Collection
    {
        return $rows->map(function (object $row) {
            $sourceSummary = $this->decodeJson($row->source_summary_json);
            $meta = $this->decodeJson($row->meta_json);
            $displayName = $row->row_type === 'supplier_variant'
                ? ProductDisplayNameFormatter::variant(
                    $row->product_code,
                    data_get($meta, 'parent_product_name') ?: $row->product_name,
                    data_get($meta, 'variant_name') ?: $row->product_name,
                    data_get($meta, 'variant_color'),
                    data_get($meta, 'variant_size'),
                    data_get($meta, 'variant_attributes.measure'),
                    data_get($meta, 'variant_attributes.capacity'),
                    data_get($meta, 'variant_attributes.option'),
                    [
                        data_get($sourceSummary, 'supplier_group_code'),
                        data_get($sourceSummary, 'supplier_product_code'),
                        data_get($sourceSummary, 'variant_stock_code'),
                        data_get($meta, 'parent_product_code'),
                    ]
                )
                : ProductDisplayNameFormatter::product($row->product_code, $row->product_name);

            $product = new TenantCatalogProduct();
            $product->setRawAttributes([
                'id' => (int) $row->tenant_catalog_product_id,
                'tenant_catalog_product_id' => (int) $row->tenant_catalog_product_id,
                'product_code' => $row->product_code,
                'tenant_sku' => $row->product_code,
                'product_name' => $displayName,
                'name' => $displayName,
                'image_url' => $row->image_url,
                'standard_category_id' => $row->standard_category_id,
                'local_stock_quantity' => $row->local_stock_quantity,
                'supplier_stock_quantity' => $row->supplier_stock_quantity,
                'total_stock_quantity' => (float) $row->local_stock_quantity + (float) $row->supplier_stock_quantity,
                'display_price' => $row->display_price,
                'currency' => $row->currency ?: 'TL',
                'visible_in_catalog' => (bool) $row->visible_in_catalog,
                'visible_in_quote' => (bool) $row->visible_in_quote,
                'catalog_status' => $row->catalog_status,
                'catalog_source' => $row->catalog_source,
                'is_active' => (bool) $row->is_active,
                'local_stock_priority' => (bool) $row->local_stock_priority,
                'hidden_reason' => $row->hidden_reason,
                'is_featured' => (bool) $row->is_featured,
                'last_synced_at' => $row->last_synced_at,
                'updated_at' => $row->updated_at,
                'source_summary' => json_encode($sourceSummary, JSON_UNESCAPED_UNICODE),
                'meta' => json_encode(array_merge($meta, [
                    'standard_category_name' => $row->category_name,
                    'is_parent' => false,
                    'is_sellable' => true,
                    'variant_color' => $row->variant_color,
                    'variant_size' => $row->variant_size,
                    'variant_attributes' => data_get($meta, 'variant_attributes', []),
                ]), JSON_UNESCAPED_UNICODE),
            ], true);
            $product->exists = true;
            $product->setRelation('category', null);
            $product->setRelation('standardProduct', null);
            $product->setRelation('primaryImage', null);
            $product->setRelation('images', collect());
            $product->setRelation('variants', collect());
            $product->setAttribute('catalog_row_type', $row->row_type === 'supplier_variant' ? 'variant' : 'flat');
            $product->setAttribute('catalog_row_variant_id', $row->tenant_catalog_product_variant_id ? (int) $row->tenant_catalog_product_variant_id : null);
            $product->setAttribute('catalog_source_label', $row->catalog_source === 'local_product' ? 'Local Ürün' : 'Tedarikçi Ürünü');
            $product->setAttribute('effective_stock_quantity', $this->effectiveStock((float) $row->local_stock_quantity, (float) $row->supplier_stock_quantity, (bool) $row->local_stock_priority));
            $product->setAttribute('has_local_stock_priority', (bool) $row->local_stock_priority && (float) $row->local_stock_quantity > 0);
            $product->setAttribute('warning_items', $this->warningsForRow($row, $meta));
            $product->setAttribute('supplier_label', $row->supplier_name ?: 'Tedarikçi');
            $product->setAttribute('local_stock_action_available', true);

            return $product;
        });
    }

    private function effectiveStock(float $localStock, float $supplierStock, bool $localPriority): float
    {
        if ($localPriority && $localStock > 0) {
            return $localStock;
        }

        return $supplierStock > 0 ? $supplierStock : max(0, $localStock);
    }

    private function warningsForRow(object $row, array $meta): array
    {
        $warnings = collect(array_merge(
            (array) data_get($meta, 'warning_snapshot', []),
            (array) data_get($meta, 'warnings', [])
        ));

        if (blank($row->display_price)) {
            $warnings->push('Fiyat eksik');
        }

        if (blank($row->image_url)) {
            $warnings->push('Görsel eksik');
        }

        if (blank($row->standard_category_id)
            || (bool) data_get($meta, 'category_missing_warning', false)
            || data_get($meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN') {
            $warnings->push('Kategori Bekliyor');
            $warnings->push('Kategori eksik');
        }

        if ($this->effectiveStock((float) $row->local_stock_quantity, (float) $row->supplier_stock_quantity, (bool) $row->local_stock_priority) <= 0) {
            $warnings->push('Stok yok');
        }

        if ((bool) data_get($meta, 'net_price_warning', data_get($meta, 'price_snapshot.net_price_warning', false))) {
            $warnings->push('Net fiyat uyarısı');
        }

        if ((bool) data_get($meta, 'supplier_warning_flag', data_get($meta, 'price_snapshot.supplier_warning_flag', false))) {
            $warnings->push('Tedarikçi özel fiyat uyarısı');
        }

        return $warnings->filter()->unique()->values()->all();
    }

    private function warningDescription(string $warning): string
    {
        return match ($warning) {
            'Fiyat eksik' => 'Ürün fiyatı eksik; teklif ve katalog görünürlüğü kontrol edilmeli.',
            'Görsel eksik' => 'Ürün görseli eksik veya projection görseli gelmemiş.',
            'Kategori Bekliyor' => 'Ürün geçici fallback kategoriyle görünür; standart kategori eşlemesi bekleniyor.',
            'Kategori eksik' => 'Standart kategori eşlemesi tamamlanmamış.',
            'Stok yok' => 'Local veya tedarikçi stok bilgisi satış için yetersiz.',
            'Net fiyat uyarısı' => 'Bu ürün net/sabit fiyatlı olabilir; iskonto kontrolü gerekli.',
            'Tedarikçi özel fiyat uyarısı' => 'Tedarikçi özel fiyat veya kırmızı ürün uyarısı gönderiyor.',
            default => 'Ürün için kontrol öneriliyor.',
        };
    }

    private function applyWarningWhere(Builder $query): void
    {
        $query
            ->whereNull('display_price')
            ->orWhereNull('image_url')
            ->orWhereNull('standard_category_id')
            ->orWhere('meta_json', 'like', '%category_missing%')
            ->orWhere('meta_json', 'like', '%PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN%')
            ->orWhereRaw($this->effectiveStockSql() . ' <= 0')
            ->orWhere('meta_json', 'like', '%warning%')
            ->orWhere('meta_json', 'like', '%net_price%')
            ->orWhere('meta_json', 'like', '%supplier_warning%');
    }

    private function effectiveStockSql(): string
    {
        return "CASE WHEN COALESCE(local_stock_priority, 1) = 1 AND COALESCE(local_stock_quantity, 0) > 0 THEN COALESCE(local_stock_quantity, 0) WHEN COALESCE(supplier_stock_quantity, 0) > 0 THEN COALESCE(supplier_stock_quantity, 0) ELSE COALESCE(local_stock_quantity, 0) END";
    }

    private function orWhereJsonSupplierId(Builder $query, string $column, int $supplierId): void
    {
        $query
            ->orWhere($column, 'like', '%"supplier_id":' . $supplierId . '%')
            ->orWhere($column, 'like', '%"supplier_id": ' . $supplierId . '%');
    }

    private function decodeJson(mixed $value): array
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
}
