<?php

namespace App\Services;

use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Facades\DB;

class ProcurementWorkflowService
{
    public function __construct(
        protected ProcurementDataBuilder $dataBuilder,
        protected NotificationEventService $notificationEventService,
    ) {}

    public function initialize(OrderItemProcurement $procurement, ?User $user = null): OrderItemProcurement
    {
        $actionType = match ($procurement->fulfillment_source) {
            OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED => 'customer_supplied_product_waiting',
            OrderItemProcurement::FULFILLMENT_NOT_REQUIRED => 'procurement_not_required',
            default => 'procurement_needed',
        };

        $note = match ($actionType) {
            'customer_supplied_product_waiting' => 'Müşteri ürünü bekleniyor olarak tedarik kaydı oluşturuldu.',
            'procurement_not_required' => 'Bu kalem için tedarik gerekmiyor.',
            default => 'Tedarik kaydı oluşturuldu.',
        };

        return DB::transaction(function () use ($procurement, $user, $actionType, $note): OrderItemProcurement {
            return $this->persistWithSnapshot(
                $procurement,
                [],
                $actionType,
                null,
                $procurement->procurement_status,
                $note,
                $user
            );
        });
    }

    public function markRequestCreated(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        return $this->transition(
            $procurement,
            [
                'procurement_status' => OrderItemProcurement::STATUS_REQUEST_CREATED,
            ],
            'procurement_request_created',
            $user,
            $note ?: 'Tedarik talebi açıldı.'
        );
    }

    public function markSupplierOrdered(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        return $this->transition(
            $procurement,
            [
                'procurement_status' => OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                'ordered_at' => now(),
            ],
            'supplier_ordered',
            $user,
            $note ?: 'Tedarikçiye sipariş verildi.',
            'procurement_ordered'
        );
    }

    public function markPartiallyReceived(OrderItemProcurement $procurement, float $receivedQty, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        if ($receivedQty <= 0) {
            throw new \InvalidArgumentException('Received quantity must be greater than zero.');
        }

        $currentReceived = (float) $procurement->received_quantity;
        $requested = (float) $procurement->requested_quantity;
        $newReceived = round($currentReceived + $receivedQty, 4);

        if ($newReceived - $requested > 0.0001) {
            throw new \InvalidArgumentException('Received quantity cannot exceed requested quantity.');
        }

        $remaining = max(round($requested - $newReceived, 4), 0.0);
        $isFull = $remaining <= 0.0001;

        return $this->transition(
            $procurement,
            [
                'received_quantity' => $newReceived,
                'remaining_quantity' => $remaining,
                'procurement_status' => $isFull
                    ? OrderItemProcurement::STATUS_FULLY_RECEIVED
                    : OrderItemProcurement::STATUS_PARTIALLY_RECEIVED,
                'partially_received_at' => $procurement->partially_received_at ?: now(),
                'fully_received_at' => $isFull ? now() : $procurement->fully_received_at,
            ],
            $isFull ? 'procurement_fully_received' : 'procurement_partially_received',
            $user,
            $note ?: ($isFull ? 'Tedarik kalemi tamamen geldi.' : 'Tedarik kalemi kısmi geldi.'),
            $isFull ? 'procurement_received' : 'procurement_partially_received'
        );
    }

    public function markFullyReceived(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        $requested = round((float) $procurement->requested_quantity, 4);

        return $this->transition(
            $procurement,
            [
                'received_quantity' => $requested,
                'remaining_quantity' => 0,
                'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                'fully_received_at' => now(),
            ],
            'procurement_fully_received',
            $user,
            $note ?: 'Tedarik kalemi tamamen geldi.',
            'procurement_received'
        );
    }

    public function markCustomerProductReceived(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        $requested = round((float) $procurement->requested_quantity, 4);

        return $this->transition(
            $procurement,
            [
                'received_quantity' => $requested,
                'remaining_quantity' => 0,
                'procurement_status' => OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
                'partially_received_at' => $procurement->partially_received_at ?: now(),
                'fully_received_at' => now(),
            ],
            'customer_supplied_product_received',
            $user,
            $note ?: 'Müşteri ürünü teslim alındı.'
        );
    }

