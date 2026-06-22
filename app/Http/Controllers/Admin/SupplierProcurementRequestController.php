<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Services\SupplierProcurementRequestDataBuilder;
use App\Services\SupplierProcurementRequestService;
use App\Services\TenantResolver;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class SupplierProcurementRequestController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected SupplierProcurementRequestDataBuilder $dataBuilder,
        protected SupplierProcurementRequestService $requestService
    ) {
    }

    public function create(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($tenant, 403);
        $this->ensureCanManage($request, $tenant->id);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
        ]);

        $supplier = $this->resolveTenantSupplier($tenant->id, (int) $validated['supplier_id']);
        $candidates = $this->dataBuilder->getCandidateProcurementsForSupplier($tenant, $supplier->id);

        return view('admin.procurements.supplier-requests.create', [
            'tenant' => $tenant,
            'supplier' => $supplier,
            'candidates' => $candidates,
            'candidateCount' => $candidates->count(),
            'totalMissingQuantity' => round($candidates->sum(fn ($row) => (float) $row->remaining_quantity), 2),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($tenant, 403);
        $this->ensureCanManage($request, $tenant->id);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'procurement_ids' => ['required', 'array', 'min:1'],
            'procurement_ids.*' => ['integer'],
        ]);

        $supplier = $this->resolveTenantSupplier($tenant->id, (int) $validated['supplier_id']);
        try {
            $requestRecord = $this->requestService->createDraftForSupplier(
                $tenant,
                $supplier->id,
                $validated['procurement_ids'],
                $request->user()
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'procurement_ids' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $requestRecord)
            ->with('success', 'Tedarikçi talep taslağı oluşturuldu.');
    }

    public function edit(Request $request, SupplierProcurementRequest $supplierRequest): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $supplierRequest->loadMissing([
            'supplier',
            'items.order',
            'items.workForm',
            'items.procurement',
        ]);

        $editData = $this->dataBuilder->buildRequestEditData($supplierRequest);

        $canViewPurchasePrices = $request->user()?->hasPermissionInTenant('view_procurement_purchase_prices', $tenant->id) ?? false;

        return view('admin.procurements.supplier-requests.edit', [
            'requestRecord' => $supplierRequest,
            'editData' => $editData,
            'canViewPurchasePrices' => $canViewPurchasePrices,
            'canManageProcurementRequests' => $request->user()?->hasPermissionInTenant('manage_procurement_requests', $tenant->id) ?? false,
            'canViewSalesReference' => $canViewPurchasePrices || ($request->user()?->hasAnyPermissionInTenant([
                'view_order_finance_summary',
                'view_sales_prices',
            ], $tenant->id) ?? false),
        ]);
    }

    public function update(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $request->merge([
            'items' => $this->normalizeRequestItemDecimals((array) $request->input('items', []), [
                'requested_quantity',
                'purchase_list_price',
                'discount_rate',
                'purchase_unit_price',
            ]),
        ]);

        $canViewPurchasePrices = $request->user()?->hasPermissionInTenant('view_procurement_purchase_prices', $tenant->id) ?? false;

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'submit_action' => ['nullable', 'in:draft,request'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.included' => ['nullable', 'boolean'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.purchase_list_price' => [$canViewPurchasePrices ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'items.*.discount_rate' => [$canViewPurchasePrices ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'items.*.purchase_unit_price' => [$canViewPurchasePrices ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $supplierRequest->forceFill([
            'note' => $validated['note'] ?? $supplierRequest->note,
            'updated_by' => $request->user()?->id,
        ])->save();

        try {
            $updatedRequest = $this->requestService->updateRequestItems(
                $supplierRequest,
                $validated['items'] ?? [],
                $request->user()
            );

            $submitAction = $validated['submit_action'] ?? 'request';

            if ($submitAction === 'request' && $updatedRequest->isDraft()) {
                $updatedRequest = $this->requestService->markRequested($updatedRequest, $request->user());
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'items' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', ($validated['submit_action'] ?? 'request') === 'draft'
                ? 'Tedarikçi talep taslağı kaydedildi.'
                : 'Tedarikçi talebi kaydedildi ve talep açıldı.');
    }

    public function markRequested(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $this->requestService->markRequested($supplierRequest, $request->user());

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', 'Talep tedarikçiye iletildi olarak işaretlendi.');
    }

    public function markSupplierOrdered(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $this->requestService->markSupplierOrdered($supplierRequest, $request->user());

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', 'Tedarikçi siparişi verildi olarak işaretlendi.');
    }

    public function markPartiallyReceived(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $validated = $request->validate([
            'received_items' => ['required', 'array'],
            'received_items.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->requestService->markPartiallyReceived(
                $supplierRequest,
                $validated['received_items'] ?? [],
                $request->user()
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'received_items' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', 'Kısmi gelen miktarlar işlendi.');
    }

    public function markCompleted(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $this->requestService->markCompleted($supplierRequest, $request->user());

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', 'Tüm kalemler tamamlandı olarak işlendi.');
    }

    public function cancel(Request $request, SupplierProcurementRequest $supplierRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        try {
            $this->requestService->cancelRequest($supplierRequest, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'request' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.procurements.supplier-requests.edit', $supplierRequest)
            ->with('success', 'Tedarikçi talebi iptal edildi.');
    }

    public function print(Request $request, SupplierProcurementRequest $supplierRequest): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $supplierRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        abort_unless(
            $request->user()?->hasPermissionInTenant('generate_supplier_request_form', $tenant->id),
            403
        );

        $supplierRequest->loadMissing(['supplier', 'creator', 'items.order']);

        return view('admin.procurements.supplier-requests.print', [
            'requestRecord' => $supplierRequest,
            'printData' => $this->dataBuilder->buildPrintData($supplierRequest),
        ]);
    }

    protected function resolveTenantSupplier(int $tenantId, int $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->whereKey($supplierId)
            ->where('status', 'active')
            ->whereHas('tenants', function ($query) use ($tenantId) {
                $query->where('tenant_accounts.id', $tenantId)
                    ->where('tenant_supplier_access.is_active', true)
                    ->where('tenant_supplier_access.can_request_purchase', true);
            })
            ->first();

        abort_unless($supplier, 403);

        return $supplier;
    }

    protected function ensureCanManage(Request $request, int $tenantId): void
    {
        abort_unless(
            $request->user()?->hasPermissionInTenant('manage_procurement_requests', $tenantId),
            403
        );
    }

    protected function normalizeRequestItemDecimals(array $items, array $fields): array
    {
        return collect($items)->map(function ($item) use ($fields) {
            if (!is_array($item)) {
                return $item;
            }

            foreach ($fields as $field) {
                if (!array_key_exists($field, $item)) {
                    continue;
                }

                $item[$field] = $this->normalizeDecimalValue($item[$field]);
            }

            return $item;
        })->all();
    }

    protected function normalizeDecimalValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return $normalized;
    }
}
