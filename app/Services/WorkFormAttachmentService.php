<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkFormAttachmentService
{
    private const DISK = 'public';

    private const ALLOWED_TYPES = [
        'graphic_visual',
        'customer_approval',
        'production_photo',
        'delivery_photo',
        'delivery_document',
        'other',
    ];

    public function __construct(
        protected NotificationEventService $notificationEventService
    ) {
    }

    public function store(
        OrderItemWorkForm $workForm,
        UploadedFile $file,
        string $attachmentType,
        ?string $note = null,
        ?string $visibility = null,
        ?User $user = null
    ): OrderItemWorkFormAttachment {
        return $this->storeAttachment($workForm, $file, $attachmentType, $note, $visibility, $user);
    }

    public function attachGraphicVisualToPrintGraphic(
        OrderItemPrintGraphic $graphic,
        UploadedFile $file,
        array $meta = [],
        ?User $user = null
    ): OrderItemWorkFormAttachment {
        $attachment = $this->storeForPrintGraphic($graphic, $file, 'graphic_visual', $meta, $user);

        $this->dispatchGraphicNotificationSafely(
            $graphic->fresh([
                'tenant',
                'order.customer.contacts',
                'orderItem',
                'workForm.delivery',
                'orderItemPrint.tenantPrintSetting',
                'updater',
                'creator',
            ]),
            'graphic_visual_uploaded',
            [
                'audience_type' => 'production_team',
                'channels' => ['internal', 'email'],
                'created_by' => $user,
                'context' => [
                    'status_label' => 'Grafik görseli yüklendi',
                ],
            ]
        );

        return $attachment;
    }

    public function attachCustomerApprovalToPrintGraphic(
        OrderItemPrintGraphic $graphic,
        UploadedFile $file,
        array $meta = [],
        ?User $user = null
    ): OrderItemWorkFormAttachment {
        return $this->storeForPrintGraphic($graphic, $file, 'customer_approval', $meta, $user);
    }

    public function updateLatestAttachment(OrderItemPrintGraphic $graphic, OrderItemWorkFormAttachment $attachment, ?User $user = null): OrderItemPrintGraphic
    {
        $this->guardGraphicAttachmentAssociation($graphic, $attachment);

        $updates = [
            'latest_attachment_id' => $attachment->id,
            'updated_by' => $user?->id ?? $attachment->uploaded_by,
        ];

        if ($attachment->attachment_type === 'graphic_visual' && $graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL) {
            $updates['status'] = OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED;
        }

        $graphic->forceFill($updates)->save();

        return $graphic->fresh();
    }

    private function storeForPrintGraphic(
        OrderItemPrintGraphic $graphic,
        UploadedFile $file,
        string $attachmentType,
        array $meta = [],
        ?User $user = null
    ): OrderItemWorkFormAttachment {
        $graphic->load([
            'workForm',
            'orderItemPrint',
        ]);

        $workForm = $graphic->workForm;

        if (!$workForm) {
            throw new \InvalidArgumentException('Grafik operasyonuna bağlı aktif bir iş formu bulunamadı.');
        }

        if ($graphic->tenant_account_id !== $workForm->tenant_account_id
            || $graphic->order_id !== $workForm->order_id
            || $graphic->order_item_id !== $workForm->order_item_id
            || (int) $graphic->order_item_work_form_id !== (int) $workForm->id
            || !$graphic->order_item_print_id
        ) {
            throw new \InvalidArgumentException('Grafik operasyonu ile iş formu eşleşmiyor.');
        }

        return $this->storeAttachment(
            $workForm,
            $file,
            $attachmentType,
            $meta['note'] ?? null,
            $meta['visibility'] ?? 'internal',
            $user,
            $graphic,
            true
        );
    }

    private function storeAttachment(
        OrderItemWorkForm $workForm,
        UploadedFile $file,
        string $attachmentType,
        ?string $note = null,
        ?string $visibility = null,
        ?User $user = null,
        ?OrderItemPrintGraphic $graphic = null,
        bool $logPrintGraphicContext = false
    ): OrderItemWorkFormAttachment {
        if (!in_array($attachmentType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported attachment type.');
        }

        $visibility = $visibility === 'customer_visible' ? 'customer_visible' : 'internal';
        $sanitizedFileName = $this->buildStoredFileName($file);
        $directory = sprintf(
            'work-forms/%d/%d/%d',
            $workForm->tenant_account_id,
            $workForm->order_id,
            $workForm->id
        );

        return DB::transaction(function () use ($workForm, $file, $attachmentType, $note, $visibility, $user, $directory, $sanitizedFileName, $graphic, $logPrintGraphicContext) {
            $storedPath = $file->storeAs($directory, $sanitizedFileName, self::DISK);

            $attachment = OrderItemWorkFormAttachment::create([
                'tenant_account_id' => $workForm->tenant_account_id,
                'work_form_id' => $workForm->id,
                'order_id' => $workForm->order_id,
                'order_item_id' => $workForm->order_item_id,
                'order_item_print_graphic_id' => $graphic?->id,
                'order_item_print_id' => $graphic?->order_item_print_id,
                'attachment_type' => $attachmentType,
                'visibility' => $visibility,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'disk' => self::DISK,
                'uploaded_by' => $user?->id,
                'note' => $note,
                'sort_order' => $this->nextSortOrder($workForm, $attachmentType),
            ]);

            $this->updateWorkFormSnapshots($workForm, $attachment);
            if ($graphic) {
                $this->updateLatestAttachment($graphic, $attachment, $user);
            }
            $this->logAttachmentActivity($workForm, $attachment, $note, $user, $graphic, $logPrintGraphicContext);
            $this->dispatchAttachmentNotificationSafely($workForm, $attachment, $user, $note);

            return $attachment->fresh();
        });
    }

    public function destroy(OrderItemWorkForm $workForm, OrderItemWorkFormAttachment $attachment): void
    {
        if (
            $attachment->work_form_id !== $workForm->id
            || $attachment->tenant_account_id !== $workForm->tenant_account_id
        ) {
            throw new \InvalidArgumentException('Attachment does not belong to the given work form.');
        }

        DB::transaction(function () use ($attachment): void {
            if ($attachment->file_path && $attachment->disk) {
                Storage::disk($attachment->disk)->delete($attachment->file_path);
            }

            $attachment->delete();
        });
    }

    public function createInitialActivityLog(OrderItemWorkForm $workForm, ?User $user = null): void
    {
        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'work_form_created',
            'note' => 'İş Formu oluşturuldu.',
            'visibility' => 'internal',
            'created_by' => $user?->id,
        ]);
    }

    private function updateWorkFormSnapshots(OrderItemWorkForm $workForm, OrderItemWorkFormAttachment $attachment): void
    {
        $now = now()->toAtomString();
        $graphicSnapshot = is_array($workForm->graphic_snapshot) ? $workForm->graphic_snapshot : [];
        $productionSnapshot = is_array($workForm->production_snapshot) ? $workForm->production_snapshot : [];
        $deliverySnapshot = is_array($workForm->delivery_snapshot) ? $workForm->delivery_snapshot : [];

        if ($attachment->attachment_type === 'graphic_visual') {
            $graphicSnapshot['primary_visual_attachment_id'] = $attachment->id;
            $graphicSnapshot['updated_at'] = $now;
            $currentStatus = (string) ($graphicSnapshot['status'] ?? '');
            if ($currentStatus === '' || $currentStatus === 'bekliyor') {
                $graphicSnapshot['status'] = 'gorsel_eklendi';
            }
        }

        if ($attachment->attachment_type === 'customer_approval') {
            $graphicSnapshot['updated_at'] = $now;
            $graphicSnapshot['approval_status'] = $graphicSnapshot['approval_status'] ?? 'bekliyor';
        }

        if ($attachment->attachment_type === 'production_photo') {
            $productionSnapshot['updated_at'] = $now;
            $productionSnapshot['photo_count'] = (int) ($productionSnapshot['photo_count'] ?? 0) + 1;
        }

        if (in_array($attachment->attachment_type, ['delivery_photo', 'delivery_document'], true)) {
            $deliverySnapshot['updated_at'] = $now;
            if ($attachment->attachment_type === 'delivery_photo') {
                $deliverySnapshot['photo_count'] = (int) ($deliverySnapshot['photo_count'] ?? 0) + 1;
            }
            if ($attachment->attachment_type === 'delivery_document') {
                $deliverySnapshot['document_count'] = (int) ($deliverySnapshot['document_count'] ?? 0) + 1;
            }
        }

        $workForm->forceFill([
            'graphic_snapshot' => $graphicSnapshot,
            'production_snapshot' => $productionSnapshot,
            'delivery_snapshot' => $deliverySnapshot,
            'version' => (int) $workForm->version + 1,
            'updated_by' => $attachment->uploaded_by,
        ])->save();

        if (in_array($attachment->attachment_type, ['delivery_photo', 'delivery_document'], true)) {
            $this->syncDeliveryRecordSnapshot($workForm, $deliverySnapshot, $attachment->uploaded_by);
        }
    }

    private function logAttachmentActivity(
        OrderItemWorkForm $workForm,
        OrderItemWorkFormAttachment $attachment,
        ?string $note,
        ?User $user,
        ?OrderItemPrintGraphic $graphic = null,
        bool $logPrintGraphicContext = false
    ): void {
        $activityNote = $note;

        if ($graphic && $logPrintGraphicContext) {
            $prefix = trim(($graphic->sequence_code ?: 'Grafik operasyonu') . ' baskı operasyonuna grafik görseli eklendi');
            $activityNote = $note ? $prefix . ' — ' . $note : $prefix;
        }

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => $this->resolveActionType($attachment->attachment_type),
            'note' => $activityNote,
            'attachment_id' => $attachment->id,
            'visibility' => $attachment->visibility,
            'created_by' => $user?->id,
        ]);
    }

    private function resolveActionType(string $attachmentType): string
    {
        return match ($attachmentType) {
            'graphic_visual' => 'graphic_visual_added',
            'customer_approval' => 'customer_approval_added',
            'production_photo' => 'production_photo_added',
            'delivery_photo' => 'delivery_photo_added',
            'delivery_document' => 'delivery_document_added',
            default => 'attachment_added',
        };
    }

    private function buildStoredFileName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseName = Str::of($baseName)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9\-_\s]/', '')
            ->replace(' ', '-')
            ->trim('-_')
            ->limit(40, '')
            ->value();

        if ($baseName === '') {
            $baseName = 'dosya';
        }

        return $baseName . '-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(8)) . '.' . $extension;
    }

    private function nextSortOrder(OrderItemWorkForm $workForm, string $attachmentType): int
    {
        return (int) $workForm->attachments()
            ->where('attachment_type', $attachmentType)
            ->max('sort_order') + 1;
    }

    private function guardGraphicAttachmentAssociation(OrderItemPrintGraphic $graphic, OrderItemWorkFormAttachment $attachment): void
    {
        if ($graphic->tenant_account_id !== $attachment->tenant_account_id
            || $graphic->order_id !== $attachment->order_id
            || $graphic->order_item_id !== $attachment->order_item_id
            || (int) $graphic->order_item_work_form_id !== (int) $attachment->work_form_id
        ) {
            throw new \InvalidArgumentException('Attachment, grafik operasyonu ile aynı iş bağlamında değil.');
        }
    }

    private function syncDeliveryRecordSnapshot(OrderItemWorkForm $workForm, array $deliverySnapshot, ?int $updatedBy): void
    {
        $workForm->loadMissing('delivery');

        /** @var OrderItemWorkFormDelivery|null $delivery */
        $delivery = $workForm->delivery;

        if (!$delivery) {
            return;
        }

        $recordSnapshot = is_array($delivery->delivery_snapshot) ? $delivery->delivery_snapshot : [];

        $delivery->forceFill([
            'delivery_snapshot' => array_merge($recordSnapshot, $deliverySnapshot),
            'updated_by' => $updatedBy,
        ])->save();
    }

    private function dispatchGraphicNotificationSafely(
        OrderItemPrintGraphic $graphic,
        string $eventKey,
        array $options = []
    ): void {
        $tenant = $graphic->tenant;

        if (!$tenant) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent($tenant, $eventKey, $graphic, $options);
        } catch (\Throwable) {
            // Grafik dosya yükleme akışı notification hatası nedeniyle rollback edilmemeli.
        }
    }

    private function dispatchAttachmentNotificationSafely(
        OrderItemWorkForm $workForm,
        OrderItemWorkFormAttachment $attachment,
        ?User $user,
        ?string $note = null
    ): void {
        if (!in_array($attachment->attachment_type, ['delivery_photo', 'delivery_document'], true)) {
            return;
        }

        $workForm->loadMissing([
            'tenant',
            'delivery.order.customer.contacts',
            'delivery.orderItem',
        ]);

        $delivery = $workForm->delivery;
        $tenant = $workForm->tenant;

        if (!$delivery || !$tenant) {
            return;
        }

        $eventKey = 'delivery_document_uploaded';
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
            'public_tracking_url' => $workForm->public_tracking_token
                ? route('public.work-forms.track', $workForm->public_tracking_token)
                : null,
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
                    'related_type' => $attachment->getMorphClass(),
                    'related_id' => $attachment->id,
                    'context' => $context,
                ]
            );

            if ($attachment->isCustomerVisible()) {
                $this->notificationEventService->dispatchEvent(
                    $tenant,
                    $eventKey,
                    $delivery,
                    [
                        'audience_type' => 'customer',
                        'channels' => ['email', 'whatsapp_link'],
                        'created_by' => $user,
                        'related_type' => $attachment->getMorphClass(),
                        'related_id' => $attachment->id,
                        'context' => $context,
                    ]
                );
            }
        } catch (\Throwable) {
            // Teslimat belge/fotoğraf yükleme akışı notification hatası nedeniyle rollback edilmemeli.
        }
    }
}
