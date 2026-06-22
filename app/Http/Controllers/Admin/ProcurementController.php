<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\OrderItemProcurement;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\SupplierSource;
use App\Services\ProcurementWorkflowService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestDataBuilder;
use App\Services\SupplierProcurementRequestService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProcurementController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected ProcurementWorkflowService $workflowService,
        protected SupplierProcurementCurrentAccountSyncService $supplierCurrentAccountSyncService,
        protected SupplierProcurementRequestDataBuilder $supplierRequestDataBuilder,
        protected SupplierProcurementRequestService $supplierProcurementRequestService
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        abort_unless($tenant, 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'max:40'],
            'supplier_id' => ['nullable', 'integer'],
            'receipt_state' => ['nullable', Rule::in(['bekliyor', 'kismi', 'tamam', 'hic_gelmedi'])],
            'limit' => ['nullable', Rule::in(['25', '50', '100', '250'])],
        ]);

        $records = OrderItemProcurement::query()
            ->where('tenant_account_id', $tenant->id)
            ->with([
                'order.customer',
                'orderItem',
                'workForm',
                'supplier',
                'supplierSource',
                'supplierRequestItems.request',
            ])
            ->latest('id')
            ->get();

        $filtered = $this->applyFilters($records, $filters);
        $limit = (int) ($filters['limit'] ?? 50);
        $rows = $filtered->take($limit)->values();
        $supplierGroups = $this->supplierRequestDataBuilder->buildSupplierGroups($tenant, $filters);
        $selectedProcurement = $rows->first();
        $supplierCompanyMap = $this->buildSupplierCompanyMap(
            $tenant->id,
            $rows->pluck('supplier_id')->filter()->unique()->values()
        );

        return view('admin.procurements.index', [
            'tenant' => $tenant,
            'filters' => $filters,
            'rows' => $rows,
            'summaryCards' => $this->buildSummaryCards($filtered),
            'suppliers' => $records->pluck('supplier')->filter()->unique('id')->sortBy('name')->values(),
            'supplierGroups' => $supplierGroups,
            'selectedProcurement' => $selectedProcurement,
            'availableSuppliers' => $this->availableSuppliers($tenant->id),
            'canManageProcurementRequests' => $request->user()?->hasPermissionInTenant('manage_procurement_requests', $tenant->id) ?? false,
            'supplierCompanyMap' => $supplierCompanyMap,
        ]);
    }

    public function show(Request $request, OrderItemProcurement $procurement): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $procurement->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $procurement->loadMissing([
            'order.customer',
            'orderItem',
            'workForm.activityLogs.attachment',
            'supplier',
            'supplierSource.supplier',
            'supplierRequestItems.request',
        ]);

        $history = $procurement->workForm
            ? $procurement->workForm->activityLogs
                ->filter(fn ($log) => in_array($log->action_type, $this->procurementActionTypes(), true))
                ->values()
            : collect();

        return view('admin.procurements.show', [
            'procurement' => $procurement,
            'history' => $history,
            'nextActionLabel' => $this->nextActionLabel($procurement),
            'actionOptions' => $this->actionOptions($procurement),
            'supplierCompanyMatch' => $this->buildSupplierCompanyMap(
                $tenant->id,
                collect([$procurement->supplier_id])->filter()->values()
            )[$procurement->supplier_id] ?? null,
        ]);
    }

    public function updateStatus(Request $request, OrderItemProcurement $procurement): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $procurement->tenant_account_id !== $tenant->id) {
            abort(403);
        }
        $this->ensureCanManage($request, $tenant->id);

        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'request_created',
                'supplier_ordered',
                'partially_received',
                'fully_received',
                'customer_received',
                'not_required',
                'cancel',
                'reopen',
                'change_supplier',
            ])],
            'received_quantity' => ['nullable', 'numeric', 'gt:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'return_back' => ['nullable', 'boolean'],
        ]);

        try {
            $note = filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null;

            $action = $validated['action'];

            if ($action === 'change_supplier') {
                if (empty($validated['supplier_id'])) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'Yeni tedarikçi seçilmelidir.',
                    ]);
                }
                $supplier = $this->resolveTenantSupplier($tenant->id, (int) ($validated['supplier_id'] ?? 0));
                $supplierSource = $this->resolveTenantSupplierSource($supplier);
                $this->detachOpenSupplierRequestItems($procurement, $request->user()?->id);
                $this->workflowService->changeSupplier($procurement, $supplier, $supplierSource, $request->user(), $note);
            } else {
                match ($action) {
                'request_created' => $this->workflowService->markRequestCreated($procurement, $request->user(), $note),
                'supplier_ordered' => $this->workflowService->markSupplierOrdered($procurement, $request->user(), $note),
                'partially_received' => $this->workflowService->markPartiallyReceived(
                    $procurement,
                    (float) ($validated['received_quantity'] ?? 0),
                    $request->user(),
                    $note
                ),
                'fully_received' => $this->workflowService->markFullyReceived($procurement, $request->user(), $note),
                'customer_received' => $this->workflowService->markCustomerProductReceived($procurement, $request->user(), $note),
                'not_required' => $this->workflowService->markNotRequired($procurement, $request->user(), $note),
                'cancel' => $this->workflowService->cancel($procurement, $request->user(), $note),
                'reopen' => $this->workflowService->reopen($procurement, $request->user(), $note),
                };
            }

            $this->syncLinkedSupplierRequests($procurement->fresh(['supplierRequestItems.request']));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'received_quantity' => $exception->getMessage(),
            ]);
        }

        if ((bool) ($validated['return_back'] ?? false)) {
            return redirect()->back()->with('success', 'Tedarik durumu güncellendi.');
        }

        return redirect()
            ->route('admin.procurements.show', $procurement)
            ->with('success', 'Tedarik durumu güncellendi.');
    }

    private function syncLinkedSupplierRequests(OrderItemProcurement $procurement): void
    {
        $requests = $procurement->supplierRequestItems
            ->pluck('request')
            ->filter()
            ->unique('id')
            ->values();

        foreach ($requests as $requestRecord) {
            $this->supplierProcurementRequestService->syncItemsFromProcurements($requestRecord->fresh('items.procurement.orderItem'));
            $this->supplierProcurementRequestService->recalculateHeaderStatus($requestRecord->fresh('items'));
        }
    }

    private function detachOpenSupplierRequestItems(OrderItemProcurement $procurement, ?int $userId = null): void
    {
        $procurement->loadMissing('supplierRequestItems.request');

        $openRequestItems = $procurement->supplierRequestItems
            ->filter(fn (SupplierProcurementRequestItem $item) => $item->request && !$item->request->isCompleted() && !$item->request->isCancelled())
            ->values();

        foreach ($openRequestItems as $requestItem) {
            $requestRecord = $requestItem->request?->fresh('items.procurement.orderItem');
            $this->supplierCurrentAccountSyncService->cancelForRequestItem($requestItem, 'Tedarikçi değişikliği nedeniyle talep kalemi kapatıldı.');

            $requestItem->delete();

            if (!$requestRecord) {
                continue;
            }

            $requestRecord = $requestRecord->fresh('items.procurement.orderItem');

            if ($requestRecord->items->isEmpty()) {
                $requestRecord->forceFill([
                    'status' => SupplierProcurementRequest::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'updated_by' => $userId,
                ])->save();

                continue;
            }

            $this->supplierProcurementRequestService->syncItemsFromProcurements($requestRecord);
            $this->supplierProcurementRequestService->recalculateHeaderStatus($requestRecord->fresh('items.procurement'));
        }
    }

    private function applyFilters(Collection $records, array $filters): Collection
    {
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $status = trim((string) ($filters['status'] ?? ''));
        $source = trim((string) ($filters['source'] ?? ''));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $receiptState = (string) ($filters['receipt_state'] ?? '');

        return $records
            ->filter(function (OrderItemProcurement $procurement) use ($query, $status, $source, $supplierId, $receiptState): bool {
                $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];
                $customerName = (string) ($procurement->order?->customer?->legal_name ?? '');

                if ($query !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $snapshot['order_number'] ?? null,
                        $snapshot['work_form_number'] ?? null,
                        $customerName,
                        $snapshot['product_name'] ?? null,
                        $snapshot['product_code'] ?? null,
                    ])));

                    if (!str_contains($haystack, $query)) {
                        return false;
                    }
                }

                if ($status !== '' && $procurement->procurement_status !== $status) {
                    return false;
                }

                if ($source !== '' && $procurement->fulfillment_source !== $source) {
                    return false;
                }

                if ($supplierId > 0 && (int) $procurement->supplier_id !== $supplierId) {
                    return false;
                }

                if ($receiptState !== '' && !$this->matchesReceiptState($procurement, $receiptState)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    private function matchesReceiptState(OrderItemProcurement $procurement, string $receiptState): bool
    {
        $received = (float) $procurement->received_quantity;
        $remaining = (float) $procurement->remaining_quantity;

        return match ($receiptState) {
            'hic_gelmedi' => $received <= 0.0,
            'kismi' => $received > 0.0 && $remaining > 0.0,
            'tamam' => $remaining <= 0.0,
            'bekliyor' => in_array($procurement->procurement_status, [
                OrderItemProcurement::STATUS_PENDING,
                OrderItemProcurement::STATUS_REQUEST_CREATED,
                OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                OrderItemProcurement::STATUS_CUSTOMER_WAITING,
            ], true),
            default => true,
        };
    }

    private function buildSummaryCards(Collection $records): array
    {
        return [
            ['label' => 'Tedarik Bekleyen', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_PENDING)->count(), 'tone' => 'amber'],
            ['label' => 'Tedarik Talebi Açıldı', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_REQUEST_CREATED)->count(), 'tone' => 'blue'],
            ['label' => 'Sipariş Verildi', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_SUPPLIER_ORDERED)->count(), 'tone' => 'blue'],
            ['label' => 'Kısmi Geldi', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_PARTIALLY_RECEIVED)->count(), 'tone' => 'amber'],
            ['label' => 'Tamamı Geldi', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_FULLY_RECEIVED)->count(), 'tone' => 'green'],
            ['label' => 'Müşteri Ürünü Bekleyen', 'value' => $records->where('procurement_status', OrderItemProcurement::STATUS_CUSTOMER_WAITING)->count(), 'tone' => 'purple'],
        ];
    }

    private function nextActionLabel(OrderItemProcurement $procurement): string
    {
        return match ($procurement->procurement_status) {
            OrderItemProcurement::STATUS_PENDING => 'Tedarik talebini aç',
            OrderItemProcurement::STATUS_REQUEST_CREATED => 'Sipariş verildi olarak işaretle',
            OrderItemProcurement::STATUS_SUPPLIER_ORDERED => 'Gelen miktarı işle',
            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'Eksik kalan ürünü tamamla',
            OrderItemProcurement::STATUS_FULLY_RECEIVED => 'İş Formu ile süreci takip et',
            OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Müşteri ürün kabulünü işle',
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'İş Formu ile süreci takip et',
            OrderItemProcurement::STATUS_NOT_REQUIRED => 'Tedarik aksiyonu gerekmiyor',
            OrderItemProcurement::STATUS_CANCELLED => 'İptal edildi',
            default => 'Tedarik kaydını incele',
        };
    }

    private function actionOptions(OrderItemProcurement $procurement): array
    {
        $base = [
            ['action' => 'request_created', 'label' => 'Tedarik Talebi Aç', 'show' => !$procurement->isNotRequired()],
            ['action' => 'supplier_ordered', 'label' => 'Sipariş Verildi İşaretle', 'show' => $procurement->isSupplierBased()],
            ['action' => 'partially_received', 'label' => 'Kısmi Geldi', 'show' => !$procurement->isNotRequired() && !$procurement->isFullyReceived()],
            ['action' => 'fully_received', 'label' => 'Tamamı Geldi', 'show' => !$procurement->isNotRequired() && !$procurement->isFullyReceived()],
            ['action' => 'customer_received', 'label' => 'Müşteri Ürünü Geldi', 'show' => $procurement->isCustomerSupplied()],
            ['action' => 'not_required', 'label' => 'Tedarik Gerekmiyor', 'show' => true],
            ['action' => 'cancel', 'label' => 'İptal', 'show' => true],
        ];

        return array_values(array_filter($base, fn (array $row) => $row['show']));
    }

    private function procurementActionTypes(): array
    {
        return [
            'procurement_needed',
            'procurement_request_created',
            'supplier_ordered',
            'procurement_partially_received',
            'procurement_fully_received',
            'procurement_cancelled',
            'procurement_reopened',
            'procurement_supplier_changed',
            'customer_supplied_product_waiting',
            'customer_supplied_product_received',
            'procurement_not_required',
        ];
    }

    private function ensureCanManage(Request $request, int $tenantId): void
    {
        abort_unless(
            $request->user()?->hasPermissionInTenant('manage_procurement_requests', $tenantId),
            403
        );
    }

    private function availableSuppliers(int $tenantId): Collection
    {
        return Supplier::query()
            ->where('status', 'active')
            ->whereHas('tenants', function ($query) use ($tenantId) {
                $query->where('tenant_accounts.id', $tenantId)
                    ->where('tenant_supplier_access.is_active', true)
                    ->where('tenant_supplier_access.can_request_purchase', true);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    private function resolveTenantSupplier(int $tenantId, int $supplierId): Supplier
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

    private function resolveTenantSupplierSource(Supplier $supplier): ?SupplierSource
    {
        return SupplierSource::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    private function buildSupplierCompanyMap(int $tenantId, Collection $supplierIds): array
    {
        if ($supplierIds->isEmpty()) {
            return [];
        }

        $supplierLinks = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->whereIn('link_id', $supplierIds->all())
            ->get(['current_account_id', 'link_id']);

        if ($supplierLinks->isEmpty()) {
            return [];
        }

        $companyLinks = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->whereIn('current_account_id', $supplierLinks->pluck('current_account_id')->all())
            ->get(['current_account_id', 'link_id'])
            ->keyBy('current_account_id');

        $companies = Company::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $companyLinks->pluck('link_id')->filter()->all())
            ->get(['id', 'legal_name', 'short_name'])
            ->keyBy('id');

        $map = [];

        foreach ($supplierLinks as $supplierLink) {
            $companyLink = $companyLinks->get($supplierLink->current_account_id);
            $company = $companyLink ? $companies->get($companyLink->link_id) : null;

            if (! $company) {
                continue;
            }

            $map[$supplierLink->link_id] = [
                'company_id' => $company->id,
                'company_name' => $company->short_name ?: $company->legal_name,
            ];
        }

        return $map;
    }
}
