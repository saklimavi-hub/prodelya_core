<?php

namespace App\Services;

use App\Models\GraphicApprovalRequest;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class GraphicApprovalRequestService
{
    public function __construct(
        protected NotificationEventService $notificationEventService,
        protected NotificationRecipientResolver $notificationRecipientResolver
    ) {
    }

    public function createRequest(
        OrderItemPrintGraphic $graphic,
        OrderItemWorkFormAttachment $attachment,
        array $data = [],
        ?User $user = null
    ): GraphicApprovalRequest {
        $graphic->loadMissing([
            'tenant',
            'order.customer.contacts',
            'orderItem',
            'orderItemPrint.tenantPrintSetting',
            'workForm.printGraphics.latestAttachment',
        ]);

        $this->assertAttachmentEligible($graphic, $attachment);

        $contact = $this->resolveCustomerContactPayload($graphic, $data);

        $request = DB::transaction(function () use ($graphic, $attachment, $data, $user, $contact) {
            $this->cancelOpenRequests($graphic, 'replaced_by_new_send', $user);

            $request = GraphicApprovalRequest::query()->create([
                'tenant_account_id' => $graphic->tenant_account_id,
                'order_id' => $graphic->order_id,
                'order_item_id' => $graphic->order_item_id,
                'order_item_print_id' => $graphic->order_item_print_id,
                'order_item_print_graphic_id' => $graphic->id,
                'work_form_id' => $graphic->order_item_work_form_id,
                'attachment_id' => $attachment->id,
                'customer_company_id' => $graphic->order?->customer_company_id,
                'contact_name' => $contact['name'],
                'contact_email' => $contact['email'],
                'contact_phone' => $contact['phone'],
                'token' => $this->generateUniqueToken(),
                'status' => GraphicApprovalRequest::STATUS_WAITING,
                'expires_at' => $data['expires_at'] ?? now()->addDays((int) ($data['expires_in_days'] ?? 7)),
                'created_by' => $user?->id,
                'meta_json' => [
                    'attachment_type' => $attachment->attachment_type,
                    'attachment_name' => $attachment->file_name,
                    'visibility' => $attachment->visibility,
                    'sequence_code' => $graphic->sequence_code,
                    'print_label' => $this->resolvePrintLabel($graphic),
                    'recipient_source' => $contact['source'],
                ],
            ]);

            $graphic->forceFill([
                'status' => OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING,
                'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING,
                'customer_note' => null,
                'updated_by' => $user?->id,
            ])->save();

            $this->syncLegacyGraphicSnapshot($graphic->workForm, $user?->id);

            return $request;
        });

        $freshRequest = $request->fresh([
            'tenant',
            'order.customer.contacts',
            'orderItem',
            'orderItemPrint.tenantPrintSetting',
            'graphic.order.customer.contacts',
            'graphic.workForm.delivery',
            'graphic.orderItem',
            'graphic.orderItemPrint.tenantPrintSetting',
            'attachment',
        ]);

        $this->dispatchSafely(
            $freshRequest,
            'graphic_customer_approval_requested',
            [
                'audience_type' => 'customer',
                'channels' => ['email', 'whatsapp_link'],
                'recipient_override' => [[
                    'type' => 'customer',
                    'name' => $freshRequest->contact_name,
                    'email' => $freshRequest->contact_email,
                    'phone' => $freshRequest->contact_phone,
                    'company_id' => $freshRequest->customer_company_id,
                ]],
                'created_by' => $user,
                'related_type' => $freshRequest->getMorphClass(),
                'related_id' => $freshRequest->id,
                'context' => [
                    'status_label' => $freshRequest->graphic?->safeStatusLabel(),
                    'print_label' => $this->resolvePrintLabel($freshRequest->graphic),
                    'public_graphic_approval_url' => $this->resolvePublicUrl($freshRequest),
                ],
            ]
        );

        return $freshRequest;
    }

    public function cancelOpenRequests(OrderItemPrintGraphic $graphic, string $reason, ?User $user = null): int
    {
        return GraphicApprovalRequest::query()
            ->where('tenant_account_id', $graphic->tenant_account_id)
            ->where('order_item_print_graphic_id', $graphic->id)
            ->whereIn('status', [
                GraphicApprovalRequest::STATUS_WAITING,
                GraphicApprovalRequest::STATUS_VIEWED,
            ])
            ->update([
                'status' => GraphicApprovalRequest::STATUS_CANCELLED,
                'responded_at' => now(),
                'cancelled_at' => now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function markViewed(GraphicApprovalRequest $request): GraphicApprovalRequest
    {
        $request->loadMissing('graphic.workForm.printGraphics.latestAttachment');

        if ($request->isCancelled()) {
            return $request->fresh();
        }

        if ($request->isExpired()) {
            if ($request->status !== GraphicApprovalRequest::STATUS_EXPIRED) {
                $request->forceFill([
                    'status' => GraphicApprovalRequest::STATUS_EXPIRED,
                ])->save();
            }

            return $request->fresh();
        }

        if ($request->isWaiting()) {
            $request->forceFill([
                'status' => GraphicApprovalRequest::STATUS_VIEWED,
                'viewed_at' => $request->viewed_at ?? now(),
            ])->save();
        }

        return $request->fresh();
    }

    public function approve(GraphicApprovalRequest $request, array $data = []): GraphicApprovalRequest
    {
        $request->loadMissing('graphic.workForm.printGraphics.latestAttachment');
        $this->guardRequestCanRespond($request);

        $approved = DB::transaction(function () use ($request, $data) {
            $request->forceFill([
                'status' => GraphicApprovalRequest::STATUS_APPROVED,
                'customer_note' => $this->sanitizeNote($data['customer_note'] ?? null),
                'viewed_at' => $request->viewed_at ?? now(),
                'responded_at' => now(),
                'approved_at' => now(),
            ])->save();

            $graphic = $request->graphic()->lockForUpdate()->firstOrFail();
            $graphic->forceFill([
                'status' => OrderItemPrintGraphic::STATUS_APPROVED,
                'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
                'approved_at' => $graphic->approved_at ?: now(),
                'updated_by' => $request->created_by,
            ])->save();

            $this->syncLegacyGraphicSnapshot($graphic->workForm, $request->created_by);

            return $request->fresh([
                'tenant',
                'graphic.order.customer.contacts',
                'graphic.orderItem',
                'graphic.workForm.delivery',
                'graphic.orderItemPrint.tenantPrintSetting',
            ]);
        });

        $this->dispatchSafely(
            $approved,
            'graphic_customer_approved',
            [
                'audience_type' => 'graphic_team',
                'channels' => ['internal', 'email'],
                'related_type' => $approved->getMorphClass(),
                'related_id' => $approved->id,
                'context' => [
                    'status_label' => $approved->graphic?->safeStatusLabel(),
                    'print_label' => $this->resolvePrintLabel($approved->graphic),
                ],
            ]
        );

        return $approved;
    }

    public function requestRevision(GraphicApprovalRequest $request, string $note): GraphicApprovalRequest
    {
        $request->loadMissing('graphic.workForm.printGraphics.latestAttachment');
        $this->guardRequestCanRespond($request);

        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Revize isteği için not gerekli.');
        }

        $revisioned = DB::transaction(function () use ($request, $note) {
            $request->forceFill([
                'status' => GraphicApprovalRequest::STATUS_REVISION_REQUESTED,
                'customer_note' => $note,
                'viewed_at' => $request->viewed_at ?? now(),
                'responded_at' => now(),
                'revision_requested_at' => now(),
            ])->save();

            $graphic = $request->graphic()->lockForUpdate()->firstOrFail();
            $graphic->forceFill([
                'status' => OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
                'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED,
                'customer_note' => $note,
                'revision_requested_at' => now(),
                'updated_by' => $request->created_by,
            ])->save();

            $this->syncLegacyGraphicSnapshot($graphic->workForm, $request->created_by);

            return $request->fresh([
                'tenant',
                'graphic.order.customer.contacts',
                'graphic.orderItem',
                'graphic.workForm.delivery',
                'graphic.orderItemPrint.tenantPrintSetting',
            ]);
        });

        $this->dispatchSafely(
            $revisioned,
            'graphic_revision_requested',
            [
                'audience_type' => 'graphic_team',
                'channels' => ['internal', 'email'],
                'related_type' => $revisioned->getMorphClass(),
                'related_id' => $revisioned->id,
                'context' => [
                    'status_label' => $revisioned->graphic?->safeStatusLabel(),
                    'print_label' => $this->resolvePrintLabel($revisioned->graphic),
                ],
            ]
        );

        return $revisioned;
    }

    public function findByToken(string $token): ?GraphicApprovalRequest
    {
        return GraphicApprovalRequest::query()
            ->with([
                'tenant',
                'order.customer.contacts',
                'orderItem',
                'orderItemPrint.tenantPrintSetting',
                'graphic.workForm.delivery',
                'attachment',
            ])
            ->where('token', $token)
            ->first();
    }

    public function resolvePublicUrl(GraphicApprovalRequest $request): ?string
    {
        foreach ([
            'public.graphics.approval.show',
            'public.graphic-approvals.show',
            'public.graphic-approval.show',
        ] as $routeName) {
            if (Route::has($routeName)) {
                return route($routeName, ['token' => $request->token]);
            }
        }

        return null;
    }

    public function assertAttachmentEligible(OrderItemPrintGraphic $graphic, OrderItemWorkFormAttachment $attachment): void
    {
        if ($graphic->tenant_account_id !== $attachment->tenant_account_id
            || $graphic->order_id !== $attachment->order_id
            || $graphic->order_item_id !== $attachment->order_item_id
            || (int) $graphic->order_item_work_form_id !== (int) $attachment->work_form_id
            || (int) $graphic->id !== (int) $attachment->order_item_print_graphic_id
        ) {
            throw new InvalidArgumentException('Grafik görseli seçilen operasyon ile eşleşmiyor.');
        }

        if ($graphic->order_item_print_id && (int) $graphic->order_item_print_id !== (int) $attachment->order_item_print_id) {
            throw new InvalidArgumentException('Grafik görseli seçilen baskı operasyonu ile eşleşmiyor.');
        }

        if (! $attachment->isCustomerVisible()) {
            throw new InvalidArgumentException('Müşteri onayına gönderilecek görsel müşteri görünür olmalıdır.');
        }

        if (!in_array($attachment->attachment_type, ['graphic_visual', 'customer_approval'], true)) {
            throw new InvalidArgumentException('Müşteri onayına yalnız grafik görseli gönderilebilir.');
        }
    }

    private function guardRequestCanRespond(GraphicApprovalRequest $request): void
    {
        if ($request->isCancelled()) {
            throw new RuntimeException('İptal edilen grafik onay isteği yanıtlanamaz.');
        }

        if ($request->isExpired()) {
            if ($request->status !== GraphicApprovalRequest::STATUS_EXPIRED) {
                $request->forceFill(['status' => GraphicApprovalRequest::STATUS_EXPIRED])->save();
            }

            throw new RuntimeException('Süresi dolan grafik onay isteği yanıtlanamaz.');
        }

        if (!in_array($request->status, [
            GraphicApprovalRequest::STATUS_WAITING,
            GraphicApprovalRequest::STATUS_VIEWED,
        ], true)) {
            throw new RuntimeException('Bu grafik onay isteği artık yanıtlanamaz.');
        }
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (GraphicApprovalRequest::query()->where('token', $token)->exists());

        return $token;
    }

    private function resolveCustomerContactPayload(OrderItemPrintGraphic $graphic, array $data): array
    {
        $graphic->loadMissing(['order.customer.contacts', 'workForm']);

        $resolvedRecipient = collect($this->notificationRecipientResolver->resolveCustomerRecipients($graphic->order ?: $graphic))->first() ?? [];
        $customer = $graphic->order?->customer;
        $contacts = $customer?->relationLoaded('contacts') ? $customer->contacts : collect();
        $primaryContact = $customer?->getPrimaryContact();
        $firstContact = $contacts instanceof Collection ? $contacts->first() : null;
        $snapshot = (array) ($graphic->workForm?->customer_snapshot ?? []);

        $name = $this->firstFilledValue([
            $data['contact_name'] ?? null,
            $primaryContact?->name,
            $firstContact?->name,
            $customer?->legal_name,
            data_get($snapshot, 'contact_name'),
            data_get($snapshot, 'company_name'),
            $resolvedRecipient['name'] ?? null,
        ]);

        $email = $this->normalizeEmail($this->firstFilledValue([
            $data['contact_email'] ?? null,
            $primaryContact?->email,
            $firstContact?->email,
            $customer?->email,
            data_get($snapshot, 'contact_email'),
            data_get($snapshot, 'email'),
            $resolvedRecipient['email'] ?? null,
        ]));

        $phone = $this->normalizePhone($this->firstFilledValue([
            $data['contact_phone'] ?? null,
            $primaryContact?->mobile,
            $primaryContact?->phone,
            $firstContact?->mobile,
            $firstContact?->phone,
            $customer?->mobile,
            $customer?->phone,
            data_get($snapshot, 'contact_phone'),
            data_get($snapshot, 'phone'),
            $resolvedRecipient['phone'] ?? null,
        ]));

        $source = 'none';

        if (filled($data['contact_email'] ?? null) || filled($data['contact_phone'] ?? null) || filled($data['contact_name'] ?? null)) {
            $source = 'request_override';
        } elseif ($email !== null && $email === $this->normalizeEmail($primaryContact?->email)) {
            $source = 'company_primary_contact';
        } elseif ($email !== null && $email === $this->normalizeEmail($firstContact?->email)) {
            $source = 'company_contact';
        } elseif ($email !== null && $email === $this->normalizeEmail($customer?->email)) {
            $source = 'company_record';
        } elseif ($email !== null && $email === $this->normalizeEmail(data_get($snapshot, 'contact_email'))) {
            $source = 'work_form_customer_snapshot';
        } elseif ($email !== null && $email === $this->normalizeEmail(data_get($snapshot, 'email'))) {
            $source = 'work_form_customer_snapshot';
        } elseif ($phone !== null && ($phone === $this->normalizePhone($primaryContact?->mobile) || $phone === $this->normalizePhone($primaryContact?->phone))) {
            $source = 'company_primary_contact';
        } elseif ($phone !== null && ($phone === $this->normalizePhone($firstContact?->mobile) || $phone === $this->normalizePhone($firstContact?->phone))) {
            $source = 'company_contact';
        } elseif ($phone !== null && ($phone === $this->normalizePhone($customer?->mobile) || $phone === $this->normalizePhone($customer?->phone))) {
            $source = 'company_record';
        } elseif ($phone !== null && ($phone === $this->normalizePhone(data_get($snapshot, 'contact_phone')) || $phone === $this->normalizePhone(data_get($snapshot, 'phone')))) {
            $source = 'work_form_customer_snapshot';
        } elseif (filled($resolvedRecipient['email'] ?? null) || filled($resolvedRecipient['phone'] ?? null)) {
            $source = 'notification_recipient_resolver';
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'source' => $source,
        ];
    }

    private function firstFilledValue(array $values): ?string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));

            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $value = strtolower(trim((string) ($email ?? '')));

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $value = trim((string) ($phone ?? ''));

        return $value === '' ? null : $value;
    }

    private function sanitizeNote(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function dispatchSafely(GraphicApprovalRequest $request, string $eventKey, array $options = []): void
    {
        $tenant = $request->tenant ?: $request->graphic?->tenant;
        $graphic = $request->graphic;

        if (!$tenant || !$graphic) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent($tenant, $eventKey, $graphic, $options);
        } catch (\Throwable) {
            // Graphic approval workflow must not fail because of notifications.
        }
    }

    private function resolvePrintLabel(?OrderItemPrintGraphic $graphic): ?string
    {
        if (!$graphic) {
            return null;
        }

        $print = $graphic->orderItemPrint;
        $parts = array_filter([
            $graphic->sequence_code,
            $print?->displayPrintType(),
            $print?->print_option,
        ], fn ($value) => filled($value));

        return empty($parts) ? null : implode(' ', $parts);
    }

    private function syncLegacyGraphicSnapshot(?OrderItemWorkForm $workForm, ?int $userId = null): void
    {
        if (!$workForm) {
            return;
        }

        $workForm = $workForm->fresh(['printGraphics.latestAttachment']) ?: $workForm;

        $graphics = $workForm->printGraphics
            ->filter(fn (OrderItemPrintGraphic $graphic) => $graphic->status !== OrderItemPrintGraphic::STATUS_NOT_REQUIRED)
            ->values();

        $snapshot = is_array($workForm->graphic_snapshot) ? $workForm->graphic_snapshot : [];

        if ($graphics->isEmpty()) {
            $snapshot['status'] = 'gerekli_degil';
            $snapshot['approval_status'] = 'gerekli_degil';
        } elseif ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)) {
            $snapshot['status'] = 'revize_istendi';
            $snapshot['approval_status'] = 'revize_istendi';
        } elseif ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)) {
            $snapshot['status'] = 'musteri_onayi_bekliyor';
            $snapshot['approval_status'] = 'onay_bekliyor';
        } elseif ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL)) {
            $snapshot['status'] = 'bekliyor';
            $snapshot['approval_status'] = 'onay_gerekmiyor';
        } elseif ($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY)) {
            $snapshot['status'] = 'uretime_hazir';
            $snapshot['approval_status'] = 'onaylandi';
        } elseif ($graphics->every(fn (OrderItemPrintGraphic $graphic) => in_array($graphic->status, [
            OrderItemPrintGraphic::STATUS_APPROVED,
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
        ], true))) {
            $snapshot['status'] = 'onaylandi';
            $snapshot['approval_status'] = 'onaylandi';
        } else {
            $snapshot['status'] = 'gorsel_eklendi';
            $snapshot['approval_status'] = 'onay_gerekmiyor';
        }

        $primaryAttachmentId = $graphics
            ->map(fn (OrderItemPrintGraphic $graphic) => $graphic->latestAttachment)
            ->filter()
            ->sortByDesc('id')
            ->first()?->id;

        $snapshot['primary_visual_attachment_id'] = $primaryAttachmentId;
        $snapshot['updated_at'] = Carbon::now()->toAtomString();

        $workForm->forceFill([
            'graphic_snapshot' => $snapshot,
            'updated_by' => $userId,
        ])->save();
    }
}