    public function markNotRequired(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        return $this->transition(
            $procurement,
            [
                'requires_procurement' => false,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_NOT_REQUIRED,
                'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                'supplier_requested_quantity' => 0,
                'received_quantity' => 0,
                'remaining_quantity' => 0,
            ],
            'procurement_not_required',
            $user,
            $note ?: 'Tedarik gerekmiyor olarak işaretlendi.'
        );
    }

    public function cancel(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        return $this->transition(
            $procurement,
            [
                'procurement_status' => OrderItemProcurement::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ],
            'procurement_cancelled',
            $user,
            $note ?: 'Tedarik kaydı iptal edildi.',
            'procurement_cancelled'
        );
    }

    public function reopen(OrderItemProcurement $procurement, ?User $user = null, ?string $note = null): OrderItemProcurement
    {
        if ((float) $procurement->received_quantity > 0.0001) {
            throw new \InvalidArgumentException('Teslim alınmaya başlayan tedarik kaydı geri alınamaz.');
        }

        $procurement->loadMissing('supplierRequestItems.request');

        $hasOpenRequest = $procurement->supplierRequestItems
            ->contains(fn ($item) => $item->request && !$item->request->isCompleted() && !$item->request->isCancelled());

        $fallbackSource = $procurement->supplier_id
            ? OrderItemProcurement::FULFILLMENT_SUPPLIER
            : ($procurement->isLocalStockBased() ? OrderItemProcurement::FULFILLMENT_LOCAL_STOCK : OrderItemProcurement::FULFILLMENT_SUPPLIER);

        $shortfall = $this->supplierShortfall($procurement);
        $requiresProcurement = $shortfall > 0.0001;
        $status = ! $requiresProcurement
            ? OrderItemProcurement::STATUS_NOT_REQUIRED
            : ($hasOpenRequest ? OrderItemProcurement::STATUS_REQUEST_CREATED : OrderItemProcurement::STATUS_PENDING);
        $reopenSource = ! $requiresProcurement
            ? OrderItemProcurement::FULFILLMENT_LOCAL_STOCK
            : ((float) $procurement->local_allocated_quantity > 0.0001 ? OrderItemProcurement::FULFILLMENT_MIXED : $fallbackSource);

        return $this->transition(
            $procurement,
            [
                'requires_procurement' => $requiresProcurement,
                'fulfillment_source' => $reopenSource,
                'procurement_status' => $status,
                'supplier_requested_quantity' => round($shortfall, 4),
                'remaining_quantity' => round($shortfall, 4),
                'received_quantity' => 0,
                'ordered_at' => $status === OrderItemProcurement::STATUS_REQUEST_CREATED ? $procurement->ordered_at : null,
                'partially_received_at' => null,
                'fully_received_at' => null,
                'cancelled_at' => null,
            ],
            'procurement_reopened',
            $user,
            $note ?: 'Tedarik kaydı yeniden açıldı.'
        );
    }

    public function changeSupplier(
        OrderItemProcurement $procurement,
        Supplier $supplier,
        ?SupplierSource $supplierSource = null,
        ?User $user = null,
        ?string $note = null
    ): OrderItemProcurement {
        if ((float) $procurement->received_quantity > 0.0001) {
            throw new \InvalidArgumentException('Teslim alınmaya başlayan tedarik kaydında tedarikçi değiştirilemez.');
        }

        if ((int) $procurement->supplier_id === (int) $supplier->id) {
            throw new \InvalidArgumentException('Yeni tedarikçi mevcut tedarikçi ile aynı olamaz.');
        }

        $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];
        $snapshot['supplier_id'] = $supplier->id;
        $snapshot['supplier_name'] = $supplier->name;
        $snapshot['supplier_source_id'] = $supplierSource?->id;
        $snapshot['supplier_source_name'] = $supplierSource?->source_name;

        $shortfall = $this->supplierShortfall($procurement);
        $requiresProcurement = $shortfall > 0.0001;

