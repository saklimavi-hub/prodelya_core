<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Support\Collection;

class OrderItemPrintGraphicCreationService
{
    public function ensureForOrder(Order $order, ?User $user = null): Collection
    {
        $order->loadMissing([
            'items.prints.tenantPrintSetting.standardPrintType',
            'items.workForm',
        ]);

        $records = collect();

        foreach ($order->items as $item) {
            $records = $records->merge($this->ensureForOrderItem($item, $user));
        }

        return $records->values();
    }

    public function ensureForOrderItem(OrderItem $orderItem, ?User $user = null): Collection
    {
        $orderItem->loadMissing([
            'prints.tenantPrintSetting.standardPrintType',
            'workForm',
        ]);

        return $orderItem->prints
            ->map(fn (OrderItemPrint $print) => $this->ensureForOrderItemPrint($print, $user))
            ->filter()
            ->values();
    }

    public function ensureForOrderItemPrint(OrderItemPrint $print, ?User $user = null): ?OrderItemPrintGraphic
    {
        $print->loadMissing([
            'order',
            'orderItem.prints',
            'orderItem.workForm',
            'tenantPrintSetting.standardPrintType',
        ]);

        if ($print->orderItem) {
            $print->orderItem->load('workForm', 'prints');
        }

        $workForm = $print->orderItem?->workForm;

        $existing = OrderItemPrintGraphic::query()
            ->where('tenant_account_id', $print->tenant_account_id)
            ->where('order_item_print_id', $print->id)
            ->first();

        if ($existing) {
            $updates = [];

            if ($workForm && (int) $existing->order_item_work_form_id !== (int) $workForm->id) {
                $updates['order_item_work_form_id'] = $workForm->id;
            }

            $sequenceCode = $this->resolveSequenceCode($print, $workForm);
            if ($sequenceCode !== null && $existing->sequence_code !== $sequenceCode) {
                $updates['sequence_code'] = $sequenceCode;
            }

            if ($updates !== []) {
                $updates['updated_by'] = $user?->id;
                $existing->forceFill($updates)->save();
            }

            return $existing->fresh();
        }

        if (!$print->effectiveRequiresGraphic()) {
            return null;
        }

        return OrderItemPrintGraphic::query()->create([
            'tenant_account_id' => $print->tenant_account_id,
            'order_id' => $print->order_id,
            'order_item_id' => $print->order_item_id,
            'order_item_print_id' => $print->id,
            'order_item_work_form_id' => $workForm?->id,
            'sequence_code' => $this->resolveSequenceCode($print, $workForm),
            'status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED,
            'visibility_default' => 'internal',
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);
    }

    private function resolveSequenceCode(OrderItemPrint $print, ?OrderItemWorkForm $workForm): ?string
    {
        $prints = $print->orderItem?->prints
            ? $print->orderItem->prints->sortBy('id')->values()
            : collect();

        $printIndex = $prints->search(fn (OrderItemPrint $row) => (int) $row->id === (int) $print->id);

        if ($workForm && $printIndex !== false) {
            $snapshotSequence = data_get($workForm->print_snapshot, $printIndex . '.sequence');
            if (filled($snapshotSequence)) {
                return (string) $snapshotSequence;
            }
        }

        if ($printIndex === false || $printIndex === null) {
            return null;
        }

        $itemSequence = (int) ($workForm?->item_sequence ?: 1);
        $alpha = 'abcdefghijklmnopqrstuvwxyz';

        return $itemSequence . ($alpha[$printIndex] ?? (string) ($printIndex + 1));
    }
}
