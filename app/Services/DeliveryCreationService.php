<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DeliveryCreationService
{
    public function __construct(
        protected DeliveryDataBuilder $dataBuilder,
        protected DeliveryWorkflowService $workflowService,
        protected FinanceSummaryService $financeSummaryService
    ) {}

    public function createForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing(['workForms.orderItem', 'workForms.attachments']);

        $records = new Collection();

        foreach ($order->workForms as $workForm) {
            $records->push($this->createForWorkForm($workForm, $user));
        }

        return $records;
    }

    public function createForWorkForm(OrderItemWorkForm $workForm, ?User $user = null): OrderItemWorkFormDelivery
    {
        $workForm->loadMissing(['order', 'orderItem', 'attachments']);

        $existing = OrderItemWorkFormDelivery::query()
            ->where('tenant_account_id', $workForm->tenant_account_id)
            ->where('work_form_id', $workForm->id)
            ->first();

        if ($existing) {
            if (blank($existing->delivery_snapshot)) {
                return $this->workflowService->initialize($existing->fresh(['workForm.attachments', 'orderItem', 'order']), $user);
            }

            return $existing->fresh(['workForm', 'orderItem', 'order']);
        }

        $plannedQuantity = round((float) (
            data_get($workForm->product_snapshot, 'quantity', $workForm->orderItem?->quantity ?? 0)
        ), 4);
        $deliveryMethod = $this->dataBuilder->normalizeDeliveryMethod(
            $workForm->order?->delivery_type ?: data_get($workForm->order_snapshot, 'delivery_type')
        );

        $delivery = OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'work_form_id' => $workForm->id,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'delivery_method' => $deliveryMethod,
            'planned_quantity' => $plannedQuantity,
            'delivered_quantity' => 0,
            'remaining_quantity' => $plannedQuantity,
            'package_count' => null,
            'units_per_package' => null,
            'packaged_quantity' => null,
            'package_type' => null,
            'package_note' => null,
            'carrier_name' => null,
            'tracking_number' => null,
            'recipient_name' => null,
            'delivery_document_no' => null,
            'recipient_phone' => null,
            'delivery_note' => null,
            'financial_warning' => $this->financeSummaryService->deliveryFinancialWarning($workForm->order),
            'delivery_snapshot' => [],
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        return $this->workflowService->initialize($delivery->fresh(['workForm.attachments', 'orderItem', 'order']), $user);
    }
}
