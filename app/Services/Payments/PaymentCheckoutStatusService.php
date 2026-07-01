<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentTransaction;
use App\Models\TenantBillingEntry;
use App\Models\User;
use App\Services\TenantBillingLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentCheckoutStatusService
{
    public function __construct(
        protected TenantBillingLedgerService $tenantBillingLedgerService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyStatus(
        PaymentCheckoutSession $session,
        string $status,
        string $transactionType,
        array $payload = [],
        string $note = '',
        ?User $actor = null
    ): PaymentCheckoutSession {
        return DB::transaction(function () use ($session, $status, $transactionType, $payload, $note, $actor): PaymentCheckoutSession {
            $session->loadMissing(['tenant', 'subject', 'provider', 'transactions']);

            $updates = [
                'status' => $status,
                'updated_by' => $actor?->id,
            ];

            if ($status === PaymentCheckoutSession::STATUS_PAID && $session->paid_at === null) {
                $updates['paid_at'] = Carbon::now();
            }

            if (filled($payload['gateway_reference'] ?? null)) {
                $updates['gateway_reference'] = trim((string) $payload['gateway_reference']);
            }

            if (filled($payload['external_reference'] ?? null)) {
                $updates['external_reference'] = trim((string) $payload['external_reference']);
            }

            $session->forceFill($updates)->save();

            PaymentTransaction::query()->create([
                'payment_checkout_session_id' => $session->id,
                'payment_provider_id' => $session->payment_provider_id,
                'tenant_account_id' => $session->tenant_account_id,
                'transaction_type' => $transactionType,
                'status' => $status,
                'amount' => $session->amount,
                'currency' => $session->currency,
                'external_reference' => $session->external_reference,
                'gateway_reference' => $session->gateway_reference,
                'provider_payload_json' => $payload,
                'processed_at' => Carbon::now(),
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            if ($status === PaymentCheckoutSession::STATUS_PAID) {
                $this->syncCollectionToLedger($session);
            }

            if ($note !== '') {
                $meta = $session->meta_json ?? [];
                $meta['last_status_note'] = $note;
                $meta['last_status_changed_at'] = Carbon::now()->toAtomString();
                $session->forceFill(['meta_json' => $meta])->save();
            }

            return $session->fresh(['provider', 'tenant', 'subject', 'transactions']) ?? $session;
        });
    }

    private function syncCollectionToLedger(PaymentCheckoutSession $session): void
    {
        $tenant = $session->tenant;

        if (! $tenant) {
            return;
        }

        $meta = $session->meta_json ?? [];
        $existingCollectionId = (int) data_get($meta, 'applied_collection_entry_id', 0);

        if ($existingCollectionId > 0 && TenantBillingEntry::query()->whereKey($existingCollectionId)->exists()) {
            return;
        }

        $title = trim((string) data_get($meta, 'title', 'SaaS ödeme tahsilatı'));
        $note = trim((string) data_get($meta, 'note', ''));
        $subjectTitle = $session->subject instanceof TenantBillingEntry ? $session->subject->title : null;

        $collectionEntry = $this->tenantBillingLedgerService->createEntry($tenant, [
            'tenant_service_definition_id' => null,
            'package_key' => $session->subject instanceof TenantBillingEntry ? $session->subject->package_key : null,
            'entry_type' => 'collection',
            'title' => $subjectTitle ? ($subjectTitle . ' tahsilatı') : $title,
            'note' => $note !== '' ? $note : 'Ortak ödeme omurgası webhook senkronu ile tahsil edildi.',
            'reference_no' => $session->reference_no,
            'direction' => 'credit',
            'amount' => (float) $session->amount,
            'currency' => $session->currency ?: 'TRY',
            'entry_date' => optional($session->paid_at)->toDateString() ?: now()->toDateString(),
            'meta_json' => [
                'source' => 'payment_gateway',
                'payment_checkout_session_id' => $session->id,
                'payment_provider_id' => $session->payment_provider_id,
                'gateway_reference' => $session->gateway_reference,
                'external_reference' => $session->external_reference,
            ],
        ]);

        $meta['applied_collection_entry_id'] = $collectionEntry->id;
        $meta['collection_synced_at'] = Carbon::now()->toAtomString();

        $session->forceFill([
            'meta_json' => $meta,
        ])->save();
    }
}
