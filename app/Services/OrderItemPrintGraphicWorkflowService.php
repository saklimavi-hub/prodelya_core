<?php

namespace App\Services;

use App\Models\OrderItemPrintGraphic;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class OrderItemPrintGraphicWorkflowService
{
    public function __construct(
        protected NotificationEventService $notificationEventService
    ) {
    }

    public function markVisualUploaded(OrderItemPrintGraphic $graphic, ?User $user = null): OrderItemPrintGraphic
    {
        $graphic->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            'updated_by' => $user?->id,
        ])->save();

        return $graphic->fresh();
    }

    public function markApproved(OrderItemPrintGraphic $graphic, ?User $user = null): OrderItemPrintGraphic
    {
        $graphic->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_APPROVED,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
            'approved_at' => Carbon::now(),
            'updated_by' => $user?->id,
        ])->save();

        return $graphic->fresh();
    }

    public function requestRevision(OrderItemPrintGraphic $graphic, string $note, ?User $user = null): OrderItemPrintGraphic
    {
        $graphic->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED,
            'customer_note' => trim($note),
            'revision_requested_at' => Carbon::now(),
            'updated_by' => $user?->id,
        ])->save();

        $freshGraphic = $graphic->fresh([
            'tenant',
            'order.customer.contacts',
            'orderItem',
            'workForm.delivery',
            'orderItemPrint.tenantPrintSetting',
            'updater',
            'creator',
        ]);

        $this->dispatchSafely($freshGraphic, 'graphic_revision_requested', [
            'audience_type' => 'graphic_team',
            'channels' => ['internal', 'email'],
            'created_by' => $user,
            'context' => [
                'status_label' => $freshGraphic->safeStatusLabel(),
            ],
        ]);

        return $freshGraphic;
    }

    public function markProductionReady(OrderItemPrintGraphic $graphic, ?User $user = null): OrderItemPrintGraphic
    {
        if (!$graphic->canMarkProductionReady()) {
            throw new InvalidArgumentException('Revize istenen veya onaylanmamış grafik operasyonu üretime hazır yapılamaz.');
        }

        if (!$graphic->latest_attachment_id) {
            throw new InvalidArgumentException('Final grafik görseli olmadan üretime hazırlanamaz.');
        }

        $updates = [
            'status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            'production_ready_at' => Carbon::now(),
            'updated_by' => $user?->id,
        ];

        if ($graphic->approved_at === null && in_array($graphic->status, [
            OrderItemPrintGraphic::STATUS_APPROVED,
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
        ], true)) {
            $updates['approved_at'] = Carbon::now();
        }

        $graphic->forceFill($updates)->save();

        $freshGraphic = $graphic->fresh([
            'tenant',
            'order.customer.contacts',
            'orderItem',
            'workForm.delivery',
            'orderItemPrint.tenantPrintSetting',
            'updater',
            'creator',
        ]);

        $this->dispatchSafely($freshGraphic, 'graphic_production_ready', [
            'audience_type' => 'production_team',
            'channels' => ['internal', 'email'],
            'created_by' => $user,
            'context' => [
                'status_label' => $freshGraphic->safeStatusLabel(),
            ],
        ]);

        return $freshGraphic;
    }

    private function dispatchSafely(OrderItemPrintGraphic $graphic, string $eventKey, array $options = []): void
    {
        $tenant = $graphic->tenant;

        if (!$tenant) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent($tenant, $eventKey, $graphic, $options);
        } catch (\Throwable) {
            // Grafik workflow'u notification hatası nedeniyle bozulmamalı.
        }
    }
}
