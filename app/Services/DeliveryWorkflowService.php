<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Facades\DB;

class DeliveryWorkflowService
{
    public function __construct(
        protected DeliveryDataBuilder $dataBuilder,
        protected NotificationEventService $notificationEventService,
    ) {}

    public function initialize(OrderItemWorkFormDelivery $delivery, ?User $user = null): OrderItemWorkFormDelivery
    {
        return DB::transaction(function () use ($delivery, $user): OrderItemWorkFormDelivery {
            return $this->persistWithSnapshot(
                $delivery,
                [],
                'delivery_record_created',
                null,
                $delivery->delivery_status,
                'Teslimat kaydı oluşturuldu.',
                $user
            );
        });
    }

    public function updateDetails(
        OrderItemWorkFormDelivery $delivery,
        array $attributes,
        ?User $user = null,
        ?string $note = null
    ): OrderItemWorkFormDelivery {
        $allowed = [
            'delivery_method',
            'carrier_name',
            'tracking_number',
            'recipient_name',
            'delivery_document_no',
            'recipient_phone',
            'package_count',
            'units_per_package',
            'packaged_quantity',
            'package_type',
            'package_note',
            'delivery_note',
        ];

        $changes = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            $changes[$key] = $value;
        }

        return DB::transaction(function () use ($delivery, $changes, $user, $note): OrderItemWorkFormDelivery {
            return $this->persistWithSnapshot(
                $delivery,
                $changes,
                'delivery_details_updated',
                $delivery->delivery_status,
                $delivery->delivery_status,
                $note ?: 'Teslimat bilgileri güncellendi.',
                $user
            );
        });
    }

    public function markPreparing(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_PREPARING,
                'prepared_at' => now(),
            ],
            'delivery_preparing',
            $user,
            $note ?: 'Teslimat hazırlığı başlatıldı.'
        );
    }

    public function markReady(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_READY,
                'ready_at' => now(),
            ],
            'delivery_ready',
            $user,
            $note ?: 'Kalem teslimata hazır olarak işaretlendi.',
            'delivery_ready'
        );
    }

    public function markShipped(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_SHIPPED,
                'shipped_at' => now(),
            ],
            'delivery_shipped',
            $user,
            $note ?: 'Kalem kargoya verildi.'
        );
    }

    public function markCourierOut(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_COURIER_OUT,
            ],
            'courier_out_for_delivery',
            $user,
            $note ?: 'Kurye teslimata çıktı.'
        );
    }

    public function markPartiallyDelivered(
        OrderItemWorkFormDelivery $delivery,
        float $deliveredQty,
        array|User|null $attributes = [],
        ?User $user = null,
        ?string $note = null
    ): OrderItemWorkFormDelivery {
        if ($attributes instanceof User) {
            $user = $attributes;
            $attributes = [];
        }

        if ($deliveredQty <= 0) {
            throw new \InvalidArgumentException('Teslim edilen adet 0’dan büyük olmalı.');
        }

        $currentDelivered = (float) $delivery->delivered_quantity;
        $planned = (float) $delivery->planned_quantity;
        $newDelivered = round($currentDelivered + $deliveredQty, 4);
        $eligibleLimit = $this->deliveryEligibleLimit($delivery);

        if ($newDelivered - $planned > 0.0001) {
            throw new \InvalidArgumentException('Teslim edilen adet sipariş adedini aşamaz.');
        }

        if ($deliveredQty - (float) $delivery->remaining_quantity > 0.0001) {
            throw new \InvalidArgumentException('Teslim edilen adet kalan adetten fazla olamaz.');
        }

        if ($newDelivered - $eligibleLimit > 0.0001) {
            throw new \InvalidArgumentException($this->eligibleLimitErrorMessage($delivery, $eligibleLimit));
        }

        $remaining = max(round($planned - $newDelivered, 4), 0.0);
        $isFull = $remaining <= 0.0001;

        return $this->transition(
            $delivery,
            array_merge($this->normalizeDeliveryAttributes($attributes, $delivery, $deliveredQty), [
                'delivered_quantity' => $newDelivered,
                'remaining_quantity' => $remaining,
                'delivery_status' => $isFull
                    ? OrderItemWorkFormDelivery::STATUS_DELIVERED
                    : OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED,
                'partially_delivered_at' => $delivery->partially_delivered_at ?: now(),
                'delivered_at' => $isFull ? now() : $delivery->delivered_at,
            ]),
            $isFull ? 'delivery_completed' : 'delivery_partially_completed',
            $user,
            $note ?: ($isFull ? 'Kalem tamamen teslim edildi.' : 'Kalem kısmi teslim edildi.'),
            $isFull ? 'delivery_completed' : 'delivery_partially_delivered'
        );
    }

    public function markDelivered(
        OrderItemWorkFormDelivery $delivery,
        array|User|null $attributes = [],
        ?User $user = null,
        ?string $note = null
    ): OrderItemWorkFormDelivery
    {
        if ($attributes instanceof User) {
            $user = $attributes;
            $attributes = [];
        }

        $planned = round((float) $delivery->planned_quantity, 4);
        $eligibleLimit = $this->deliveryEligibleLimit($delivery);

        if ($planned - $eligibleLimit > 0.0001) {
            throw new \InvalidArgumentException($this->eligibleLimitErrorMessage($delivery, $eligibleLimit));
        }

        return $this->transition(
            $delivery,
            array_merge($this->normalizeDeliveryAttributes($attributes, $delivery, $delivery->remaining_quantity), [
                'delivered_quantity' => $planned,
                'remaining_quantity' => 0,
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]),
            'delivery_completed',
            $user,
            $note ?: 'Kalem teslim edildi.',
            'delivery_completed'
        );
    }

    public function markIssue(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_ISSUE,
                'issue_reported_at' => now(),
                'delivery_note' => $note ?: $delivery->delivery_note,
            ],
            'delivery_issue_reported',
            $user,
            $note ?: 'Teslimat sorunu bildirildi.',
            'delivery_problem_reported'
        );
    }

    public function cancel(OrderItemWorkFormDelivery $delivery, ?User $user = null, ?string $note = null): OrderItemWorkFormDelivery
    {
        return $this->transition(
            $delivery,
            [
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ],
            'delivery_cancelled',
            $user,
            $note ?: 'Teslimat kaydı iptal edildi.'
        );
    }

    private function transition(
        OrderItemWorkFormDelivery $delivery,
        array $changes,
        string $actionType,
        ?User $user,
        string $note,
        ?string $notificationEventKey = null
    ): OrderItemWorkFormDelivery {
        return DB::transaction(function () use ($delivery, $changes, $actionType, $user, $note, $notificationEventKey): OrderItemWorkFormDelivery {
            $oldStatus = $delivery->delivery_status;

            return $this->persistWithSnapshot(
                $delivery,
                $this->normalizeQuantities($delivery, $changes),
                $actionType,
                $oldStatus,
                $changes['delivery_status'] ?? $delivery->delivery_status,
                $note,
                $user,
                $notificationEventKey
            );
        });
    }

    private function normalizeQuantities(OrderItemWorkFormDelivery $delivery, array $changes): array
    {
        $planned = round((float) ($changes['planned_quantity'] ?? $delivery->planned_quantity), 4);
        $delivered = round((float) ($changes['delivered_quantity'] ?? $delivery->delivered_quantity), 4);

        if ($delivered - $planned > 0.0001) {
            throw new \InvalidArgumentException('Delivered quantity cannot exceed planned quantity.');
        }

        $changes['planned_quantity'] = $planned;
        $changes['delivered_quantity'] = $delivered;
        $changes['remaining_quantity'] = max(round($planned - $delivered, 4), 0.0);

        return $changes;
    }

    private function normalizeDeliveryAttributes(
        array $attributes,
        OrderItemWorkFormDelivery $delivery,
        float $deliveryQty
    ): array {
        $allowed = [
            'delivery_method',
            'carrier_name',
            'tracking_number',
            'recipient_name',
            'delivery_document_no',
            'recipient_phone',
            'package_count',
            'units_per_package',
            'packaged_quantity',
            'package_type',
            'package_note',
            'delivery_note',
        ];

        $changes = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            $changes[$key] = $value;
        }

        if ((!array_key_exists('packaged_quantity', $changes) || $changes['packaged_quantity'] === null)
            && (array_key_exists('package_count', $changes) || array_key_exists('units_per_package', $changes))) {
            $packageCount = (int) ($changes['package_count'] ?? $delivery->package_count ?? 0);
            $unitsPerPackage = (int) ($changes['units_per_package'] ?? $delivery->units_per_package ?? 0);
            if ($packageCount > 0 && $unitsPerPackage > 0) {
                $changes['packaged_quantity'] = $packageCount * $unitsPerPackage;
            }
        }

        if (!array_key_exists('packaged_quantity', $changes) && $deliveryQty > 0 && !array_key_exists('package_count', $changes)) {
            $changes['packaged_quantity'] = $delivery->packaged_quantity;
        }

        return $changes;
    }

    private function deliveryEligibleLimit(OrderItemWorkFormDelivery $delivery): float
    {
        $delivery->loadMissing([
            'workForm.orderItem',
            'workForm.printProductions',
            'workForm.procurement',
            'orderItem',
        ]);

        $planned = round((float) $delivery->planned_quantity, 4);
        $workForm = $delivery->workForm;
        $orderItem = $delivery->orderItem ?? $workForm?->orderItem;

        if ($orderItem?->has_print) {
            $productions = $workForm?->printProductions ?? collect();

            if ($productions->isNotEmpty()) {
                $completedFloor = $productions
                    ->map(fn ($production) => round((float) $production->completed_quantity, 4))
                    ->min();

                return max(min((float) $completedFloor, $planned), 0.0);
            }

            return max(min(
                round((float) data_get($workForm?->production_snapshot, 'completed_quantity', 0), 4),
                $planned
            ), 0.0);
        }

        $procurement = $workForm?->procurement;
        $procurementStatus = (string) ($procurement?->procurement_status
            ?? data_get($workForm?->procurement_snapshot, 'procurement_status', ''));
        $receivedQuantity = round((float) (
            $procurement?->received_quantity
            ?? data_get($workForm?->procurement_snapshot, 'received_quantity', 0)
        ), 4);

        return match ($procurementStatus) {
            OrderItemProcurement::STATUS_NOT_REQUIRED => $planned,
            OrderItemProcurement::STATUS_FULLY_RECEIVED,
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => $receivedQuantity > 0 ? min($receivedQuantity, $planned) : $planned,
            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => min($receivedQuantity, $planned),
            default => $this->fallbackEligibleForNoProcurement($orderItem?->has_print ?? true, $procurementStatus, $planned, $receivedQuantity),
        };
    }

    private function fallbackEligibleForNoProcurement(bool $hasPrint, string $procurementStatus, float $planned, float $receivedQuantity): float
    {
        if ($hasPrint) {
            return 0.0;
        }

        if ($procurementStatus === '') {
            return $planned;
        }

        return min($receivedQuantity, $planned);
    }

    private function eligibleLimitErrorMessage(OrderItemWorkFormDelivery $delivery, float $eligibleLimit): string
    {
        $delivery->loadMissing(['workForm.orderItem', 'workForm.procurement', 'workForm.printProductions', 'orderItem']);

        $orderItem = $delivery->orderItem ?? $delivery->workForm?->orderItem;

        if ($orderItem?->has_print) {
            return 'Üretim gereken kalemde teslim edilen adet, tamamlanan üretim miktarını aşamaz.';
        }

        $procurementStatus = (string) ($delivery->workForm?->procurement?->procurement_status
            ?? data_get($delivery->workForm?->procurement_snapshot, 'procurement_status', ''));

        if ($eligibleLimit <= 0.0001) {
            return match ($procurementStatus) {
                OrderItemProcurement::STATUS_PENDING,
                OrderItemProcurement::STATUS_REQUEST_CREATED,
                OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Tedarik hazır olmadan teslimat güncellenemez.',
                default => 'Teslim edilebilir hazır miktar bulunmuyor.',
            };
        }

        return 'Teslim edilen adet hazır olan miktarı aşamaz.';
    }

    private function persistWithSnapshot(
        OrderItemWorkFormDelivery $delivery,
        array $changes,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user,
        ?string $notificationEventKey = null
    ): OrderItemWorkFormDelivery {
        $delivery->fill($changes);
        $delivery->loadMissing(['workForm.attachments', 'order', 'orderItem']);

        $fullSnapshot = $this->dataBuilder->build($delivery->workForm, $delivery);

        $delivery->forceFill([
            ...$changes,
            'delivery_snapshot' => $fullSnapshot,
            'updated_by' => $user?->id,
        ])->save();

        $delivery = $delivery->fresh(['workForm.attachments', 'order', 'orderItem']);

        if ($delivery->workForm) {
            $this->syncWorkFormSnapshot($delivery->workForm, $delivery, $user);
            $this->createWorkflowLog($delivery, $actionType, $oldStatus, $newStatus, $note, $user);
        }

        $delivery = $delivery->fresh(['workForm', 'orderItem', 'order.customer.contacts', 'creator', 'updater']);

        if ($notificationEventKey) {
            $this->dispatchSafely($delivery, $notificationEventKey, $user, $note);
        }

        return $delivery;
    }

    private function syncWorkFormSnapshot(
        OrderItemWorkForm $workForm,
        OrderItemWorkFormDelivery $delivery,
        ?User $user
    ): void {
        $workForm->forceFill([
            'delivery_snapshot' => $this->dataBuilder->buildWorkFormSnapshot($delivery),
            'version' => (int) $workForm->version + 1,
            'updated_by' => $user?->id,
        ])->save();
    }

    private function createWorkflowLog(
        OrderItemWorkFormDelivery $delivery,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user
    ): void {
        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $delivery->tenant_account_id,
            'work_form_id' => $delivery->work_form_id,
            'order_id' => $delivery->order_id,
            'order_item_id' => $delivery->order_item_id,
            'action_type' => $actionType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'note' => $note,
            'visibility' => 'internal',
            'created_by' => $user?->id,
        ]);
    }

    private function dispatchSafely(
        OrderItemWorkFormDelivery $delivery,
        string $eventKey,
        ?User $user,
        ?string $note = null
    ): void {
        $tenant = $delivery->tenant;

        if (!$tenant) {
            return;
        }

        $context = [
            'status_label' => $delivery->safeStatusLabel(),
            'delivery_status' => $delivery->safeStatusLabel(),
            'delivery_method' => $delivery->safeDeliveryMethodLabel(),
            'tracking_number' => $delivery->tracking_number,
            'recipient_name' => $delivery->recipient_name,
            'delivered_quantity' => round((float) $delivery->delivered_quantity, 4),
            'remaining_quantity' => round((float) $delivery->remaining_quantity, 4),
            'package_count' => $delivery->package_count,
            'units_per_package' => $delivery->units_per_package,
            'internal_note' => filled($note) ? trim((string) $note) : null,
        ];

        try {
            $this->notificationEventService->dispatchEvent(
                $tenant,
                $eventKey,
                $delivery,
                [
                    'audience_type' => 'delivery_team',
                    'channels' => ['internal', 'email'],
                    'created_by' => $user,
                    'related_type' => $delivery->getMorphClass(),
                    'related_id' => $delivery->id,
                    'context' => $context,
                ]
            );

            if ($eventKey === 'delivery_completed') {
                $this->notificationEventService->dispatchEvent(
                    $tenant,
                    $eventKey,
                    $delivery,
                    [
                        'audience_type' => 'customer',
                        'channels' => ['email', 'whatsapp_link'],
                        'created_by' => $user,
                        'related_type' => $delivery->getMorphClass(),
                        'related_id' => $delivery->id,
                        'context' => $context,
                    ]
                );
            }
        } catch (\Throwable) {
            // Notification failures should never break the delivery workflow.
        }
    }
}
