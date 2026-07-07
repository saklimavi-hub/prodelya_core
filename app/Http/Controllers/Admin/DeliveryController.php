<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemWorkFormDelivery;
use App\Services\DeliveryWorkflowService;
use App\Services\TenantDeliveryTypeService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected DeliveryWorkflowService $workflowService,
        protected TenantDeliveryTypeService $tenantDeliveryTypeService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        abort_unless($tenant, 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:60'],
            'method' => ['nullable', 'string', 'max:40'],
            'customer' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', Rule::in(['25', '50', '100', '250'])],
        ]);

        $records = OrderItemWorkFormDelivery::query()
            ->where('tenant_account_id', $tenant->id)
            ->with([
                'order.customer',
                'orderItem',
                'workForm',
            ])
            ->latest('id')
            ->get();

        $filtered = $this->applyFilters($records, $filters);
        $limit = (int) ($filters['limit'] ?? 50);
        $rows = $filtered
            ->sortBy([
                fn (OrderItemWorkFormDelivery $delivery) => (string) ($delivery->order?->document_number ?? ''),
                fn (OrderItemWorkFormDelivery $delivery) => (string) ($delivery->workForm?->work_form_number ?? ''),
                fn (OrderItemWorkFormDelivery $delivery) => (string) ($delivery->orderItem?->product_name ?? ''),
            ])
            ->take($limit)
            ->values();

        return view('admin.deliveries.index', [
            'tenant' => $tenant,
            'filters' => $filters,
            'rows' => $rows,
            'groupedRows' => $rows->groupBy('order_id'),
            'summaryCards' => $this->buildSummaryCards($filtered),
            'statusLabels' => OrderItemWorkFormDelivery::statusLabels(),
            'methodLabels' => OrderItemWorkFormDelivery::deliveryMethodLabels(),
            'packageTypeLabels' => OrderItemWorkFormDelivery::packageTypeLabels(),
            'financialWarningLabels' => OrderItemWorkFormDelivery::financialWarningLabels(),
        ]);
    }

    public function show(Request $request, OrderItemWorkFormDelivery $delivery): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $delivery->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $delivery->loadMissing([
            'order.customer',
            'orderItem',
            'workForm.attachments',
            'workForm.activityLogs.attachment',
        ]);

        $history = $delivery->workForm
            ? $delivery->workForm->activityLogs
                ->filter(fn ($log) => in_array($log->action_type, $this->deliveryActionTypes(), true))
                ->values()
            : collect();
        $deliveryTypeState = $this->tenantDeliveryTypeService->selectionState(
            $tenant->id,
            $delivery->order?->delivery_type_id,
            $delivery->order?->delivery_type
        );

        return view('admin.deliveries.show', [
            'delivery' => $delivery,
            'history' => $history,
            'statusLabels' => OrderItemWorkFormDelivery::statusLabels(),
            'methodLabels' => OrderItemWorkFormDelivery::deliveryMethodLabels(),
            'packageTypeLabels' => OrderItemWorkFormDelivery::packageTypeLabels(),
            'deliveryTypeOptions' => $deliveryTypeState['types'],
            'selectedDeliveryTypeId' => old('delivery_type_id', $deliveryTypeState['selected_id']),
            'legacyDeliveryTypeLabel' => $deliveryTypeState['legacy_label'],
            'nextActionLabel' => $this->nextActionLabel($delivery),
            'groupDeliveries' => $delivery->order
                ? OrderItemWorkFormDelivery::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('order_id', $delivery->order_id)
                    ->with(['orderItem', 'workForm'])
                    ->orderBy('id')
                    ->get()
                : collect(),
        ]);
    }

    public function updateDetails(Request $request, OrderItemWorkFormDelivery $delivery): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $delivery->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'delivery_type_id' => ['nullable', 'integer'],
            'delivery_method' => ['nullable', Rule::in(array_keys(OrderItemWorkFormDelivery::deliveryMethodLabels()))],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'delivery_document_no' => ['nullable', 'string', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'units_per_package' => ['nullable', 'integer', 'min:1'],
            'packaged_quantity' => ['nullable', 'integer', 'min:1'],
            'package_type' => ['nullable', Rule::in(array_keys(OrderItemWorkFormDelivery::packageTypeLabels()))],
            'package_note' => ['nullable', 'string', 'max:1000'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $deliveryTypePayload = $this->tenantDeliveryTypeService->resolveForPersistence(
            $tenant->id,
            isset($validated['delivery_type_id']) && $validated['delivery_type_id'] !== ''
                ? (int) $validated['delivery_type_id']
                : null,
            $delivery->order?->delivery_type
        );

        if ($delivery->order) {
            $delivery->order->forceFill([
                'delivery_type_id' => $deliveryTypePayload['delivery_type_id'],
                'delivery_type' => $deliveryTypePayload['delivery_type'],
            ])->save();
        }

        $this->workflowService->updateDetails(
            $delivery,
            $validated,
            $request->user(),
            'Teslimat bilgileri güncellendi.'
        );

        return redirect()
            ->route('admin.deliveries.show', $delivery)
            ->with('success', 'Teslimat bilgileri güncellendi.');
    }

    public function updateStatus(Request $request, OrderItemWorkFormDelivery $delivery): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $delivery->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'preparing',
                'ready',
                'shipped',
                'courier_out',
                'partially_delivered',
                'delivered',
                'issue',
                'cancel',
            ])],
            'this_delivery_quantity' => ['nullable', 'numeric', 'gt:0'],
            'delivered_quantity' => ['nullable', 'numeric', 'gt:0'],
            'delivery_method' => ['nullable', Rule::in(array_keys(OrderItemWorkFormDelivery::deliveryMethodLabels()))],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'units_per_package' => ['nullable', 'integer', 'min:1'],
            'packaged_quantity' => ['nullable', 'integer', 'min:1'],
            'package_type' => ['nullable', Rule::in(array_keys(OrderItemWorkFormDelivery::packageTypeLabels()))],
            'package_note' => ['nullable', 'string', 'max:1000'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'delivery_document_no' => ['nullable', 'string', 'max:160'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $note = filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null;
            $deliveryQty = (float) ($validated['this_delivery_quantity'] ?? $validated['delivered_quantity'] ?? 0);
            $deliveryAttributes = [];

            foreach ([
                'delivery_method',
                'package_count',
                'units_per_package',
                'packaged_quantity',
                'package_type',
                'package_note',
                'recipient_name',
                'delivery_document_no',
                'tracking_number',
                'carrier_name',
            ] as $key) {
                if (array_key_exists($key, $validated)) {
                    $deliveryAttributes[$key] = $validated[$key];
                }
            }

            if (array_key_exists('note', $validated)) {
                $deliveryAttributes['delivery_note'] = $validated['note'];
            }

            match ($validated['action']) {
                'preparing' => $this->workflowService->markPreparing($delivery, $request->user(), $note),
                'ready' => $this->workflowService->markReady($delivery, $request->user(), $note),
                'shipped' => $this->workflowService->markShipped($delivery, $request->user(), $note),
                'courier_out' => $this->workflowService->markCourierOut($delivery, $request->user(), $note),
                'partially_delivered' => $this->workflowService->markPartiallyDelivered(
                    $delivery,
                    $deliveryQty,
                    $deliveryAttributes,
                    $request->user(),
                    $note
                ),
                'delivered' => $this->workflowService->markDelivered($delivery, $deliveryAttributes, $request->user(), $note),
                'issue' => $this->workflowService->markIssue($delivery, $request->user(), $note),
                'cancel' => $this->workflowService->cancel($delivery, $request->user(), $note),
            };
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'this_delivery_quantity' => $exception->getMessage(),
                'delivered_quantity' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.deliveries.show', $delivery)
            ->with('success', 'Teslimat durumu güncellendi.');
    }

    private function applyFilters(Collection $records, array $filters): Collection
    {
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $status = trim((string) ($filters['status'] ?? ''));
        $method = trim((string) ($filters['method'] ?? ''));
        $customer = mb_strtolower(trim((string) ($filters['customer'] ?? '')));

        return $records->filter(function (OrderItemWorkFormDelivery $delivery) use (
            $query,
            $status,
            $method,
            $customer
        ): bool {
            $snapshot = is_array($delivery->delivery_snapshot) ? $delivery->delivery_snapshot : [];
            $customerName = (string) ($delivery->order?->customer?->legal_name ?? '');

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

            if ($status !== '' && $delivery->delivery_status !== $status) {
                return false;
            }

            if ($method !== '' && $delivery->delivery_method !== $method) {
                return false;
            }

            if ($customer !== '' && !str_contains(mb_strtolower($customerName), $customer)) {
                return false;
            }

            return true;
        })->values();
    }

    private function buildSummaryCards(Collection $records): array
    {
        return [
            ['label' => 'Teslimata Hazır', 'value' => $records->where('delivery_status', OrderItemWorkFormDelivery::STATUS_READY)->count()],
            ['label' => 'Kısmi Teslim', 'value' => $records->where('delivery_status', OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED)->count()],
            ['label' => 'Bugün Teslim', 'value' => $records->filter(fn (OrderItemWorkFormDelivery $row) => optional($row->delivered_at)?->isToday())->count()],
            ['label' => 'Sorunlu', 'value' => $records->where('delivery_status', OrderItemWorkFormDelivery::STATUS_ISSUE)->count()],
        ];
    }

    private function nextActionLabel(OrderItemWorkFormDelivery $delivery): string
    {
        return match ($delivery->delivery_status) {
            OrderItemWorkFormDelivery::STATUS_PENDING => 'Teslimat hazırlığını başlat',
            OrderItemWorkFormDelivery::STATUS_PREPARING => 'Teslimata hazır olarak işaretle',
            OrderItemWorkFormDelivery::STATUS_READY => 'Sevkiyata çıkar',
            OrderItemWorkFormDelivery::STATUS_SHIPPED => 'Kargo takibini izle',
            OrderItemWorkFormDelivery::STATUS_COURIER_OUT => 'Kurye teslimini takip et',
            OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED => 'Kalan teslimatı tamamla',
            OrderItemWorkFormDelivery::STATUS_DELIVERED => 'İş Formu ve müşteri takibini izlemeye devam et',
            OrderItemWorkFormDelivery::STATUS_ISSUE => 'Teslimat sorununu değerlendir',
            OrderItemWorkFormDelivery::STATUS_CANCELLED => 'İptal edildi',
            default => 'Teslimat kaydını incele',
        };
    }

    private function deliveryActionTypes(): array
    {
        return [
            'delivery_record_created',
            'delivery_details_updated',
            'delivery_preparing',
            'delivery_ready',
            'delivery_shipped',
            'courier_out_for_delivery',
            'delivery_partially_completed',
            'delivery_completed',
            'delivery_issue_reported',
            'delivery_cancelled',
            'delivery_photo_added',
            'delivery_document_added',
        ];
    }
}
