<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\Stock\TenantStockReservationService;
use Illuminate\Database\Eloquent\Collection;

class ProcurementCreationService
{
    public function __construct(
        protected ProcurementDataBuilder $dataBuilder,
        protected ProcurementWorkflowService $workflowService,
        protected TenantStockReservationService $reservationService,
    ) {}

    public function createForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing([
            'items.workForm',
            'items.supplierSource.supplier',
            'items.legacySupplierCompany',
        ]);

        $records = new Collection();

        foreach ($order->items as $item) {
            $records->push(
                $this->createForOrderItem($item, $item->workForm, $user)
            );
        }

        return $records;
    }

    public function createForOrderItem(OrderItem $item, ?OrderItemWorkForm $workForm = null, ?User $user = null): OrderItemProcurement
    {
        $item->loadMissing([
            'order',
            'workForm',
            'supplierSource.supplier',
            'legacySupplierCompany',
        ]);

        $workForm ??= $item->workForm;
        $allocation = $this->allocationForItem($item, $user);

        $existing = OrderItemProcurement::query()
            ->where('tenant_account_id', $item->tenant_account_id)
            ->where('order_item_id', $item->id)
            ->first();

        if ($existing) {
            $needsAssociation = $workForm && (int) $existing->work_form_id !== (int) $workForm->id;
            $needsSnapshotInitialization = blank($existing->procurement_snapshot);

            $snapshot = $this->buildSnapshot($item, $workForm, $allocation);
            $updatedAttributes = [
                'requires_procurement' => $allocation['requires_procurement'],
                'fulfillment_source' => $allocation['fulfillment_source'],
                'procurement_status' => $allocation['procurement_status'],
                'requested_quantity' => $allocation['requested_quantity'],
                'local_allocated_quantity' => $allocation['local_allocated_quantity'],
                'supplier_requested_quantity' => $allocation['supplier_requested_quantity'],
                'remaining_quantity' => $allocation['remaining_quantity'],
                'snapshot' => $snapshot,
                'updated_by' => $user?->id,
            ];

            if ($needsAssociation) {
                $updatedAttributes['work_form_id'] = $workForm->id;
            }

            $existing->forceFill($updatedAttributes)->save();

            if ($needsSnapshotInitialization) {
                if ($existing->work_form_id) {
                    $this->workflowService->initialize($existing->fresh(['workForm']), $user);
                } else {
                    $existing->forceFill([
                        'procurement_snapshot' => $this->dataBuilder->buildWorkFormSnapshot($existing),
                        'updated_by' => $user?->id,
                    ])->save();
                }
            }

            return $existing->fresh(['workForm', 'orderItem']);
        }

        $snapshot = $this->buildSnapshot($item, $workForm, $allocation);
        $supplierId = $snapshot['supplier_id'] ?? $item->supplier_id;
        $supplierSourceId = $snapshot['supplier_source_id'] ?? $item->supplier_source_id;

        $procurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $item->tenant_account_id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm?->id,
            'supplier_id' => $supplierId ?: null,
            'supplier_source_id' => $supplierSourceId ?: null,
            'requires_procurement' => $allocation['requires_procurement'],
            'fulfillment_source' => $allocation['fulfillment_source'],
            'procurement_status' => $allocation['procurement_status'],
            'requested_quantity' => $allocation['requested_quantity'],
            'local_allocated_quantity' => $allocation['local_allocated_quantity'],
            'supplier_requested_quantity' => $allocation['supplier_requested_quantity'],
            'received_quantity' => $allocation['received_quantity'],
            'remaining_quantity' => $allocation['remaining_quantity'],
            'snapshot' => $snapshot,
            'procurement_snapshot' => [],
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        if ($workForm) {
            return $this->workflowService->initialize($procurement->fresh(['workForm']), $user);
        }

        $procurement->forceFill([
            'procurement_snapshot' => $this->dataBuilder->buildWorkFormSnapshot($procurement),
        ])->save();

        return $procurement->fresh(['workForm', 'orderItem']);
    }

    private function allocationForItem(OrderItem $item, ?User $user = null): array
    {
        $requestedQuantity = round((float) $item->quantity, 4);
        $suggestedFulfillmentSource = $this->dataBuilder->suggestFulfillmentSource($item);

        if ($suggestedFulfillmentSource === OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED) {
            return [
                'requires_procurement' => true,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED,
                'procurement_status' => OrderItemProcurement::STATUS_CUSTOMER_WAITING,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0.0,
                'supplier_requested_quantity' => 0.0,
                'received_quantity' => 0.0,
                'remaining_quantity' => $requestedQuantity,
                'stock_reason_code' => 'customer_supplied',
                'stock_scope' => 'unresolved',
                'stock_resolved' => false,
            ];
        }

        if ($suggestedFulfillmentSource === OrderItemProcurement::FULFILLMENT_NOT_REQUIRED) {
            return [
                'requires_procurement' => false,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_NOT_REQUIRED,
                'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0.0,
                'supplier_requested_quantity' => 0.0,
                'received_quantity' => 0.0,
                'remaining_quantity' => 0.0,
                'stock_reason_code' => 'not_required',
                'stock_scope' => 'unresolved',
                'stock_resolved' => false,
            ];
        }

        $reservation = $this->reservationService->syncForOrderItem($item, $user);
        $localAllocated = round((float) ($reservation['allocated_quantity'] ?? 0), 4);
        $supplierRequested = round((float) ($reservation['supplier_requested_quantity'] ?? $requestedQuantity), 4);
        $requiresProcurement = $supplierRequested > 0.0001;

        if (!$requiresProcurement) {
            return [
                'requires_procurement' => false,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_LOCAL_STOCK,
                'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => $localAllocated,
                'supplier_requested_quantity' => 0.0,
                'received_quantity' => 0.0,
                'remaining_quantity' => 0.0,
                'stock_reason_code' => (string) ($reservation['reason_code'] ?? 'exact_variant_stock_found'),
                'stock_scope' => (string) ($reservation['scope'] ?? 'variant'),
                'stock_resolved' => (bool) ($reservation['resolved'] ?? false),
            ];
        }

        $fulfillmentSource = $localAllocated > 0.0001
            ? OrderItemProcurement::FULFILLMENT_MIXED
            : ($suggestedFulfillmentSource === OrderItemProcurement::FULFILLMENT_LOCAL_STOCK
                ? OrderItemProcurement::FULFILLMENT_LOCAL_STOCK
                : OrderItemProcurement::FULFILLMENT_SUPPLIER);

        return [
            'requires_procurement' => true,
            'fulfillment_source' => $fulfillmentSource,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => $requestedQuantity,
            'local_allocated_quantity' => $localAllocated,
            'supplier_requested_quantity' => $supplierRequested,
            'received_quantity' => 0.0,
            'remaining_quantity' => $supplierRequested,
            'stock_reason_code' => (string) ($reservation['reason_code'] ?? 'no_local_stock'),
            'stock_scope' => (string) ($reservation['scope'] ?? 'unresolved'),
            'stock_resolved' => (bool) ($reservation['resolved'] ?? false),
        ];
    }

    private function buildSnapshot(OrderItem $item, ?OrderItemWorkForm $workForm, array $allocation): array
    {
        $snapshot = $this->dataBuilder->build($item, $workForm);
        $snapshot['local_stock_truth'] = [
            'resolved' => (bool) ($allocation['stock_resolved'] ?? false),
            'scope' => (string) ($allocation['stock_scope'] ?? 'unresolved'),
            'reason_code' => (string) ($allocation['stock_reason_code'] ?? 'no_local_stock'),
            'requested_quantity' => (float) ($allocation['requested_quantity'] ?? 0),
            'local_allocated_quantity' => (float) ($allocation['local_allocated_quantity'] ?? 0),
            'supplier_requested_quantity' => (float) ($allocation['supplier_requested_quantity'] ?? 0),
            'remaining_quantity' => (float) ($allocation['remaining_quantity'] ?? 0),
        ];

        return $snapshot;
    }
}
