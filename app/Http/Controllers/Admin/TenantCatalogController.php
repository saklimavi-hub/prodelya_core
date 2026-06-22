<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\TenantSupplierAccess;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantCatalogController extends Controller
{
    public function __construct(
        private readonly TenantCatalogProjectionService $projectionService,
        private readonly TenantCatalogListRowQueryService $listRowQueryService
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        [$products, $stats, $filters, $categories, $suppliers, $summary] = $this->buildCatalogPageData($tenant, $request);

        return view('admin.catalog.index', compact('products', 'stats', 'filters', 'categories', 'suppliers', 'summary'));
    }

    public function productPanel(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->catalogFilters($request);
        [$products, $stats, $categories, $suppliers, $summary] = $this->catalogListingData($tenant, $filters);

        return view('admin.catalog.product-panel', compact('products', 'stats', 'filters', 'categories', 'suppliers', 'summary'));
    }

    public function supplierProducts(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->catalogFilters($request);
        $filters['source_type'] = 'supplier';

        [$products, $stats, $categories, $suppliers, $summary] = $this->catalogListingData($tenant, $filters);

        return view('admin.catalog.supplier-products', compact('products', 'stats', 'filters', 'categories', 'suppliers', 'summary'));
    }

    public function localProducts(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->catalogFilters($request);
        $filters['source_type'] = 'local';

        [$products, $stats, $categories, $suppliers, $summary] = $this->catalogListingData($tenant, $filters);
        $editProduct = null;

        if ($request->filled('edit')) {
            $editProduct = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('catalog_source', 'local_product')
                ->findOrFail((int) $request->integer('edit'));
        }

        return view('admin.catalog.local-products', compact('products', 'stats', 'filters', 'categories', 'suppliers', 'summary', 'editProduct'));
    }

    public function storeLocalProduct(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $validated = $this->validateLocalProduct($request, $tenant);
        $localStock = (float) ($validated['local_stock_quantity'] ?? 0);

        $product = TenantCatalogProduct::query()->create(
            $this->buildLocalProductPayload($tenant, $validated)
        );

        $this->syncLocalStockRecord($tenant, $product, $localStock);

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', 'Local ürün başarıyla eklendi.');
    }

    public function updateLocalProduct(Request $request, TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);
        abort_unless($this->isLocalProduct($product), 404);

        $validated = $this->validateLocalProduct($request, $tenant, $product);
        $localStock = (float) ($validated['local_stock_quantity'] ?? 0);

        $product->update($this->buildLocalProductPayload($tenant, $validated, $product));
        $this->syncLocalStockRecord($tenant, $product, $localStock);

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', 'Local ürün güncellendi.');
    }

    public function deactivateLocalProduct(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);
        abort_unless($this->isLocalProduct($product), 404);

        $product->update([
            'is_active' => false,
            'visible_in_catalog' => false,
            'visible_in_quote' => false,
            'hidden_reason' => 'Tenant tarafından pasifleştirildi.',
            'catalog_status' => 'local_inactive',
        ]);

        return back()->with('success', 'Local ürün pasif yapıldı.');
    }

    public function destroyLocalProduct(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);
        abort_unless($this->isLocalProduct($product), 404);

        if ($this->productUsedInSales($product)) {
            $product->update([
                'is_active' => false,
                'visible_in_catalog' => false,
                'visible_in_quote' => false,
                'hidden_reason' => 'Geçmiş teklif/sipariş kaydı olduğu için arşivlendi.',
                'catalog_status' => 'local_archived',
            ]);

            return back()->with('success', 'Local ürün geçmiş kullanımı olduğu için silinmedi, arşivlendi.');
        }

        $product->localStocks()->delete();
        $product->delete();

        return back()->with('success', 'Local ürün güvenli şekilde silindi.');
    }

    public function visibility(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->catalogFilters($request);
        [$products, $stats, $categories, $suppliers, $summary] = $this->catalogListingData($tenant, $filters);

        return view('admin.catalog.visibility', compact('products', 'stats', 'filters', 'categories', 'suppliers', 'summary'));
    }

    public function bulkUpdateVisibility(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $validated = $request->validate([
            'action' => 'required|string|in:save_rows,show_selected,hide_selected,enable_quote,disable_quote,hide_warnings,disable_missing_price',
            'selected_products' => 'nullable|array',
            'selected_products.*' => 'integer',
            'rows' => 'nullable|array',
        ]);

        $products = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->get()
            ->keyBy('id');

        if ($validated['action'] === 'save_rows') {
            $rows = (array) ($validated['rows'] ?? []);
            $updated = 0;

            foreach ($rows as $productId => $row) {
                $product = $products->get((int) $productId);
                if (!$product) {
                    continue;
                }

                $product->update([
                    'visible_in_catalog' => array_key_exists('visible_in_catalog', $row),
                    'visible_in_quote' => array_key_exists('visible_in_quote', $row),
                    'is_featured' => array_key_exists('is_featured', $row),
                    'local_stock_priority' => array_key_exists('local_stock_priority', $row),
                    'hidden_reason' => blank($row['hidden_reason'] ?? null) ? null : $row['hidden_reason'],
                ]);
                $updated++;
            }

            return back()->with('success', $updated > 0 ? "{$updated} ürün güncellendi." : 'Seçilen ürün bulunamadı.');
        }

        $selectedIds = collect((array) ($validated['selected_products'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($selectedIds->isEmpty()) {
            return back()->with('error', 'Seçilen ürün bulunamadı.');
        }

        $selectedProducts = $products->only($selectedIds->all())->values();

        if ($selectedProducts->isEmpty()) {
            return back()->with('error', 'Seçilen ürün bulunamadı.');
        }

        $updated = 0;
        $message = null;

        foreach ($selectedProducts as $product) {
            if ($validated['action'] === 'show_selected') {
                $product->update(['visible_in_catalog' => true, 'hidden_reason' => null]);
                $updated++;
            } elseif ($validated['action'] === 'hide_selected') {
                $product->update(['visible_in_catalog' => false, 'hidden_reason' => 'Tenant tarafından toplu gizlendi.']);
                $updated++;
            } elseif ($validated['action'] === 'enable_quote') {
                $product->update(['visible_in_quote' => true]);
                $updated++;
            } elseif ($validated['action'] === 'disable_quote') {
                $product->update(['visible_in_quote' => false]);
                $updated++;
            } elseif ($validated['action'] === 'hide_warnings' && !empty($this->productWarnings($product))) {
                $product->update(['visible_in_catalog' => false, 'hidden_reason' => 'Uyarılı ürün olarak toplu gizlendi.']);
                $updated++;
            } elseif ($validated['action'] === 'disable_missing_price' && $this->productHasWarningType($product, 'Fiyat eksik')) {
                $product->update(['visible_in_quote' => false]);
                $updated++;
            }
        }

        if ($validated['action'] === 'disable_missing_price') {
            $message = "{$updated} ürün fiyat eksik olduğu için teklife kapatıldı.";
        } else {
            $message = $updated > 0 ? "{$updated} ürün güncellendi." : 'Seçilen ürün bulunamadı.';
        }

        return back()->with($updated > 0 ? 'success' : 'error', $message);
    }

    public function warnings(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = $this->catalogFilters($request);
        $warningRows = $this->listRowQueryService->warningRows($tenant, $filters, $request);
        $metrics = $this->listRowQueryService->metrics($tenant);
        $stats = $metrics['stats'];
        $summary = $metrics['summary'];
        $suppliers = $this->listRowQueryService->supplierOptions($tenant);

        return view('admin.catalog.warnings', compact('warningRows', 'stats', 'summary', 'filters', 'suppliers'));
    }

    public function project(): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $result = $this->projectionService->projectForTenant($tenant);

        return redirect()
            ->route('admin.catalog.index')
            ->with('success', "Katalog projeksiyonu güncellendi. Ürün: {$result['products']}, Varyasyon: {$result['variants']}.");
    }

    public function show(TenantCatalogProduct $product): View
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $product->load(['category', 'standardProduct', 'images', 'primaryImage', 'variants.images', 'localStocks']);
        $product->setAttribute('warning_items', $this->productWarnings($product));

        return view('admin.catalog.show', compact('product'));
    }

    public function toggleVisibility(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $newValue = !$product->visible_in_catalog;

        $product->update([
            'visible_in_catalog' => $newValue,
            'hidden_reason' => $newValue ? null : 'Tenant tarafından katalogda gizlendi.',
        ]);

        return back()->with('success', $newValue ? 'Ürün katalogda görünür yapıldı.' : 'Ürün katalogdan gizlendi.');
    }

    public function toggleQuoteVisibility(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $product->update([
            'visible_in_quote' => !$product->visible_in_quote,
        ]);

        return back()->with('success', $product->visible_in_quote ? 'Ürün teklifte kullanılabilir yapıldı.' : 'Ürün teklif aramasından gizlendi.');
    }

    public function updateLocalStock(Request $request, TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);
        $this->ensureSellableForLocalStock($product);

        $validated = $request->validate([
            'local_stock_quantity' => 'required|numeric|min:0',
        ]);

        $localStock = (float) $validated['local_stock_quantity'];
        $supplierStock = (float) ($product->supplier_stock_quantity ?? 0);
        $isLocalProduct = $this->isLocalProduct($product);

        $product->update([
            'local_stock_quantity' => $localStock,
            'total_stock_quantity' => $isLocalProduct ? $localStock : $localStock + $supplierStock,
            'stock_quantity' => (int) round($isLocalProduct ? $localStock : $localStock + $supplierStock),
            'meta' => array_merge((array) ($product->meta ?? []), [
                'stock_snapshot' => array_merge((array) data_get($product->meta, 'stock_snapshot', []), [
                    'stock_quantity' => $isLocalProduct ? $localStock : $localStock + $supplierStock,
                    'local_stock_quantity' => $localStock,
                    'supplier_stock_quantity' => $supplierStock,
                ]),
            ]),
        ]);

        $this->syncLocalStockRecord($tenant, $product, $localStock);

        return back()->with('success', 'Local stok güncellendi.');
    }

    public function storeLocalStockEntry(Request $request, TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $validated = $request->validate([
            'entry_type' => 'required|string|in:supplier_purchase,existing_stock',
            'tenant_catalog_product_variant_id' => 'nullable|integer|exists:tenant_catalog_product_variants,id',
            'quantity' => 'required|numeric|min:0.0001',
            'list_price' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'calculated_purchase_unit_price' => 'nullable|numeric|min:0',
            'unit_purchase_price' => 'nullable|required_if:entry_type,supplier_purchase|numeric|min:0',
            'manual_purchase_unit_price' => 'nullable|boolean',
            'currency' => 'nullable|string|max:3',
            'vat_enabled' => 'nullable|boolean',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'document_no' => 'nullable|string|max:100',
            'entry_date' => 'nullable|date',
            'warehouse_code' => 'nullable|string|max:100',
            'location_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        $variant = $this->resolveSellableVariantForLocalStock($product, $validated['tenant_catalog_product_variant_id'] ?? null);
        $this->ensureSellableForLocalStock($product, $variant);

        DB::transaction(function () use ($tenant, $product, $variant, $validated): void {
            $this->applyLocalStockEntry($tenant, $product, $validated, $variant);
        });

        $message = $validated['entry_type'] === 'supplier_purchase'
            ? 'Tedarikçiden satın alma kaydedildi, local stok ve borç hareketi oluşturuldu.'
            : 'Eldeki mevcut stok borç oluşturmadan local stoğa eklendi.';

        return back()->with('success', $message);
    }

    public function localProductsImport(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $categories = StandardCategory::query()->permanentBackbone()->orderBy('path')->get();
        $preview = session('local_product_import_preview');

        return view('admin.catalog.local-products-import', compact('categories', 'preview'));
    }

    public function localProductsImportTemplate(): Response
    {
        $csv = implode("\n", [
            'urun_kodu,urun_adi,kategori,stok,liste_fiyati,para_birimi,kdv_var,renk,olcu,gorsel_url,aciklama,katalogda_gorunsun,teklifte_kullanilsin',
            'PRD-001,Örnek Local Ürün,Promosyon Ürünleri,100,25.50,TL,1,Mavi,10x20 cm,https://example.com/gorsel.jpg,Örnek açıklama,1,1',
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prodelya-local-urun-sablonu.csv"',
        ]);
    }

    public function previewLocalProductsImport(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $rows = $this->parseCsvFile($validated['file']->getRealPath());
        $headers = array_keys($rows[0] ?? []);
        $previewRows = collect($rows)->take(20)->values()->all();
        $errors = $this->validateImportRows($rows);

        session([
            'local_product_import_preview' => [
                'headers' => $headers,
                'rows' => $rows,
                'preview_rows' => $previewRows,
                'errors' => $errors,
                'total' => count($rows),
            ],
        ]);

        return redirect()
            ->route('admin.catalog.local-products.import')
            ->with('success', 'Import önizlemesi hazırlandı. İlk 20 satırı kontrol edin.');
    }

    public function storeLocalProductsImport(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $preview = session('local_product_import_preview');
        if (!$preview || empty($preview['rows'])) {
            return back()->with('error', 'Import önizlemesi bulunamadı. Önce dosya yükleyin.');
        }

        $policy = $request->string('duplicate_policy')->toString() ?: 'update';
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        DB::transaction(function () use ($tenant, $preview, $policy, &$result): void {
            foreach ($preview['rows'] as $row) {
                $normalized = $this->normalizeImportRow($row);

                if (blank($normalized['product_code']) || blank($normalized['product_name'])) {
                    $result['errors']++;
                    continue;
                }

                $existing = TenantCatalogProduct::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('catalog_source', 'local_product')
                    ->where('product_code', $normalized['product_code'])
                    ->first();

                if ($existing && $policy === 'skip') {
                    $result['skipped']++;
                    continue;
                }

                $payload = $this->buildLocalProductPayload($tenant, $normalized, $existing);

                if ($existing) {
                    $existing->update($payload);
                    $this->syncLocalStockRecord($tenant, $existing, (float) ($normalized['local_stock_quantity'] ?? 0));
                    $result['updated']++;
                } else {
                    $product = TenantCatalogProduct::query()->create($payload);
                    $this->syncLocalStockRecord($tenant, $product, (float) ($normalized['local_stock_quantity'] ?? 0));
                    $result['created']++;
                }
            }
        });

        session()->forget('local_product_import_preview');

        return redirect()
            ->route('admin.catalog.local-products')
            ->with('success', "Import tamamlandı. Eklenen: {$result['created']}, güncellenen: {$result['updated']}, atlanan: {$result['skipped']}, hatalı: {$result['errors']}.");
    }

    public function markWarningReviewed(TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $product->update([
            'meta' => array_merge((array) ($product->meta ?? []), [
                'warning_reviewed_at' => now()->toDateTimeString(),
            ]),
        ]);

        return back()->with('success', 'Ürün uyarısı kontrol edildi olarak işaretlendi.');
    }

    public function quickWarningAction(Request $request, TenantCatalogProduct $product): RedirectResponse
    {
        $tenant = $this->currentTenant();
        $this->ensureTenantProduct($tenant, $product);

        $validated = $request->validate([
            'action' => 'required|string|in:hide_catalog,disable_quote,local_stock_boost',
        ]);

        if ($validated['action'] === 'hide_catalog') {
            $product->update([
                'visible_in_catalog' => false,
                'hidden_reason' => 'Uyarı ekranından gizlendi.',
            ]);

            return back()->with('success', 'Ürün katalogda gizlendi.');
        }

        if ($validated['action'] === 'disable_quote') {
            $product->update(['visible_in_quote' => false]);

            return back()->with('success', 'Ürün teklifte kullanılamaz yapıldı.');
        }

        $localStock = (float) ($product->local_stock_quantity ?? 0) + 1;
        $supplierStock = (float) ($product->supplier_stock_quantity ?? 0);
        $isLocalProduct = $this->isLocalProduct($product);

        $product->update([
            'local_stock_quantity' => $localStock,
            'total_stock_quantity' => $isLocalProduct ? $localStock : $localStock + $supplierStock,
            'stock_quantity' => (int) round($isLocalProduct ? $localStock : $localStock + $supplierStock),
        ]);
        $this->syncLocalStockRecord($tenant, $product, $localStock);

        return back()->with('success', 'Local fiyat/stok müdahalesi için local stok güncellendi.');
    }

    private function buildCatalogPageData(TenantAccount $tenant, Request $request): array
    {
        $filters = $this->catalogFilters($request);
        [$products, $stats, $categories, $suppliers, $summary] = $this->catalogListingData($tenant, $filters);

        return [$products, $stats, $filters, $categories, $suppliers, $summary];
    }

    private function catalogListingData(TenantAccount $tenant, array $filters): array
    {
        $products = $this->listRowQueryService->paginate($tenant, $filters, request(), 'products');
        $metrics = $this->listRowQueryService->metrics($tenant);
        $stats = $metrics['stats'];
        $categories = StandardCategory::query()->permanentBackbone()->orderBy('path')->get();
        $suppliers = $this->listRowQueryService->supplierOptions($tenant);
        $summary = $metrics['summary'];

        return [$products, $stats, $categories, $suppliers, $summary];
    }

    private function tenantCatalogProducts(TenantAccount $tenant): Collection
    {
        $allowedSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->all();
        $tenantHasAnyAccessRule = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->exists();

        return TenantCatalogProduct::query()
            ->with(['category', 'standardProduct', 'images', 'primaryImage', 'variants', 'localStocks'])
            ->where('tenant_account_id', $tenant->id)
            ->latest('updated_at')
            ->get()
            ->filter(function (TenantCatalogProduct $product) use ($allowedSupplierIds, $tenantHasAnyAccessRule) {
                if ($this->isLocalProduct($product)) {
                    return true;
                }

                $supplierIds = collect($product->source_summary ?? [])->pluck('supplier_id')->filter()->unique()->values()->all();

                if ($supplierIds === []) {
                    return true;
                }

                if (!$tenantHasAnyAccessRule) {
                    return true;
                }

                if ($allowedSupplierIds === []) {
                    return false;
                }

                return collect($supplierIds)->intersect($allowedSupplierIds)->isNotEmpty();
            })
            ->flatMap(fn (TenantCatalogProduct $product) => $this->expandSellableCatalogRows($product))
            ->values()
            ->map(function (TenantCatalogProduct $product) {
                $product->setAttribute('catalog_source_label', $product->catalog_source_label);
                $product->setAttribute('effective_stock_quantity', $product->effective_stock_quantity);
                $product->setAttribute('has_local_stock_priority', $product->has_local_stock_priority);
                $product->setAttribute('warning_items', $this->productWarnings($product));
                $product->setAttribute('supplier_label', $this->productSupplierLabel($product));

                return $product;
            });
    }

    private function catalogFilters(Request $request): array
    {
        return [
            'search' => trim($request->string('search')->toString()),
            'category' => $request->integer('category') ?: null,
            'category_status' => $request->string('category_status')->toString(),
            'status' => $request->string('status')->toString(),
            'supplier' => $request->integer('supplier') ?: null,
            'source_type' => $request->string('source_type')->toString(),
            'stock_state' => $request->string('stock_state')->toString(),
            'warning_state' => $request->string('warning_state')->toString(),
            'visibility' => $request->string('visibility')->toString(),
            'quote_visibility' => $request->string('quote_visibility')->toString(),
            'product_type' => $request->string('product_type')->toString(),
            'price_state' => $request->string('price_state')->toString(),
            'image_state' => $request->string('image_state')->toString(),
            'limit' => $this->normalizeLimit($request),
        ];
    }

    private function filterCatalogProducts(Collection $products, array $filters): Collection
    {
        return $products->filter(function (TenantCatalogProduct $product) use ($filters) {
            if ($filters['search'] !== '') {
                $haystack = Str::lower(implode(' ', array_filter([
                    $product->display_name,
                    $product->display_code,
                    $this->productSupplierLabel($product),
                    $product->category_display_name,
                    collect($product->variants)->pluck('variant_code')->implode(' '),
                ])));

                if (!Str::contains($haystack, Str::lower($filters['search']))) {
                    return false;
                }
            }

            if ($filters['category'] && (int) $product->standard_category_id !== (int) $filters['category']) {
                return false;
            }

            if ($filters['supplier']) {
                $supplierIds = collect($product->source_summary ?? [])->pluck('supplier_id')->filter()->all();
                if (!in_array((int) $filters['supplier'], array_map('intval', $supplierIds), true)) {
                    return false;
                }
            }

            if ($filters['source_type'] === 'supplier' && $this->isLocalProduct($product)) {
                return false;
            }

            if ($filters['source_type'] === 'local' && !$this->isLocalProduct($product) && (float) ($product->local_stock_quantity ?? 0) <= 0) {
                return false;
            }

            if ($filters['status'] === 'active' && !$product->is_active) {
                return false;
            }

            if ($filters['status'] === 'inactive' && $product->is_active) {
                return false;
            }

            if ($filters['stock_state'] === 'in_stock' && (float) $product->effective_stock_quantity <= 0) {
                return false;
            }

            if ($filters['stock_state'] === 'out_of_stock' && (float) $product->effective_stock_quantity > 0) {
                return false;
            }

            if ($filters['stock_state'] === 'local_stock' && (float) ($product->local_stock_quantity ?? 0) <= 0) {
                return false;
            }

            if ($filters['stock_state'] === 'supplier_stock' && (float) ($product->supplier_stock_quantity ?? 0) <= 0) {
                return false;
            }

            if ($filters['warning_state'] === 'warning' && empty($product->warning_items)) {
                return false;
            }

            if ($filters['warning_state'] === 'missing_price' && !$this->productHasWarningType($product, 'Fiyat eksik')) {
                return false;
            }

            if ($filters['warning_state'] === 'missing_image' && !$this->productHasWarningType($product, 'Görsel eksik')) {
                return false;
            }

            if ($filters['warning_state'] === 'missing_category' && !$this->productHasWarningType($product, 'Kategori eksik')) {
                return false;
            }

            if ($filters['warning_state'] === 'stock_warning' && !$this->productHasWarningType($product, 'Stok yok')) {
                return false;
            }

            if ($filters['warning_state'] === 'red_product' && !$this->productHasWarningType($product, 'Tedarikçi özel fiyat uyarısı')) {
                return false;
            }

            if ($filters['warning_state'] === 'net_price' && !$this->productHasWarningType($product, 'Net fiyat uyarısı')) {
                return false;
            }

            if ($filters['visibility'] === 'visible' && !$product->visible_in_catalog) {
                return false;
            }

            if ($filters['visibility'] === 'hidden' && $product->visible_in_catalog) {
                return false;
            }

            if ($filters['quote_visibility'] === 'open' && !$product->visible_in_quote) {
                return false;
            }

            if ($filters['quote_visibility'] === 'closed' && $product->visible_in_quote) {
                return false;
            }

            if ($filters['product_type'] === 'parent' && !$product->is_parent_group) {
                return false;
            }

            if ($filters['product_type'] === 'flat' && $this->catalogRowType($product) !== 'flat') {
                return false;
            }

            if ($filters['product_type'] === 'variant' && $this->catalogRowType($product) !== 'variant') {
                return false;
            }

            if ($filters['price_state'] === 'available' && blank($product->display_price)) {
                return false;
            }

            if ($filters['price_state'] === 'missing' && filled($product->display_price)) {
                return false;
            }

            return true;
        })->values();
    }

    private function normalizeLimit(Request $request): int|string
    {
        $limit = $request->string('limit')->toString();

        if ($limit === 'all') {
            return 'all';
        }

        $limit = (int) ($limit ?: 50);

        return in_array($limit, [50, 100, 250, 500], true) ? $limit : 50;
    }

    private function paginateCollection(Collection $items, array $filters, Request $request, string $pageName): LengthAwarePaginator
    {
        $limit = $filters['limit'] ?? 50;
        $page = max(1, (int) $request->query($pageName === 'products' ? 'page' : $pageName . '_page', 1));
        $perPage = $limit === 'all' ? max($items->count(), 1) : (int) $limit;
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return (new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName === 'products' ? 'page' : $pageName . '_page',
            ]
        ))->appends($request->query());
    }

    private function catalogStats(Collection $products): array
    {
        return [
            'total' => $products->count(),
            'supplier' => $products->reject(fn (TenantCatalogProduct $product) => $this->isLocalProduct($product))->count(),
            'local' => $products->filter(fn (TenantCatalogProduct $product) => $this->isLocalProduct($product))->count(),
            'in_stock' => $products->filter(fn (TenantCatalogProduct $product) => (float) $product->effective_stock_quantity > 0)->count(),
            'missing_price' => $products->filter(fn (TenantCatalogProduct $product) => $this->productHasWarningType($product, 'Fiyat eksik'))->count(),
            'warning' => $products->filter(fn (TenantCatalogProduct $product) => !empty($product->warning_items))->count(),
            'visible' => $products->where('visible_in_catalog', true)->count(),
            'hidden' => $products->where('visible_in_catalog', false)->count(),
        ];
    }

    private function catalogSummary(Collection $products): array
    {
        return [
            'total_products' => $products->count(),
            'local_stock_priority' => $products->filter(fn (TenantCatalogProduct $product) => $product->has_local_stock_priority)->count(),
            'supplier_products' => $products->reject(fn (TenantCatalogProduct $product) => $this->isLocalProduct($product))->count(),
            'missing_price' => $products->filter(fn (TenantCatalogProduct $product) => $this->productHasWarningType($product, 'Fiyat eksik'))->count(),
            'warnings' => $products->filter(fn (TenantCatalogProduct $product) => !empty($product->warning_items))->count(),
            'visible' => $products->where('visible_in_catalog', true)->count(),
            'last_sync' => optional($products->whereNotNull('last_synced_at')->sortByDesc('last_synced_at')->first()?->last_synced_at)->format('d.m.Y H:i') ?: 'Henüz yok',
        ];
    }

    private function supplierOptions(TenantAccount $tenant, Collection $products): Collection
    {
        $accessSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->pluck('supplier_id')
            ->filter()
            ->values();

        $supplierIds = $accessSupplierIds->isNotEmpty()
            ? $accessSupplierIds
            : $products
                ->flatMap(fn (TenantCatalogProduct $product) => collect($product->source_summary ?? [])->pluck('supplier_id'))
                ->filter()
                ->unique()
                ->values();

        return Supplier::query()
            ->whereIn('id', $supplierIds->all())
            ->orderBy('name')
            ->get();
    }

    private function buildWarningRows(Collection $products): Collection
    {
        return $products->flatMap(function (TenantCatalogProduct $product) {
            return collect($this->productWarnings($product))->map(function (string $warning) use ($product) {
                return [
                    'product' => $product,
                    'warning_type' => $warning,
                    'description' => $this->warningDescription($warning, $product),
                ];
            });
        })->values();
    }

    private function buildLocalProductWarnings(array $validated, float $localStock): array
    {
        $warnings = [];

        if (blank($validated['display_price'] ?? null)) {
            $warnings[] = 'Fiyat eksik';
        }

        if (blank($validated['image_url'] ?? null)) {
            $warnings[] = 'Görsel eksik';
        }

        if (blank($validated['standard_category_id'] ?? null)) {
            $warnings[] = 'Kategori eksik';
        }

        if ($localStock <= 0) {
            $warnings[] = 'Stok yok';
        }

        return $warnings;
    }

    private function expandSellableCatalogRows(TenantCatalogProduct $product): Collection
    {
        $visibleVariants = $product->variants
            ->filter(fn (TenantCatalogProductVariant $variant) => (bool) $variant->is_active && (bool) $variant->visible_in_catalog)
            ->values();

        if ($visibleVariants->isNotEmpty()) {
            return $visibleVariants
                ->filter(fn (TenantCatalogProductVariant $variant) => (bool) data_get($variant->meta, 'is_sellable', true))
                ->map(fn (TenantCatalogProductVariant $variant) => $this->buildVariantCatalogRow($product, $variant));
        }

        if ($product->is_parent_group || !$product->is_sellable) {
            return collect();
        }

        $product->setAttribute('catalog_row_type', 'flat');
        $product->setAttribute('local_stock_action_available', true);

        return collect([$product]);
    }

    private function buildVariantCatalogRow(TenantCatalogProduct $product, TenantCatalogProductVariant $variant): TenantCatalogProduct
    {
        $variant->setRelation('catalogProduct', $product);

        $meta = array_merge((array) ($product->meta ?? []), (array) ($variant->meta ?? []), [
            'is_parent' => false,
            'is_variant' => true,
            'is_sellable' => true,
            'price_snapshot' => array_merge(
                (array) data_get($product->meta, 'price_snapshot', []),
                (array) data_get($variant->meta, 'price_snapshot', [])
            ),
            'stock_snapshot' => array_merge(
                (array) data_get($product->meta, 'stock_snapshot', []),
                [
                    'stock_quantity' => $variant->stock_quantity ?? $product->total_stock_quantity,
                    'local_stock_quantity' => $variant->local_stock_quantity ?? 0,
                    'supplier_stock_quantity' => $variant->supplier_stock_quantity ?? $product->supplier_stock_quantity,
                ]
            ),
        ]);

        $row = new TenantCatalogProduct();
        $sourceSummary = $this->sourceSummaryRows($variant->source_summary ?: $product->source_summary);

        $row->setRawAttributes(array_merge($product->getAttributes(), [
            'product_code' => $variant->variant_code,
            'tenant_sku' => $variant->variant_code,
            'product_name' => $variant->display_name,
            'name' => $variant->display_name,
            'image_url' => $variant->image_url ?: $product->image_url,
            'display_price' => $variant->display_price ?? $product->display_price,
            'currency' => $variant->currency ?? $product->currency,
            'total_stock_quantity' => $variant->stock_quantity ?? $product->total_stock_quantity,
            'local_stock_quantity' => $variant->local_stock_quantity ?? 0,
            'supplier_stock_quantity' => $variant->supplier_stock_quantity ?? $product->supplier_stock_quantity,
            'safe_stock_quantity' => $variant->safe_stock_quantity ?? $product->safe_stock_quantity,
            'visible_in_catalog' => $variant->visible_in_catalog,
            'visible_in_quote' => (bool) data_get($variant->meta, 'quote_search_visible', $product->visible_in_quote),
            'is_active' => $variant->is_active,
        ]), true);
        $row->setAttribute('source_summary', $sourceSummary);
        $row->setAttribute('meta', $meta);

        $row->exists = true;
        $row->setRelation('category', $product->relationLoaded('category') ? $product->getRelation('category') : null);
        $row->setRelation('standardProduct', $product->relationLoaded('standardProduct') ? $product->getRelation('standardProduct') : null);
        $row->setRelation('primaryImage', $product->relationLoaded('primaryImage') ? $product->getRelation('primaryImage') : null);
        $row->setRelation('images', $product->relationLoaded('images') ? $product->getRelation('images') : collect());
        $row->setRelation('variants', collect());
        $row->setAttribute('catalog_row_type', 'variant');
        $row->setAttribute('catalog_row_variant_id', $variant->id);
        $row->setAttribute('local_stock_action_available', true);

        return $row;
    }

    private function catalogRowType(TenantCatalogProduct $product): string
    {
        return (string) ($product->getAttribute('catalog_row_type') ?: ($product->is_parent_group ? 'parent' : 'flat'));
    }

    private function sourceSummaryRows(mixed $sourceSummary): array
    {
        if (blank($sourceSummary) || !is_array($sourceSummary)) {
            return [];
        }

        return array_is_list($sourceSummary) ? $sourceSummary : [$sourceSummary];
    }

    private function productWarnings(TenantCatalogProduct $product): array
    {
        $warnings = collect(array_merge(
            (array) data_get($product->meta, 'warning_snapshot', []),
            (array) data_get($product->meta, 'warnings', [])
        ));

        if (blank($product->display_price) && blank(data_get($product->meta, 'price_snapshot.list_price'))) {
            $warnings->push('Fiyat eksik');
        }

        if (blank($product->image_url)) {
            $warnings->push('Görsel eksik');
        }

        if (blank($product->standard_category_id)) {
            $warnings->push('Kategori eksik');
        }

        if ((float) $product->effective_stock_quantity <= 0) {
            $warnings->push('Stok yok');
        }

        if ((bool) data_get($product->meta, 'net_price_warning', data_get($product->meta, 'price_snapshot.net_price_warning', false))) {
            $warnings->push('Net fiyat uyarısı');
        }

        if ((bool) data_get($product->meta, 'supplier_warning_flag', data_get($product->meta, 'price_snapshot.supplier_warning_flag', false))) {
            $warnings->push('Tedarikçi özel fiyat uyarısı');
        }

        $projectionStatus = data_get($product->meta, 'projection_status', $product->catalog_status);
        if (in_array($projectionStatus, ['missing_from_feed', 'inactive_candidate'], true)) {
            $warnings->push('XML’den çıkan / pasif adayı');
        }

        if ($projectionStatus === 'category_conflict') {
            $warnings->push('Kategori conflict');
        }

        if ($projectionStatus === 'projection_error') {
            $warnings->push('Sync hatası');
        }

        return $warnings->filter()->unique()->values()->all();
    }

    private function productHasWarningType(TenantCatalogProduct $product, string $warningType): bool
    {
        return in_array($warningType, $this->productWarnings($product), true);
    }

    private function warningDescription(string $warning, TenantCatalogProduct $product): string
    {
        return match ($warning) {
            'Fiyat eksik' => 'Ürünün liste fiyatı eksik olduğu için satış öncesi manuel kontrol önerilir.',
            'Görsel eksik' => 'Ürün katalogda görselsiz kalabilir.',
            'Kategori eksik' => 'Standart kategori eşlemesi tamamlanmamış olabilir.',
            'Net fiyat uyarısı' => 'Bu ürün net fiyatlı olabilir; standart iskonto uygulanmadan önce kontrol edilmelidir.',
            'Tedarikçi özel fiyat uyarısı' => 'Tedarikçi bu ürünü özel fiyat/uyarı ile işaretlemiş.',
            'Stok yok' => 'Satışta kullanılacak stok görünmüyor.',
            'XML’den çıkan / pasif adayı' => 'Ürün son tedarikçi beslemesinde görünmediği için kontrol kuyruğunda tutuluyor.',
            'Kategori conflict' => 'Kategori önerisi çakışmalı olduğu için kontrol bekliyor.',
            'Sync hatası' => 'Son senkronizasyonda ürün işlenirken hata oluştu. Rapor kontrol edilmelidir.',
            default => $warning . ' uyarısı bu ürün için aktif.',
        };
    }

    private function isLocalProduct(TenantCatalogProduct $product): bool
    {
        return $product->catalog_source === 'local_product'
            || data_get($product->meta, 'catalog_source') === 'local_product';
    }

    private function productSupplierLabel(TenantCatalogProduct $product): string
    {
        if ($this->isLocalProduct($product)) {
            return 'Local Ürün';
        }

        $supplierNames = collect($product->source_summary ?? [])
            ->pluck('supplier_name')
            ->filter()
            ->unique()
            ->values();

        return $supplierNames->isNotEmpty() ? $supplierNames->implode(', ') : 'Tedarikçi Ürünü';
    }

    private function validateLocalProduct(Request $request, TenantAccount $tenant, ?TenantCatalogProduct $product = null): array
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'product_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenant_catalog_products', 'product_code')
                    ->ignore($product?->id)
                    ->where(fn ($query) => $query->where('tenant_account_id', $tenant->id)),
            ],
            'standard_category_id' => 'nullable|exists:standard_categories,id',
            'image_url' => 'nullable|url',
            'display_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'local_stock_quantity' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'visible_in_catalog' => 'nullable|boolean',
            'visible_in_quote' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'local_stock_priority' => 'nullable|boolean',
        ]);

        if (!empty($validated['standard_category_id'])) {
            $category = StandardCategory::query()->findOrFail((int) $validated['standard_category_id']);
            abort_if(
                $category->isArchivedCategory() || !$category->isPermanentBackbone(),
                422,
                'Arşiv veya kalıcı omurga dışında kalan kategoriler local ürün kategorisi olarak kullanılamaz.'
            );
        }

        return $validated;
    }

    private function buildLocalProductPayload(TenantAccount $tenant, array $validated, ?TenantCatalogProduct $product = null): array
    {
        $localStock = (float) ($validated['local_stock_quantity'] ?? 0);
        $visibleInCatalog = (bool) ($validated['visible_in_catalog'] ?? false);
        $visibleInQuote = (bool) ($validated['visible_in_quote'] ?? false);
        $isActive = (bool) ($validated['is_active'] ?? false);
        $isFeatured = (bool) ($validated['is_featured'] ?? false);
        $localStockPriority = (bool) ($validated['local_stock_priority'] ?? true);

        return [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => $product?->tenant_sku ?: 'LOCAL-' . $tenant->id . '-' . Str::upper($validated['product_code']),
            'name' => $validated['product_name'],
            'description' => $validated['description'] ?? null,
            'product_code' => $validated['product_code'],
            'product_name' => $validated['product_name'],
            'slug' => Str::slug($validated['product_name'] . '-' . $validated['product_code']),
            'standard_category_id' => $validated['standard_category_id'] ?? null,
            'product_family' => 'local',
            'image_url' => $validated['image_url'] ?? null,
            'display_price' => $validated['display_price'] ?? null,
            'sale_price' => $validated['display_price'] ?? null,
            'currency' => $validated['currency'] ?? 'TL',
            'total_stock_quantity' => $localStock,
            'local_stock_quantity' => $localStock,
            'supplier_stock_quantity' => 0,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [],
            'visible_in_catalog' => $visibleInCatalog,
            'visible_in_quote' => $visibleInQuote,
            'hidden_reason' => $visibleInCatalog ? null : 'Tenant tarafından gizlendi.',
            'is_featured' => $isFeatured,
            'local_stock_priority' => $localStockPriority,
            'catalog_source' => 'local_product',
            'catalog_status' => $isActive ? 'local_active' : 'local_inactive',
            'last_synced_at' => null,
            'is_active' => $isActive,
            'stock_quantity' => (int) round($localStock),
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [
                'catalog_images' => filled($validated['image_url'] ?? null) ? [$validated['image_url']] : [],
            ],
            'meta' => [
                'catalog_source' => 'local_product',
                'warning_snapshot' => $this->buildLocalProductWarnings($validated, $localStock),
                'price_snapshot' => [
                    'list_price' => $validated['display_price'] ?? null,
                    'display_price' => $validated['display_price'] ?? null,
                    'currency' => $validated['currency'] ?? 'TL',
                    'vat_rate' => (float) ($validated['vat_rate'] ?? 0),
                ],
                'stock_snapshot' => [
                    'stock_quantity' => $localStock,
                    'local_stock_quantity' => $localStock,
                    'supplier_stock_quantity' => 0,
                ],
                'can_use_in_quotes' => $visibleInQuote,
                'is_local_product' => true,
            ],
        ];
    }

    private function productUsedInSales(TenantCatalogProduct $product): bool
    {
        return OrderItem::query()
            ->where('tenant_catalog_product_id', $product->id)
            ->exists();
    }

    private function syncLocalStockRecord(TenantAccount $tenant, TenantCatalogProduct $product, float $localStock): void
    {
        TenantLocalStock::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $product->id,
                'warehouse_code' => 'LOCAL-MAIN',
                'location_code' => null,
            ],
            [
                'quantity_on_hand' => $localStock,
                'quantity_reserved' => 0,
                'quantity_available' => $localStock,
                'reorder_level' => 0,
                'max_stock' => null,
                'last_counted_at' => now(),
                'notes' => 'Tenant katalog ekranından güncellendi.',
            ]
        );
    }

    private function applyLocalStockEntry(TenantAccount $tenant, TenantCatalogProduct $product, array $validated, ?TenantCatalogProductVariant $variant = null): TenantSupplierPurchaseEntry
    {
        $quantity = (float) $validated['quantity'];
        $priceMeta = $variant ? (array) data_get($variant->meta, 'price_snapshot', []) : (array) data_get($product->meta, 'price_snapshot', []);
        $listPrice = (float) ($validated['list_price'] ?? data_get($priceMeta, 'list_price', $variant?->display_price ?? $product->display_price ?? 0));
        $discountRate = (float) ($validated['discount_rate'] ?? data_get($priceMeta, 'discount_rate', 0));
        $calculatedUnitPrice = round($listPrice * (1 - ($discountRate / 100)), 4);
        $manualPurchaseUnitPrice = (bool) ($validated['manual_purchase_unit_price'] ?? false);
        $unitPrice = $manualPurchaseUnitPrice
            ? (float) ($validated['unit_purchase_price'] ?? $calculatedUnitPrice)
            : (float) ($validated['calculated_purchase_unit_price'] ?? $calculatedUnitPrice);
        $vatEnabled = (bool) ($validated['vat_enabled'] ?? false);
        $vatRate = $vatEnabled ? (float) ($validated['vat_rate'] ?? data_get($priceMeta, 'vat_rate', data_get($product->meta, 'price_snapshot.vat_rate', 20))) : 0;
        $baseTotal = $validated['entry_type'] === 'supplier_purchase' ? $quantity * $unitPrice : 0;
        $payableAmount = $validated['entry_type'] === 'supplier_purchase'
            ? $baseTotal + ($baseTotal * $vatRate / 100)
            : 0;
        $warehouseCode = $validated['warehouse_code'] ?? 'LOCAL-MAIN';
        $locationCode = $validated['location_code'] ?? null;
        $sourceSummary = $variant?->source_summary ?: $product->source_summary;
        $supplierId = collect($sourceSummary ?? [])->pluck('supplier_id')->filter()->first();
        $supplierSourceId = collect($sourceSummary ?? [])->pluck('supplier_source_id')->filter()->first();
        $supplierName = $this->productSupplierLabel($product);
        $entryProductCode = $variant?->variant_code ?: $product->display_code;
        $entryProductName = $variant?->display_name ?: $product->display_name;

        $entry = TenantSupplierPurchaseEntry::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplierId,
            'supplier_source_id' => $supplierSourceId,
            'tenant_catalog_product_id' => $product->id,
            'supplier_name' => $supplierName,
            'product_code' => $entryProductCode,
            'product_name' => $entryProductName,
            'quantity' => $quantity,
            'list_price' => $listPrice,
            'discount_rate' => $discountRate,
            'calculated_purchase_unit_price' => $calculatedUnitPrice,
            'unit_purchase_price' => $unitPrice > 0 ? $unitPrice : null,
            'manual_purchase_unit_price' => $manualPurchaseUnitPrice,
            'currency' => $validated['currency'] ?? $product->currency ?? 'TL',
            'vat_enabled' => $vatEnabled,
            'vat_rate' => $vatRate,
            'total_amount' => $baseTotal,
            'payable_amount' => $payableAmount,
            'entry_type' => $validated['entry_type'],
            'payable_status' => $validated['entry_type'] === 'supplier_purchase' ? 'open' : 'none',
            'document_no' => $validated['document_no'] ?? null,
            'entry_date' => $validated['entry_date'] ?? now()->toDateString(),
            'warehouse_code' => $warehouseCode,
            'location_code' => $locationCode,
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $currentLocalStock = (float) ($product->local_stock_quantity ?? 0);
        $newLocalStock = $currentLocalStock + $quantity;
        $supplierStock = (float) ($product->supplier_stock_quantity ?? 0);
        $isLocalProduct = $this->isLocalProduct($product);

        if ($variant) {
            $variantLocalStock = (float) ($variant->local_stock_quantity ?? 0) + $quantity;
            $variantSupplierStock = (float) ($variant->supplier_stock_quantity ?? 0);
            $variant->update([
                'local_stock_quantity' => $variantLocalStock,
                'stock_quantity' => $variantLocalStock + $variantSupplierStock,
                'meta' => array_merge((array) ($variant->meta ?? []), [
                    'stock_snapshot' => array_merge((array) data_get($variant->meta, 'stock_snapshot', []), [
                        'stock_quantity' => $variantLocalStock + $variantSupplierStock,
                        'local_stock_quantity' => $variantLocalStock,
                        'supplier_stock_quantity' => $variantSupplierStock,
                    ]),
                ]),
            ]);
        }

        $product->update([
            'local_stock_quantity' => $newLocalStock,
            'total_stock_quantity' => $isLocalProduct ? $newLocalStock : $newLocalStock + $supplierStock,
            'stock_quantity' => (int) round($isLocalProduct ? $newLocalStock : $newLocalStock + $supplierStock),
            'local_stock_priority' => true,
            'meta' => array_merge((array) ($product->meta ?? []), [
                'stock_snapshot' => array_merge((array) data_get($product->meta, 'stock_snapshot', []), [
                    'stock_quantity' => $isLocalProduct ? $newLocalStock : $newLocalStock + $supplierStock,
                    'local_stock_quantity' => $newLocalStock,
                    'supplier_stock_quantity' => $supplierStock,
                ]),
            ]),
        ]);

        $this->syncLocalStockRecord($tenant, $product, $newLocalStock);
        StockMovement::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'movement_type' => 'in',
            'reason' => 'purchase',
            'quantity' => $quantity,
            'unit_cost' => $unitPrice > 0 ? $unitPrice : null,
            'currency' => $validated['currency'] ?? $product->currency ?? 'TL',
            'warehouse_code' => $warehouseCode,
            'location_code' => $locationCode,
            'reference_document' => $validated['document_no'] ?? null,
            'notes' => $validated['entry_type'] === 'supplier_purchase' ? 'Tedarikçiden satın alma local stok girişi.' : 'Eldeki mevcut stok girişi.',
            'created_by' => auth()->id(),
        ]);

        return $entry;
    }

    private function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($value) => Str::of((string) $value)->trim()->lower()->snake()->toString(), $data);
                continue;
            }

            if (count(array_filter($data, fn ($value) => filled($value))) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($data, count($headers), null));
        }

        fclose($handle);

        return $rows;
    }

    private function validateImportRows(array $rows): array
    {
        $errors = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $code = trim((string) ($row['urun_kodu'] ?? $row['product_code'] ?? ''));
            $name = trim((string) ($row['urun_adi'] ?? $row['product_name'] ?? ''));

            if ($code === '') {
                $errors[] = "{$line}. satır: ürün kodu boş.";
            }

            if ($name === '') {
                $errors[] = "{$line}. satır: ürün adı boş.";
            }

            if ($code !== '' && in_array($code, $seenCodes, true)) {
                $errors[] = "{$line}. satır: duplicate ürün kodu ({$code}).";
            }

            if ($code !== '') {
                $seenCodes[] = $code;
            }

            foreach (['stok', 'liste_fiyati'] as $numericField) {
                $value = $row[$numericField] ?? null;
                if (filled($value) && !is_numeric(str_replace(',', '.', (string) $value))) {
                    $errors[] = "{$line}. satır: {$numericField} sayısal değil.";
                }
            }
        }

        return $errors;
    }

    private function normalizeImportRow(array $row): array
    {
        $bool = fn ($value, bool $default = false) => filled($value)
            ? in_array(Str::lower((string) $value), ['1', 'true', 'evet', 'var', 'yes'], true)
            : $default;
        $decimal = fn ($value) => filled($value) ? (float) str_replace(',', '.', (string) $value) : null;

        return [
            'product_code' => trim((string) ($row['urun_kodu'] ?? $row['product_code'] ?? '')),
            'product_name' => trim((string) ($row['urun_adi'] ?? $row['product_name'] ?? '')),
            'standard_category_id' => null,
            'image_url' => $row['gorsel_url'] ?? $row['image_url'] ?? null,
            'display_price' => $decimal($row['liste_fiyati'] ?? $row['display_price'] ?? null),
            'currency' => $row['para_birimi'] ?? $row['currency'] ?? 'TL',
            'vat_rate' => $bool($row['kdv_var'] ?? null) ? 20 : 0,
            'local_stock_quantity' => $decimal($row['stok'] ?? $row['stock'] ?? 0) ?? 0,
            'description' => $row['aciklama'] ?? $row['description'] ?? null,
            'visible_in_catalog' => $bool($row['katalogda_gorunsun'] ?? null, true),
            'visible_in_quote' => $bool($row['teklifte_kullanilsin'] ?? null, true),
            'is_active' => true,
            'is_featured' => false,
            'local_stock_priority' => true,
        ];
    }

    private function currentTenant(): ?TenantAccount
    {
        return request()->attributes->get('current_tenant')
            ?? auth()->user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->orderBy('id')->first();
    }

    private function ensureTenantProduct(?TenantAccount $tenant, TenantCatalogProduct $product): void
    {
        if (!$tenant || $product->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu katalog ürününe erişim izniniz yok.');
        }
    }

    private function resolveSellableVariantForLocalStock(TenantCatalogProduct $product, mixed $variantId): ?TenantCatalogProductVariant
    {
        if (blank($variantId)) {
            return null;
        }

        return TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $product->tenant_account_id)
            ->where('tenant_catalog_product_id', $product->id)
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->findOrFail((int) $variantId);
    }

    private function ensureSellableForLocalStock(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant = null): void
    {
        if ($variant && (bool) data_get($variant->meta, 'is_sellable', true)) {
            return;
        }

        if ($product->is_parent_group || !$product->is_sellable) {
            abort(422, 'Grup ürünler local stoğa alınamaz. Lütfen satılabilir varyant ürünü seçin.');
        }
    }
}
