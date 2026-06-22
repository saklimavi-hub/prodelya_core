<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProcurementCreationService
{
    public function __construct(
        protected ProcurementDataBuilder $dataBuilder,
        protected ProcurementWorkflowService $workflowService
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

        $existing = OrderItemProcurement::query()
            ->where('tenant_account_id', $item->tenant_account_id)
            ->where('order_item_id', $item->id)
            ->first();

        if ($existing) {
            $needsAssociation = $workForm && (int) $existing->work_form_id !== (int) $workForm->id;
            $needsSnapshotInitialization = blank($existing->procurement_snapshot);

            if ($needsAssociation) {
                $existing->forceFill([
                    'work_form_id' => $workForm->id,
                    'updated_by' => $user?->id,
                ])->save();
            }

            if ($needsSnapshotInitialization) {
                if ($workForm && (int) $existing->work_form_id !== (int) $workForm->id) {
                    $existing->forceFill(['work_form_id' => $workForm->id])->save();
                }

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

        $snapshot = $this->dataBuilder->build($item, $workForm);
        $defaults = $this->defaultsForItem($item);
        $supplierId = $snapshot['supplier_id'] ?? $item->supplier_id;
        $supplierSourceId = $snapshot['supplier_source_id'] ?? $item->supplier_source_id;

        $procurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $item->tenant_account_id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm?->id,
            'supplier_id' => $supplierId ?: null,
            'supplier_source_id' => $supplierSourceId ?: null,
            'requires_procurement' => $defaults['requires_procurement'],
            'fulfillment_source' => $defaults['fulfillment_source'],
            'procurement_status' => $defaults['procurement_status'],
            'requested_quantity' => $defaults['requested_quantity'],
            'local_allocated_quantity' => $defaults['local_allocated_quantity'],
            'supplier_requested_quantity' => $defaults['supplier_requested_quantity'],
            'received_quantity' => $defaults['received_quantity'],
            'remaining_quantity' => $defaults['remaining_quantity'],
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

    private function defaultsForItem(OrderItem $item): array
    {
        $requestedQuantity = round((float) $item->quantity, 4);
        $fulfillmentSource = $this->dataBuilder->suggestFulfillmentSource($item);

        return match ($fulfillmentSource) {
            OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED => [
                'requires_procurement' => true,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED,
                'procurement_status' => OrderItemProcurement::STATUS_CUSTOMER_WAITING,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0,
                'supplier_requested_quantity' => 0,
                'received_quantity' => 0,
                'remaining_quantity' => $requestedQuantity,
            ],
            OrderItemProcurement::FULFILLMENT_NOT_REQUIRED => [
                'requires_procurement' => false,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_NOT_REQUIRED,
                'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0,
                'supplier_requested_quantity' => 0,
                'received_quantity' => 0,
                'remaining_quantity' => 0,
            ],
            OrderItemProcurement::FULFILLMENT_LOCAL_STOCK => [
                'requires_procurement' => true,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_LOCAL_STOCK,
                'procurement_status' => OrderItemProcurement::STATUS_PENDING,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0,
                'supplier_requested_quantity' => 0,
                'received_quantity' => 0,
                'remaining_quantity' => $requestedQuantity,
            ],
            default => [
                'requires_procurement' => true,
                'fulfillment_source' => $fulfillmentSource,
                'procurement_status' => OrderItemProcurement::STATUS_PENDING,
                'requested_quantity' => $requestedQuantity,
                'local_allocated_quantity' => 0,
                'supplier_requested_quantity' => $fulfillmentSource === OrderItemProcurement::FULFILLMENT_MIXED ? 0 : $requestedQuantity,
                'received_quantity' => 0,
                'remaining_quantity' => $requestedQuantity,
            ],
        };
    }
}
