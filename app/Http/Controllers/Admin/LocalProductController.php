<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StandardCategory;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Services\TenantCatalog\TenantCatalogProductSourceResolver;
use App\Services\TenantCatalog\TenantLocalProductQueryService;
use App\Services\TenantCatalog\TenantLocalProductWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocalProductController extends Controller
{
    public function __construct(
        private readonly TenantLocalProductQueryService $queryService,
        private readonly TenantLocalProductWriteService $writeService,
        private readonly TenantCatalogProductSourceResolver $sourceResolver,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        if ($request->filled('edit')) {
            $filters = $this->filters($request);
            $categories = $this->tenantVisibleCategories();
            $editProduct = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('catalog_source', 'local_product')
                ->findOrFail((int) $request->integer('edit'))
                ->load(['variants', 'localStocks']);

            return view('admin.catalog.local-products-create', compact('filters', 'categories', 'editProduct'));
        }

        $filters = $this->filters($request);
        $products = $this->queryService->ownProductsForTenant($tenant, $filters, $request);
        $stats = $this->queryService->ownProductStats($tenant);

        $categories = $this->tenantVisibleCategories();

        return view('admin.catalog.local-products-index', compact('products', 'stats', 'filters', 'categories'));
    }

    public function create(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->filters($request);
        $categories = $this->tenantVisibleCategories();
        $editProduct = null;

        return view('admin.catalog.local-products-create', compact('filters', 'categories', 'editProduct'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $product = $this->writeService->create($tenant, $request->all(), $request, $request->user());

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', 'Kendi ürün başarıyla kaydedildi.');
    }

    public function show(TenantCatalogProduct $product): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);
        $this->writeService->assertOwnProduct($tenant, $product);

        $product->load(['category', 'standardProduct', 'images', 'primaryImage', 'variants.images', 'localStocks']);
        $product->setAttribute('warning_items', []);
        $selectedVariant = null;

        return view('admin.catalog.local-products.show', compact('product', 'selectedVariant'));
    }

    public function showVariant(TenantCatalogProduct $product, TenantCatalogProductVariant $variant): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);
        $this->writeService->assertOwnProduct($tenant, $product);
        abort_unless((int) $variant->tenant_account_id === (int) $tenant->id, 403);
        abort_unless((int) $variant->tenant_catalog_product_id === (int) $product->id, 404);

        $product->load(['category', 'standardProduct', 'images', 'primaryImage', 'variants.images', 'localStocks']);
        $variant->loadMissing(['images', 'catalogProduct']);
        $product->setAttribute('warning_items', []);
        $selectedVariant = $variant;

        return view('admin.catalog.local-products.show', compact('product', 'selectedVariant'));
    }

    public function edit(Request $request, TenantCatalogProduct $product): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);
        $this->writeService->assertOwnProduct($tenant, $product);

        $filters = $this->filters($request);
        $categories = $this->tenantVisibleCategories();
        $editProduct = $product->load(['variants', 'localStocks']);

        return view('admin.catalog.local-products-create', compact('filters', 'categories', 'editProduct'));
    }

    public function update(Request $request, TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $product = $this->writeService->update($tenant, $product, $request->all(), $request, $request->user());

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', 'Kendi ürün güncellendi.');
    }

    public function deactivate(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $this->writeService->deactivate($tenant, $product);

        return back()->with('success', 'Kendi ürün pasif yapıldı.');
    }

    public function destroy(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $this->writeService->destroy($tenant, $product);

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', 'Kendi ürün kaldırıldı veya geçmişi nedeniyle arşivlendi.');
    }

    private function tenantVisibleCategories(): \Illuminate\Support\Collection
    {
        return StandardCategory::query()->permanentBackbone()->orderBy('path')->get();
    }

    private function currentTenant(): ?TenantAccount
    {
        return request()->attributes->get('current_tenant')
            ?? auth()->user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->orderBy('id')->first();
    }

    private function filters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'stock_state' => trim((string) $request->query('stock_state', '')),
            'limit' => (string) $request->query('limit', '50'),
        ];
    }
}