        return $this->transition(
            $procurement,
            [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $supplierSource?->id,
                'requires_procurement' => $requiresProcurement,
                'fulfillment_source' => $requiresProcurement
                    ? ((float) $procurement->local_allocated_quantity > 0.0001 ? OrderItemProcurement::FULFILLMENT_MIXED : OrderItemProcurement::FULFILLMENT_SUPPLIER)
                    : OrderItemProcurement::FULFILLMENT_LOCAL_STOCK,
                'procurement_status' => $requiresProcurement ? OrderItemProcurement::STATUS_PENDING : OrderItemProcurement::STATUS_NOT_REQUIRED,
                'supplier_requested_quantity' => round($shortfall, 4),
                'received_quantity' => 0,
                'remaining_quantity' => round($shortfall, 4),
                'ordered_at' => null,
                'partially_received_at' => null,
                'fully_received_at' => null,
                'cancelled_at' => null,
                'snapshot' => $snapshot,
            ],
            'procurement_supplier_changed',
            $user,
            $note ?: 'Tedarikçi değiştirildi ve tedarik kalemi yeniden açıldı.'
        );
    }

    private function supplierShortfall(OrderItemProcurement $procurement): float
    {
        return max(round((float) $procurement->requested_quantity - (float) $procurement->local_allocated_quantity, 4), 0.0);
    }

    private function transition(
        OrderItemProcurement $procurement,
        array $changes,
        string $actionType,
        ?User $user,
        string $note,
        ?string $notificationEventKey = null
    ): OrderItemProcurement {
        return DB::transaction(function () use ($procurement, $changes, $actionType, $user, $note, $notificationEventKey): OrderItemProcurement {
            $oldStatus = $procurement->procurement_status;

            return $this->persistWithSnapshot(
                $procurement,
                $changes,
                $actionType,
                $oldStatus,
                $changes['procurement_status'] ?? $procurement->procurement_status,
                $note,
                $user,
                $notificationEventKey
            );
        });
    }

    private function persistWithSnapshot(
        OrderItemProcurement $procurement,
        array $changes,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user,
        ?string $notificationEventKey = null
    ): OrderItemProcurement {
        $procurement->forceFill($changes + [
            'procurement_snapshot' => $this->dataBuilder->buildWorkFormSnapshot($procurement->fill($changes)),
            'updated_by' => $user?->id,
        ])->save();

        $procurement = $procurement->fresh(['workForm']);

        if ($procurement->workForm) {
            $this->syncWorkFormSnapshot($procurement->workForm, $procurement, $user);
            $this->createWorkflowLog($procurement, $actionType, $oldStatus, $newStatus, $note, $user);
        }

        $freshProcurement = $procurement->fresh([
            'tenant',
            'order.customer.contacts',
            'orderItem',
            'workForm',
            'supplier',
            'supplierSource.supplier',
            'updater',
            'creator',
        ]);

        if ($notificationEventKey) {
            $this->dispatchSafely($freshProcurement, $notificationEventKey, [
                'audience_type' => 'procurement_team',
                'channels' => ['internal', 'email'],
                'created_by' => $user,
                'context' => [
                    'status_label' => $freshProcurement->safeStatusLabel(),
                ],
            ]);
        }

        return $freshProcurement;
    }

    private function syncWorkFormSnapshot(OrderItemWorkForm $workForm, OrderItemProcurement $procurement, ?User $user): void
    {
        $workForm->forceFill([
            'procurement_snapshot' => $procurement->procurement_snapshot,
            'version' => (int) $workForm->version + 1,
            'updated_by' => $user?->id,
        ])->save();
    }

    private function createWorkflowLog(
        OrderItemProcurement $procurement,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user
    ): void {
        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $procurement->tenant_account_id,
            'work_form_id' => $procurement->work_form_id,
            'order_id' => $procurement->order_id,
            'order_item_id' => $procurement->order_item_id,
            'action_type' => $actionType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'note' => $note,
            'visibility' => 'internal',
            'created_by' => $user?->id,
        ]);
    }

    private function dispatchSafely(OrderItemProcurement $procurement, string $eventKey, array $options = []): void
    {
        $tenant = $procurement->tenant;

        if (!$tenant) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent($tenant, $eventKey, $procurement, $options);
        } catch (\Throwable) {
            // Tedarik workflow'u notification hatası nedeniyle rollback edilmemeli.
        }
    }
}
