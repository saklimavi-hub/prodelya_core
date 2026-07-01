<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuperAdminPaymentCheckoutService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSaasBillingSession(
        TenantAccount $tenant,
        PaymentProvider $provider,
        array $payload,
        User $actor,
        ?TenantBillingEntry $billingEntry = null
    ): PaymentCheckoutSession {
        if (! $provider->supports_shared_saas_payments) {
            throw new InvalidArgumentException('Seçili provider Super Admin ortak SaaS tahsilatını desteklemiyor.');
        }

        if ($provider->status !== PaymentProvider::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Seçili provider aktif değil.');
        }

        $credential = $provider->sharedCredential;

        if (! $credential instanceof PaymentGatewayCredential || ! $credential->is_active) {
            throw new InvalidArgumentException('Seçili provider için aktif ortak credential bulunamadı.');
        }

        return DB::transaction(function () use ($tenant, $provider, $credential, $payload, $actor, $billingEntry): PaymentCheckoutSession {
            $session = PaymentCheckoutSession::query()->create([
                'payment_provider_id' => $provider->id,
                'payment_gateway_credential_id' => $credential->id,
                'tenant_account_id' => $tenant->id,
                'scope_type' => PaymentGatewayCredential::SCOPE_SUPER_ADMIN_SHARED,
                'payment_context' => 'saas_billing',
                'subject_type' => $billingEntry?->getMorphClass(),
                'subject_id' => $billingEntry?->id,
                'reference_no' => $this->referenceFor($tenant),
                'status' => PaymentCheckoutSession::STATUS_DRAFT,
                'amount' => round((float) $payload['amount'], 2),
                'currency' => (string) ($payload['currency'] ?? 'TRY'),
                'expires_at' => filled($payload['expires_at'] ?? null) ? $payload['expires_at'] : now()->addDays(7),
                'meta_json' => [
                    'tenant_side_note' => 'Tenant tarafında ödeme yeteneği ileride modül olarak açılacaktır.',
                    'shared_backbone_note' => 'Super Admin tarafında ortak ödeme omurgası kullanılır.',
                    'title' => $payload['title'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'tenant_billing_entry_id' => $billingEntry?->id,
                ],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $gatewayPayload = $this->gatewayManager->initializeCheckout($provider, $credential, $session);

            $session->forceFill([
                'status' => (string) ($gatewayPayload['status'] ?? PaymentCheckoutSession::STATUS_PENDING),
                'external_reference' => $gatewayPayload['external_reference'] ?? null,
                'gateway_reference' => $gatewayPayload['gateway_reference'] ?? null,
                'provider_payload_json' => $gatewayPayload['provider_payload_json'] ?? [],
                'checkout_url' => $gatewayPayload['checkout_url'] ?? route('admin.super.payment-checkouts.show', $session),
                'updated_by' => $actor->id,
            ])->save();

            PaymentTransaction::query()->create([
                'payment_checkout_session_id' => $session->id,
                'payment_provider_id' => $provider->id,
                'tenant_account_id' => $tenant->id,
                'transaction_type' => 'checkout_initialized',
                'status' => $session->status,
                'amount' => $session->amount,
                'currency' => $session->currency,
                'external_reference' => $session->external_reference,
                'gateway_reference' => $session->gateway_reference,
                'provider_payload_json' => $session->provider_payload_json,
                'processed_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $session->fresh(['provider', 'credential', 'tenant', 'subject', 'transactions']) ?? $session;
        });
    }

    public function retrySaasBillingSession(PaymentCheckoutSession $session, User $actor): PaymentCheckoutSession
    {
        $session->loadMissing(['provider.sharedCredential', 'tenant', 'subject']);

        if (! $session->canBeRetried()) {
            throw new InvalidArgumentException('Yalnız başarısız, iptal edilmiş veya süresi dolmuş checkout oturumları yeniden üretilebilir.');
        }

        $tenant = $session->tenant;
        $provider = $session->provider;

        if (! $tenant instanceof TenantAccount || ! $provider instanceof PaymentProvider) {
            throw new InvalidArgumentException('Retry için tenant veya provider bilgisi eksik.');
        }

        $meta = $session->meta_json ?? [];
        $retryPayload = [
            'amount' => (float) $session->amount,
            'currency' => $session->currency ?: 'TRY',
            'title' => data_get($meta, 'title', 'SaaS ödeme checkout tekrar üretimi'),
            'note' => trim((string) data_get($meta, 'note', '')),
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ];

        $billingEntry = $session->subject instanceof TenantBillingEntry ? $session->subject : null;
        $newSession = $this->createSaasBillingSession($tenant, $provider, $retryPayload, $actor, $billingEntry);

        $oldMeta = $session->meta_json ?? [];
        $oldMeta['retried_to_checkout_session_id'] = $newSession->id;
        $oldMeta['retried_at'] = now()->toAtomString();
        $oldMeta['retried_by'] = $actor->id;

        $session->forceFill([
            'meta_json' => $oldMeta,
            'updated_by' => $actor->id,
        ])->save();

        $newMeta = $newSession->meta_json ?? [];
        $newMeta['retry_of_checkout_session_id'] = $session->id;

        $newSession->forceFill([
            'meta_json' => $newMeta,
            'updated_by' => $actor->id,
        ])->save();

        return $newSession->fresh(['provider', 'credential', 'tenant', 'subject', 'transactions']) ?? $newSession;
    }

    private function referenceFor(TenantAccount $tenant): string
    {
        return 'PAY-' . Str::upper(Str::substr($tenant->slug ?: (string) $tenant->id, 0, 12)) . '-' . now()->format('YmdHis');
    }
}
