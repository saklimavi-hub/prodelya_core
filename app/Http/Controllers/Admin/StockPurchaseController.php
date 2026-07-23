<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CurrentAccountTransaction;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\TenantSupplierPurchaseEntry;
use App\Services\TenantCatalog\CatalogFastStockActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockPurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $filters = [
            'entry_type' => (string) $request->query('entry_type', ''),
            'supplier' => (int) $request->integer('supplier'),
            'search' => trim((string) $request->query('search', '')),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $entries = TenantSupplierPurchaseEntry::query()
            ->with(['catalogProduct', 'catalogVariant', 'supplier'])
            ->where('tenant_account_id', $tenant->id)
            ->when($filters['entry_type'] !== '', fn ($query) => $query->where('entry_type', $filters['entry_type']))
            ->when($filters['supplier'] > 0, fn ($query) => $query->where('supplier_id', $filters['supplier']))
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $query->where(function ($nested) use ($filters) {
                    $nested
                        ->where('product_code', 'like', '%' . $filters['search'] . '%')
                        ->orWhere('product_name', 'like', '%' . $filters['search'] . '%')
                        ->orWhere('supplier_name', 'like', '%' . $filters['search'] . '%')
                        ->orWhere('document_no', 'like', '%' . $filters['search'] . '%');
                });
            })
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('entry_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('entry_date', '<=', $filters['date_to']))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $suppliers = $this->visibleSuppliers($tenant);

        $summary = [
            'total_entries' => TenantSupplierPurchaseEntry::query()->where('tenant_account_id', $tenant->id)->count(),
            'purchase_entries' => TenantSupplierPurchaseEntry::query()->where('tenant_account_id', $tenant->id)->where('entry_type', CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE)->count(),
            'opening_entries' => TenantSupplierPurchaseEntry::query()->where('tenant_account_id', $tenant->id)->where('entry_type', CatalogFastStockActionService::ENTRY_TYPE_OPENING_STOCK)->count(),
            'cancelled_entries' => TenantSupplierPurchaseEntry::query()->where('tenant_account_id', $tenant->id)->where('entry_status', 'cancelled')->count(),
        ];

        return view('admin.stock-purchases.index', compact('entries', 'filters', 'suppliers', 'summary'));
    }

    public function create(Request $request): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $entryType = old('entry_type', CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE);
        $supplierFilter = (int) old('supplier_id', $request->integer('supplier_id'));
        $accesses = $this->activeSupplierAccesses($tenant);
        $preselected = $this->resolvePreselectedCandidate($tenant, $request, $accesses)
            ?? $this->resolveDirectRequestedCandidate($tenant, $request, $accesses);

        $rows = old('rows');
        if (!is_array($rows) || $rows === []) {
            $rows = [$this->defaultRow($preselected)];
        }

        $selectedKeys = collect($rows)
            ->pluck('selection_key')
            ->filter(fn ($key) => filled($key))
            ->values()
            ->all();

        $selectedCandidates = $this->resolveSelectedCandidateMap($tenant, $selectedKeys, $accesses);

        if ($preselected) {
            $selectedCandidates->put($preselected['selection_key'], $preselected);
        }

        $rows = collect($rows)
            ->map(fn ($row) => $this->normalizeRow($row, $selectedCandidates))
            ->values()
            ->all();

        return view('admin.stock-purchases.create', [
            'entryType' => $entryType,
            'rows' => $rows,
            'initialCandidates' => $selectedCandidates->all(),
            'suppliers' => $this->visibleSuppliers($tenant),
            'supplierId' => $supplierFilter > 0 ? $supplierFilter : ($preselected['supplier_id'] ?? null),
            'entryDate' => old('entry_date', now()->toDateString()),
            'documentNo' => old('document_no', ''),
            'pageNote' => old('page_note', ''),
            'searchEndpoint' => route('admin.stock-purchases.search'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();
        if (!$tenant) {
            return response()->json([]);
        }

        $queryText = trim((string) $request->query('q', ''));
        if (mb_strlen($queryText) < 2) {
            return response()->json([]);
        }

        $entryType = (string) $request->query('entry_type', CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE);
        $supplierFilter = (int) $request->integer('supplier_id');
        $accesses = $this->activeSupplierAccesses($tenant);

        $catalogRequest = Request::create('/admin/catalog/search', 'GET', array_merge($request->query(), [
            'q' => $queryText,
            'only_quote_visible' => 0,
        ]));
        $catalogRequest->setUserResolver(fn () => $request->user());
        $catalogRequest->attributes->set('current_tenant', $tenant);

        $catalogResults = app(CatalogSearchController::class)->search($catalogRequest)->getData(true);

        $results = collect($catalogResults)
            ->map(function (array $result) use ($tenant, $accesses) {
                $selectionKey = !empty($result['tenant_catalog_product_variant_id'])
                    ? 'variant:' . (int) $result['tenant_catalog_product_variant_id']
                    : 'product:' . (int) ($result['tenant_catalog_product_id'] ?? 0);

                return $this->findCandidateBySelectionKey($tenant, $selectionKey, $accesses);
            })
            ->filter()
            ->filter(function (array $candidate) use ($entryType, $supplierFilter) {
                if ($supplierFilter > 0 && (int) ($candidate['supplier_id'] ?? 0) !== $supplierFilter) {
                    return false;
                }

                if ($entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE) {
                    return (int) ($candidate['supplier_id'] ?? 0) > 0 && (bool) ($candidate['can_purchase'] ?? false);
                }

                return true;
            })
            ->unique('selection_key')
            ->values()
            ->map(fn (array $candidate) => $this->buildSearchResultPayload($candidate))
            ->all();

        return response()->json($results);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);

        $validator = Validator::make($request->all(), [
            'entry_type' => 'required|string|in:supplier_purchase,existing_stock',
            'supplier_id' => 'nullable|integer',
            'entry_date' => 'required|date',
            'document_no' => 'nullable|string|max:100',
            'page_note' => 'nullable|string|max:1000',
            'rows' => 'required|array|min:1',
            'rows.*.include' => 'nullable',
            'rows.*.selection_key' => 'nullable|string|max:40',
            'rows.*.quantity' => 'nullable|numeric|min:0.0001',
            'rows.*.list_price' => 'nullable|numeric|min:0',
            'rows.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'rows.*.unit_purchase_price' => 'nullable|numeric|min:0',
            'rows.*.currency' => 'nullable|string|max:3',
            'rows.*.exchange_rate' => 'nullable|numeric|min:0.000001',
            'rows.*.exchange_rate_date' => 'nullable|date',
            'rows.*.line_note' => 'nullable|string|max:500',
        ]);

        $validated = $validator->validate();
        $entryType = (string) $validated['entry_type'];
        $supplierId = (int) ($validated['supplier_id'] ?? 0);
        $candidateMap = $this->candidateMap($tenant, $supplierId > 0 && $entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE ? $supplierId : null);

        $rows = collect($validated['rows'])
            ->filter(function (array $row): bool {
                $include = !array_key_exists('include', $row) || filter_var($row['include'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;

                return $include && filled($row['selection_key'] ?? null) && filled($row['quantity'] ?? null);
            })
            ->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'En az bir ürün satırı seçin.',
            ]);
        }

        $entries = [];

        foreach ($rows as $index => $row) {
            $candidate = $candidateMap->get((string) $row['selection_key']);
            $lineNumber = $index + 1;

            if (!$candidate) {
                throw ValidationException::withMessages([
                    "rows.$index.selection_key" => "Satır {$lineNumber} için geçerli bir exact ürün seçin.",
                ]);
            }

            if ($entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE) {
                if ((int) ($candidate['supplier_id'] ?? 0) <= 0) {
                    throw ValidationException::withMessages([
                        "rows.$index.selection_key" => "Satır {$lineNumber} için satın alma yalnız tedarikçi ürününde kullanılabilir.",
                    ]);
                }

                if (!$candidate['can_purchase']) {
                    throw ValidationException::withMessages([
                        "rows.$index.selection_key" => "Satır {$lineNumber} için bu tedarikçide satın alma izniniz yok.",
                    ]);
                }

                if ($supplierId > 0 && (int) $candidate['supplier_id'] !== $supplierId) {
                    throw ValidationException::withMessages([
                        "rows.$index.selection_key" => "Satır {$lineNumber} seçilen tedarikçiye ait değil.",
                    ]);
                }
            }

            $product = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->findOrFail((int) $candidate['product_id']);

            $variant = !empty($candidate['variant_id'])
                ? TenantCatalogProductVariant::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('tenant_catalog_product_id', $product->id)
                    ->findOrFail((int) $candidate['variant_id'])
                : null;

            $quantity = round((float) $row['quantity'], 4);
            $listPrice = round((float) ($row['list_price'] ?? $candidate['list_price']), 4);
            $discountRate = $entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE
                ? round((float) ($row['discount_rate'] ?? 0), 4)
                : 0.0;
            $calculatedUnitPrice = round($listPrice * (1 - ($discountRate / 100)), 4);
            $enteredUnitPrice = round((float) ($row['unit_purchase_price'] ?? $calculatedUnitPrice), 4);
            $manualOverride = $entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE
                && abs($enteredUnitPrice - $calculatedUnitPrice) > 0.00005;
            $currency = $this->normalizeCurrency((string) ($row['currency'] ?? $candidate['currency']));
            $exchangeRate = $currency === 'TRY'
                ? 1.0
                : round((float) ($row['exchange_rate'] ?? $candidate['exchange_rate'] ?? 0), 6);

            if ($currency !== 'TRY' && $exchangeRate <= 0) {
                throw ValidationException::withMessages([
                    "rows.$index.exchange_rate" => "Satır {$lineNumber} için kur 0'dan büyük olmalıdır.",
                ]);
            }

            $payload = [
                'entry_type' => $entryType,
                'tenant_catalog_product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'list_price' => $listPrice,
                'discount_rate' => $discountRate,
                'calculated_purchase_unit_price' => $calculatedUnitPrice,
                'unit_purchase_price' => $enteredUnitPrice,
                'manual_purchase_unit_price' => $manualOverride,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'exchange_rate_date' => $row['exchange_rate_date'] ?? $candidate['exchange_rate_date'] ?? now()->toDateString(),
                'document_no' => $validated['document_no'] ?? null,
                'entry_date' => $validated['entry_date'],
                'warehouse_code' => 'LOCAL-MAIN',
                'location_code' => null,
                'notes' => $row['line_note'] ?? ($validated['page_note'] ?? null),
                'idempotency_key' => sha1(implode('|', [
                    $entryType,
                    $product->id,
                    $variant?->id ?? 'flat',
                    $quantity,
                    $validated['entry_date'],
                    $validated['document_no'] ?? '',
                    $index,
                ])),
            ];

            $entries[] = app(CatalogFastStockActionService::class)->store(
                $tenant,
                $product,
                $variant,
                $payload,
                $request->user()
            );
        }

        if (count($entries) === 1) {
            return redirect()
                ->route('admin.stock-purchases.show', $entries[0])
                ->with('success', $entryType === CatalogFastStockActionService::ENTRY_TYPE_COMPLETED_PURCHASE
                    ? 'Satın alma kaydı oluşturuldu; stok ve tedarikçi cari borcu işlendi.'
                    : 'Eldeki mevcut stok exact local stoğa eklendi.');
        }

        return redirect()
            ->route('admin.stock-purchases.index')
            ->with('success', count($entries) . ' stok satırı kaydedildi.');
    }

    public function show(TenantSupplierPurchaseEntry $entry): View
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);
        $this->ensureTenantEntry($tenant, $entry);

        $entry->load(['catalogProduct', 'catalogVariant', 'supplier']);

        $movement = StockMovement::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('reference_type', TenantSupplierPurchaseEntry::class)
            ->where('reference_id', $entry->id)
            ->orderBy('id')
            ->get();

        $debit = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('source_id', $entry->id)
            ->whereIn('source_type', [
                CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
                CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_REVERSAL,
            ])
            ->orderBy('id')
            ->get();

        return view('admin.stock-purchases.show', compact('entry', 'movement', 'debit'));
    }

    public function cancel(Request $request, TenantSupplierPurchaseEntry $entry): RedirectResponse
    {
        $tenant = $this->currentTenant();
        abort_if(!$tenant, 404);
        $this->ensureTenantEntry($tenant, $entry);

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ]);

        app(CatalogFastStockActionService::class)->cancel(
            $tenant,
            $entry,
            $validated['cancellation_reason'],
            $request->user()
        );

        return redirect()
            ->route('admin.stock-purchases.show', $entry)
            ->with('success', 'Kayıt iptal edildi ve ters hareketler oluşturuldu.');
    }

    private function candidateMap(TenantAccount $tenant, ?int $supplierId = null): Collection
    {
        $accesses = $this->activeSupplierAccesses($tenant);

        return TenantCatalogProduct::query()
            ->with(['variants'])
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->orderBy('product_name')
            ->get()
            ->flatMap(function (TenantCatalogProduct $product) use ($accesses, $supplierId) {
                $variants = $product->variants
                    ->filter(fn (TenantCatalogProductVariant $variant) => $variant->is_active && $variant->visible_in_catalog && (bool) data_get($variant->meta, 'is_sellable', true));

                if ($variants->isNotEmpty()) {
                    return $variants->map(fn (TenantCatalogProductVariant $variant) => $this->candidateFromVariant($product, $variant, $accesses, $supplierId))->filter();
                }

                return collect([$this->candidateFromProduct($product, $accesses, $supplierId)])->filter();
            })
            ->keyBy('selection_key');
    }

    private function activeSupplierAccesses(TenantAccount $tenant): Collection
    {
        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('supplier_id');
    }

    private function visibleSuppliers(TenantAccount $tenant)
    {
        $visibleSupplierIds = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->pluck('supplier_id');

        return Supplier::query()
            ->select('id', 'name')
            ->whereIn('id', $visibleSupplierIds)
            ->orderBy('name')
            ->get();
    }

    private function candidateFromProduct(TenantCatalogProduct $product, Collection $accesses, ?int $supplierFilter = null): ?array
    {
        if ($product->is_parent_group || !$product->is_sellable) {
            return null;
        }

        $summary = collect($product->source_summary ?? [])->firstWhere(fn ($row) => filled($row['supplier_id'] ?? null)) ?? [];
        $supplierId = (int) ($summary['supplier_id'] ?? 0);
        $access = $supplierId > 0 ? $accesses->get($supplierId) : null;

        if ($supplierId > 0) {
            if (!$access || !$access->can_view_products) {
                return null;
            }

            if ($supplierFilter && $supplierId !== $supplierFilter) {
                return null;
            }
        } elseif ($supplierFilter) {
            return null;
        }

        $currency = $this->normalizeCurrency((string) ($product->currency ?? 'TRY'));

        return [
            'selection_key' => 'product:' . $product->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'product_code' => $product->display_code,
            'product_name' => $product->display_name,
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $summary['supplier_name'] ?? ($supplierId > 0 ? 'Tedarikçi' : 'Kendi ürününüz'),
            'local_stock_quantity' => round((float) ($product->local_stock_quantity ?? 0), 4),
            'supplier_stock_quantity' => round((float) ($product->supplier_stock_quantity ?? 0), 4),
            'list_price' => round((float) ($product->display_price ?? data_get($product->meta, 'price_snapshot.list_price', 0)), 4),
            'currency' => $currency,
            'exchange_rate' => $currency === 'TRY' ? 1.0 : round((float) data_get($product->meta, 'price_snapshot.exchange_rate', 0), 6),
            'exchange_rate_date' => data_get($product->meta, 'price_snapshot.exchange_rate_date'),
            'can_purchase' => $supplierId > 0 && (bool) ($access?->can_request_purchase ?? false),
        ];
    }

    private function candidateFromVariant(TenantCatalogProduct $product, TenantCatalogProductVariant $variant, Collection $accesses, ?int $supplierFilter = null): ?array
    {
        $summary = (array) ($variant->source_summary ?? []);
        $supplierId = (int) ($summary['supplier_id'] ?? 0);
        $access = $supplierId > 0 ? $accesses->get($supplierId) : null;

        if ($supplierId > 0) {
            if (!$access || !$access->can_view_products) {
                return null;
            }

            if ($supplierFilter && $supplierId !== $supplierFilter) {
                return null;
            }
        } elseif ($supplierFilter) {
            return null;
        }

        $currency = $this->normalizeCurrency((string) ($variant->currency ?? $product->currency ?? 'TRY'));

        return [
            'selection_key' => 'variant:' . $variant->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'product_code' => $variant->variant_code ?: $product->display_code,
            'product_name' => $variant->display_name,
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $summary['supplier_name'] ?? 'Tedarikçi',
            'local_stock_quantity' => round((float) ($variant->local_stock_quantity ?? 0), 4),
            'supplier_stock_quantity' => round((float) ($variant->supplier_stock_quantity ?? 0), 4),
            'list_price' => round((float) ($variant->display_price ?? data_get($variant->meta, 'price_snapshot.list_price', 0)), 4),
            'currency' => $currency,
            'exchange_rate' => $currency === 'TRY' ? 1.0 : round((float) data_get($variant->meta, 'price_snapshot.exchange_rate', data_get($product->meta, 'price_snapshot.exchange_rate', 0)), 6),
            'exchange_rate_date' => data_get($variant->meta, 'price_snapshot.exchange_rate_date', data_get($product->meta, 'price_snapshot.exchange_rate_date')),
            'can_purchase' => $supplierId > 0 && (bool) ($access?->can_request_purchase ?? false),
        ];
    }

    private function resolvePreselectedCandidate(TenantAccount $tenant, Request $request, Collection $accesses): ?array
    {
        if ($request->filled('variant')) {
            return $this->findCandidateBySelectionKey($tenant, 'variant:' . (int) $request->query('variant'), $accesses);
        }

        if ($request->filled('product')) {
            return $this->findCandidateBySelectionKey($tenant, 'product:' . (int) $request->query('product'), $accesses);
        }

        return null;
    }

    private function resolveDirectRequestedCandidate(TenantAccount $tenant, Request $request, Collection $accesses): ?array
    {
        if ($request->filled('variant')) {
            $variant = TenantCatalogProductVariant::query()
                ->where('tenant_account_id', $tenant->id)
                ->with('catalogProduct')
                ->find((int) $request->query('variant'));

            if ($variant && $variant->catalogProduct) {
                return $this->candidateFromVariant($variant->catalogProduct, $variant, $accesses);
            }
        }

        if ($request->filled('product')) {
            $product = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->find((int) $request->query('product'));

            if ($product) {
                return $this->candidateFromProduct($product, $accesses);
            }
        }

        return null;
    }
    private function resolveSelectedCandidateMap(TenantAccount $tenant, array $selectionKeys, Collection $accesses): Collection
    {
        return collect($selectionKeys)
            ->filter(fn ($key) => filled($key))
            ->unique()
            ->mapWithKeys(function ($selectionKey) use ($tenant, $accesses) {
                $candidate = $this->findCandidateBySelectionKey($tenant, (string) $selectionKey, $accesses);

                return $candidate ? [$candidate['selection_key'] => $candidate] : [];
            });
    }

    private function findCandidateBySelectionKey(TenantAccount $tenant, string $selectionKey, Collection $accesses): ?array
    {
        [$type, $id] = array_pad(explode(':', $selectionKey, 2), 2, null);
        $id = (int) $id;

        if ($type === 'variant' && $id > 0) {
            $variant = TenantCatalogProductVariant::query()
                ->where('tenant_account_id', $tenant->id)
                ->with('catalogProduct')
                ->find($id);

            if (!$variant || !$variant->catalogProduct) {
                return null;
            }

            return $this->candidateFromVariant($variant->catalogProduct, $variant, $accesses);
        }

        if ($type === 'product' && $id > 0) {
            $product = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenant->id)
                ->find($id);

            if (!$product) {
                return null;
            }

            return $this->candidateFromProduct($product, $accesses);
        }

        return null;
    }

    private function buildSearchResultPayload(array $candidate): array
    {
        return array_merge($candidate, [
            'search_label' => trim($candidate['product_code'] . ' ' . $candidate['product_name']),
            'meta_primary' => collect([
                'SKU: ' . $candidate['product_code'],
                $candidate['supplier_name'],
            ])->filter()->implode(' · '),
            'meta_secondary' => collect([
                'Local stok: ' . $this->formatStockForDisplay((float) ($candidate['local_stock_quantity'] ?? 0)),
                'Tedarikçi stok: ' . $this->formatStockForDisplay((float) ($candidate['supplier_stock_quantity'] ?? 0)),
                'Liste: ' . $this->formatMoneyForDisplay((float) ($candidate['list_price'] ?? 0)) . ' ' . ($candidate['currency'] === 'TRY' ? 'TL' : $candidate['currency']),
            ])->implode(' · '),
        ]);
    }

    private function defaultRow(?array $candidate = null): array
    {
        return [
            'include' => true,
            'selection_key' => $candidate['selection_key'] ?? '',
            'search_text' => $candidate ? $candidate['product_code'] . ' - ' . $candidate['product_name'] : '',
            'product_name' => $candidate['product_name'] ?? '',
            'product_code' => $candidate['product_code'] ?? '',
            'supplier_name' => $candidate['supplier_name'] ?? '',
            'local_stock_quantity' => $candidate['local_stock_quantity'] ?? 0,
            'supplier_stock_quantity' => $candidate['supplier_stock_quantity'] ?? 0,
            'quantity' => 1,
            'list_price' => $candidate['list_price'] ?? 0,
            'discount_rate' => 0,
            'unit_purchase_price' => $candidate['list_price'] ?? 0,
            'currency' => $candidate['currency'] ?? 'TRY',
            'exchange_rate' => $candidate['exchange_rate'] ?? 1,
            'exchange_rate_date' => $candidate['exchange_rate_date'] ?? now()->toDateString(),
            'line_note' => '',
        ];
    }

    private function normalizeRow(array $row, Collection $candidateMap): array
    {
        $candidate = $candidateMap->get((string) ($row['selection_key'] ?? ''));

        return [
            'include' => $row['include'] ?? true,
            'selection_key' => (string) ($row['selection_key'] ?? ''),
            'search_text' => (string) ($row['search_text'] ?? ($candidate ? $candidate['product_code'] . ' - ' . $candidate['product_name'] : '')),
            'product_name' => (string) ($row['product_name'] ?? ($candidate['product_name'] ?? '')),
            'product_code' => (string) ($row['product_code'] ?? ($candidate['product_code'] ?? '')),
            'supplier_name' => (string) ($row['supplier_name'] ?? ($candidate['supplier_name'] ?? '')),
            'local_stock_quantity' => (float) ($row['local_stock_quantity'] ?? ($candidate['local_stock_quantity'] ?? 0)),
            'supplier_stock_quantity' => (float) ($row['supplier_stock_quantity'] ?? ($candidate['supplier_stock_quantity'] ?? 0)),
            'quantity' => (float) ($row['quantity'] ?? 1),
            'list_price' => (float) ($row['list_price'] ?? ($candidate['list_price'] ?? 0)),
            'discount_rate' => (float) ($row['discount_rate'] ?? 0),
            'unit_purchase_price' => (float) ($row['unit_purchase_price'] ?? ($candidate['list_price'] ?? 0)),
            'currency' => (string) ($row['currency'] ?? ($candidate['currency'] ?? 'TRY')),
            'exchange_rate' => (float) ($row['exchange_rate'] ?? ($candidate['exchange_rate'] ?? 1)),
            'exchange_rate_date' => (string) ($row['exchange_rate_date'] ?? ($candidate['exchange_rate_date'] ?? now()->toDateString())),
            'line_note' => (string) ($row['line_note'] ?? ''),
        ];
    }

    private function formatStockForDisplay(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function formatMoneyForDisplay(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function normalizeCurrency(string $currency): string
    {
        return match (strtoupper(trim($currency))) {
            'TL', 'TRY' => 'TRY',
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => 'TRY',
        };
    }

    private function ensureTenantEntry(TenantAccount $tenant, TenantSupplierPurchaseEntry $entry): void
    {
        abort_unless((int) $entry->tenant_account_id === (int) $tenant->id, 403);
    }

    private function currentTenant(): ?TenantAccount
    {
        return request()->attributes->get('current_tenant')
            ?? auth()->user()?->tenantAccount
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->orderBy('id')->first();
    }
}
