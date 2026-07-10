<?php

namespace App\Services;

use App\Models\Order;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteSendSnapshot;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\TenantSmtpMailerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

class QuoteApprovalService
{
    public function __construct(
        protected QuoteSendSnapshotBuilder $snapshotBuilder,
        protected NotificationEventService $notificationEventService,
        protected TenantSmtpMailerService $tenantSmtpMailerService,
    ) {
    }

    public function sendToCustomer(Order $quote, array $recipientData, ?User $user = null): QuoteApprovalRequest
    {
        $this->guardQuoteCanBeSent($quote);
        $forceEmailPreview = (bool) ($recipientData['force_email_preview'] ?? false);
        $skipEmailSend = (bool) ($recipientData['skip_email_send'] ?? false);
        $skipWhatsappDispatch = (bool) ($recipientData['skip_whatsapp_dispatch'] ?? false);

        $request = DB::transaction(function () use ($quote, $recipientData, $user) {
            $quote->loadMissing('items.prints', 'customer');

            $this->cancelOpenRequests($quote, 'replaced_by_new_send');

            $snapshot = $this->createSendSnapshot($quote, $recipientData, $user);
            $request = $this->createApprovalRequest($quote, $snapshot, $recipientData, $user);

            $quote->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK,
                'last_sent_at' => now(),
                'approved_at' => null,
                'rejected_at' => null,
                'revision_requested_at' => null,
                'status' => 'pending',
            ])->save();

            return $request;
        });

        $freshQuote = $quote->fresh(['customer.contacts', 'items', 'workForms', 'tenant']);
        $publicQuoteUrl = $this->resolvePublicQuoteUrl($request) ?? '';

        if ($publicQuoteUrl !== '' && ! $skipEmailSend && (filled($recipientData['contact_email'] ?? null) || $forceEmailPreview)) {
            $emailLog = $this->tenantSmtpMailerService->sendQuoteApprovalMail(
                $freshQuote->tenant,
                $freshQuote,
                $recipientData,
                $publicQuoteUrl,
                $user,
                $forceEmailPreview
            );

            if ($emailLog->status === \App\Models\NotificationLog::STATUS_FAILED) {
                if (! $skipWhatsappDispatch) {
                    $this->dispatchSafely(
                        $freshQuote,
                        'quote_sent_to_customer',
                        [
                            'audience_type' => 'customer',
                            'channels' => ['whatsapp_link'],
                            'recipient_override' => [[
                                'type' => 'customer',
                                'name' => $recipientData['contact_name'] ?? ($freshQuote->customer?->legal_name ?: null),
                                'email' => $recipientData['contact_email'] ?? null,
                                'phone' => $recipientData['contact_phone'] ?? null,
                                'company_id' => $freshQuote->customer_company_id,
                            ]],
                            'created_by' => $user,
                            'related_type' => $freshQuote->getMorphClass(),
                            'related_id' => $freshQuote->id,
                            'context' => [
                                'public_quote_url' => $publicQuoteUrl,
                                'status_label' => $freshQuote->safeCustomerApprovalStatusLabel(),
                            ],
                        ]
                    );
                }

                $this->dispatchSafely(
                    $freshQuote,
                    'quote_sent_to_customer',
                    [
                        'audience_type' => 'tenant_admin',
                        'channels' => ['internal'],
                        'created_by' => $user,
                        'related_type' => $freshQuote->getMorphClass(),
                        'related_id' => $freshQuote->id,
                        'context' => [
                            'status_label' => $freshQuote->safeCustomerApprovalStatusLabel(),
                        ],
                    ]
                );

                throw new RuntimeException('Teklif kaydı oluşturuldu ancak e-posta gönderilemedi. SMTP ayarlarını veya müşteri e-posta adresini kontrol edin.');
            }
        }

        if (! $skipWhatsappDispatch) {
            $this->dispatchSafely(
                $freshQuote,
                'quote_sent_to_customer',
                [
                    'audience_type' => 'customer',
                    'channels' => ['whatsapp_link'],
                    'recipient_override' => [[
                        'type' => 'customer',
                        'name' => $recipientData['contact_name'] ?? ($freshQuote->customer?->legal_name ?: null),
                        'email' => $recipientData['contact_email'] ?? null,
                        'phone' => $recipientData['contact_phone'] ?? null,
                        'company_id' => $freshQuote->customer_company_id,
                    ]],
                    'created_by' => $user,
                    'related_type' => $freshQuote->getMorphClass(),
                    'related_id' => $freshQuote->id,
                    'context' => [
                        'public_quote_url' => $publicQuoteUrl,
                        'status_label' => $freshQuote->safeCustomerApprovalStatusLabel(),
                    ],
                ]
            );
        }

        $this->dispatchSafely(
            $freshQuote,
            'quote_sent_to_customer',
            [
                'audience_type' => 'tenant_admin',
                'channels' => ['internal'],
                'created_by' => $user,
                'related_type' => $freshQuote->getMorphClass(),
                'related_id' => $freshQuote->id,
                'context' => [
                    'status_label' => $freshQuote->safeCustomerApprovalStatusLabel(),
                ],
            ]
        );

        return $request->fresh();
    }

    public function createSendSnapshot(Order $quote, array $meta = [], ?User $user = null): QuoteSendSnapshot
    {
        $quote->loadMissing('customer', 'items.prints');

        $snapshotData = $this->snapshotBuilder->build($quote);
        $nextSendNo = (int) $quote->quoteSendSnapshots()->max('send_no') + 1;

        return QuoteSendSnapshot::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'quote_id' => $quote->id,
            'send_no' => $nextSendNo,
            'snapshot_json' => $snapshotData,
            'summary_json' => [
                'quote_number' => data_get($snapshotData, 'quote_number'),
                'customer_name' => data_get($snapshotData, 'customer.name'),
                'item_count' => count((array) data_get($snapshotData, 'items', [])),
                'grand_total' => data_get($snapshotData, 'totals.grand_total'),
                'currency' => data_get($snapshotData, 'currency'),
            ],
            'financial_snapshot_json' => (array) data_get($snapshotData, 'totals', []),
            'sent_channel' => $meta['sent_channel'] ?? null,
            'sent_to_name' => $meta['sent_to_name'] ?? $meta['contact_name'] ?? null,
            'sent_to_email' => $meta['sent_to_email'] ?? $meta['contact_email'] ?? null,
            'sent_to_phone' => $meta['sent_to_phone'] ?? $meta['contact_phone'] ?? null,
            'sent_at' => $meta['sent_at'] ?? now(),
            'created_by' => $user?->id,
        ]);
    }

    public function createApprovalRequest(Order $quote, QuoteSendSnapshot $snapshot, array $recipientData, ?User $user = null): QuoteApprovalRequest
    {
        return QuoteApprovalRequest::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'quote_id' => $quote->id,
            'quote_send_snapshot_id' => $snapshot->id,
            'customer_company_id' => $quote->customer_company_id,
            'contact_name' => $recipientData['contact_name'] ?? null,
            'contact_email' => $recipientData['contact_email'] ?? null,
            'contact_phone' => $recipientData['contact_phone'] ?? null,
            'token' => $this->generateUniqueToken(),
            'status' => QuoteApprovalRequest::STATUS_WAITING,
            'expires_at' => $recipientData['expires_at'] ?? now()->addDays((int) ($recipientData['expires_in_days'] ?? 7)),
            'created_by' => $user?->id,
        ]);
    }

    public function markViewed(QuoteApprovalRequest $request): QuoteApprovalRequest
    {
        $wasWaiting = $request->isWaiting();

        if ($request->isExpired()) {
            $request->forceFill(['status' => QuoteApprovalRequest::STATUS_EXPIRED])->save();
            throw new RuntimeException('Süresi dolan istek görüntülenemez.');
        }

        if ($request->isCancelled()) {
            throw new RuntimeException('İptal edilen istek görüntülenemez.');
        }

        if ($request->isWaiting()) {
            $request->forceFill([
                'status' => QuoteApprovalRequest::STATUS_VIEWED,
                'viewed_at' => $request->viewed_at ?? now(),
            ])->save();

            $request->quote?->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK,
            ])->save();
        }

        $fresh = $request->fresh(['quote.customer.contacts', 'quote.items', 'quote.workForms']);

        if ($wasWaiting) {
            $this->dispatchSafely(
                $fresh->quote,
                'quote_customer_viewed',
                [
                    'audience_type' => 'tenant_admin',
                    'channels' => ['internal'],
                    'related_type' => $fresh->getMorphClass(),
                    'related_id' => $fresh->id,
                    'context' => [
                        'status_label' => $fresh->quote?->safeCustomerApprovalStatusLabel(),
                    ],
                ]
            );
        }

        return $fresh;
    }

    public function approve(QuoteApprovalRequest $request, ?string $note = null): QuoteApprovalRequest
    {
        $this->guardRequestCanRespond($request);

        $approved = DB::transaction(function () use ($request, $note) {
            $request->forceFill([
                'status' => QuoteApprovalRequest::STATUS_APPROVED,
                'customer_note' => $note,
                'viewed_at' => $request->viewed_at ?? now(),
                'responded_at' => now(),
            ])->save();

            $quote = $request->quote()->lockForUpdate()->firstOrFail();
            $quote->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK,
                'status' => 'approved',
                'approved_at' => now(),
                'rejected_at' => null,
                'revision_requested_at' => null,
            ])->save();

            return $request->fresh();
        });

        $this->dispatchSafely(
            $approved->quote()->with(['customer.contacts', 'items', 'workForms'])->first(),
            'quote_customer_approved',
            [
                'audience_type' => 'tenant_admin',
                'channels' => ['internal'],
                'related_type' => $approved->getMorphClass(),
                'related_id' => $approved->id,
                'context' => [
                    'status_label' => $approved->quote?->safeCustomerApprovalStatusLabel(),
                ],
            ]
        );

        return $approved;
    }

    public function requestRevision(QuoteApprovalRequest $request, string $note): QuoteApprovalRequest
    {
        $this->guardRequestCanRespond($request);

        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Revize isteği için not gerekli.');
        }

        $revisioned = DB::transaction(function () use ($request, $note) {
            $request->forceFill([
                'status' => QuoteApprovalRequest::STATUS_REVISION_REQUESTED,
                'customer_note' => $note,
                'viewed_at' => $request->viewed_at ?? now(),
                'responded_at' => now(),
            ])->save();

            $quote = $request->quote()->lockForUpdate()->firstOrFail();
            $quote->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_REVISION_REQUESTED,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK,
                'status' => 'draft',
                'revision_requested_at' => now(),
                'approved_at' => null,
                'rejected_at' => null,
            ])->save();

            return $request->fresh();
        });

        $this->dispatchSafely(
            $revisioned->quote()->with(['customer.contacts', 'items', 'workForms'])->first(),
            'quote_revision_requested',
            [
                'audience_type' => 'tenant_admin',
                'channels' => ['internal'],
                'related_type' => $revisioned->getMorphClass(),
                'related_id' => $revisioned->id,
                'context' => [
                    'status_label' => $revisioned->quote?->safeCustomerApprovalStatusLabel(),
                ],
            ]
        );

        return $revisioned;
    }

    public function reject(QuoteApprovalRequest $request, ?string $note = null): QuoteApprovalRequest
    {
        $this->guardRequestCanRespond($request);

        $rejected = DB::transaction(function () use ($request, $note) {
            $request->forceFill([
                'status' => QuoteApprovalRequest::STATUS_REJECTED,
                'customer_note' => $note,
                'viewed_at' => $request->viewed_at ?? now(),
                'responded_at' => now(),
            ])->save();

            $quote = $request->quote()->lockForUpdate()->firstOrFail();
            $quote->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_REJECTED,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK,
                'status' => 'rejected',
                'rejected_at' => now(),
                'approved_at' => null,
                'revision_requested_at' => null,
            ])->save();

            return $request->fresh();
        });

        $this->dispatchSafely(
            $rejected->quote()->with(['customer.contacts', 'items', 'workForms'])->first(),
            'quote_rejected',
            [
                'audience_type' => 'tenant_admin',
                'channels' => ['internal'],
                'related_type' => $rejected->getMorphClass(),
                'related_id' => $rejected->id,
                'context' => [
                    'status_label' => $rejected->quote?->safeCustomerApprovalStatusLabel(),
                ],
            ]
        );

        return $rejected;
    }

    public function cancelOpenRequests(Order $quote, ?string $reason = null): void
    {
        $activeStatuses = [
            QuoteApprovalRequest::STATUS_WAITING,
            QuoteApprovalRequest::STATUS_VIEWED,
        ];

        $query = $quote->quoteApprovalRequests()->whereIn('status', $activeStatuses);

        if ($reason === 'expire') {
            $query->update([
                'status' => QuoteApprovalRequest::STATUS_EXPIRED,
                'responded_at' => now(),
            ]);

            return;
        }

        $query->update([
            'status' => QuoteApprovalRequest::STATUS_CANCELLED,
            'responded_at' => now(),
        ]);
    }

    public function canConvertToOrder(Order $quote): array
    {
        if (! $quote->isQuote()) {
            return [
                'allowed' => false,
                'reason' => 'Bu kayıt teklif değil.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        if ($quote->workflow_status === 'quote_converted') {
            return [
                'allowed' => false,
                'reason' => 'Teklif zaten siparişe dönüştürüldü.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        if (! $quote->customer_company_id) {
            return [
                'allowed' => false,
                'reason' => 'Müşteri seçilmeden siparişe çevrilemez.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        if ($quote->items()->count() < 1) {
            return [
                'allowed' => false,
                'reason' => 'En az bir ürün kalemi gerekli.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        if ($quote->customer_approval_status !== Order::CUSTOMER_APPROVAL_APPROVED) {
            return [
                'allowed' => false,
                'reason' => 'Müşteri onayı olmadan siparişe çevrilemez.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        $approvedRequest = $quote->quoteApprovalRequests()
            ->where('status', QuoteApprovalRequest::STATUS_APPROVED)
            ->latest('responded_at')
            ->latest('id')
            ->first();

        if (! $approvedRequest) {
            return [
                'allowed' => false,
                'reason' => 'Onaylı müşteri kaydı bulunamadı.',
                'approved_request_id' => null,
                'approved_snapshot_id' => null,
            ];
        }

        return [
            'allowed' => true,
            'reason' => '',
            'approved_request_id' => $approvedRequest->id,
            'approved_snapshot_id' => $approvedRequest->quote_send_snapshot_id,
        ];
    }

    private function guardQuoteCanBeSent(Order $quote): void
    {
        if (! $quote->isQuote()) {
            throw new RuntimeException('Sadece teklifler müşteriye gönderilebilir.');
        }

        if ($quote->workflow_status === 'quote_converted') {
            throw new RuntimeException('Siparişe dönüşen teklif tekrar gönderilemez.');
        }

        if (! $quote->customer_company_id) {
            throw new RuntimeException('Müşteri seçilmeden teklif gönderilemez.');
        }

        if ($quote->items()->count() < 1) {
            throw new RuntimeException('En az bir ürün kalemi olmadan teklif gönderilemez.');
        }
    }

    private function guardRequestCanRespond(QuoteApprovalRequest $request): void
    {
        $request->loadMissing('quote');

        if ($request->isCancelled()) {
            throw new RuntimeException('İptal edilen istek yanıtlanamaz.');
        }

        if ($request->isExpired()) {
            $request->forceFill(['status' => QuoteApprovalRequest::STATUS_EXPIRED])->save();
            throw new RuntimeException('Süresi dolan istek yanıtlanamaz.');
        }

        if (! in_array($request->status, [
            QuoteApprovalRequest::STATUS_WAITING,
            QuoteApprovalRequest::STATUS_VIEWED,
        ], true)) {
            throw new RuntimeException('Bu istek artık yanıtlanamaz.');
        }
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (QuoteApprovalRequest::query()->where('token', $token)->exists());

        return $token;
    }

    private function dispatchSafely(?Order $quote, string $eventKey, array $options = []): void
    {
        if (!$quote) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent(
                $quote->tenant,
                $eventKey,
                $quote,
                $options
            );
        } catch (\Throwable) {
            // Notification failures must not break quote approval workflow.
        }
    }

    private function resolvePublicQuoteUrl(QuoteApprovalRequest $request): ?string
    {
        foreach ([
            'public.quotes.approval.show',
            'public.quote-approvals.show',
            'public.quote-approval.show',
        ] as $routeName) {
            if (Route::has($routeName)) {
                return route($routeName, ['token' => $request->token]);
            }
        }

        return null;
    }
}
