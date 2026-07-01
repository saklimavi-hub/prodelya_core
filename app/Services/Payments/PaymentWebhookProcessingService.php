<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Models\PaymentWebhookLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentWebhookProcessingService
{
    public function __construct(
        protected PaymentCheckoutStatusService $checkoutStatusService,
        protected PaymentWebhookSignatureService $signatureService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, PaymentProvider $provider): array
    {
        $credential = $provider->sharedCredential;
        $headers = collect($request->headers->all())->map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value)->all();
        $payload = $request->all();
        $eventKey = $this->eventKey($request);
        $reference = $this->reference($request);

        $webhookLog = PaymentWebhookLog::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => null,
            'scope_type' => PaymentGatewayCredential::SCOPE_SUPER_ADMIN_SHARED,
            'event_key' => $eventKey,
            'status' => 'received',
            'external_reference' => $reference,
            'headers_json' => $headers,
            'payload_json' => $payload,
            'notes' => 'Webhook alındı, doğrulama ve ödeme senkronu işleniyor.',
        ]);

        if (! $this->secretMatches($request, $credential)) {
            $webhookLog->forceFill([
                'status' => 'rejected',
                'notes' => 'Webhook secret doğrulaması başarısız.',
                'processed_at' => Carbon::now(),
            ])->save();

            return [
                'accepted' => false,
                'code' => 401,
                'payload' => [
                    'received' => false,
                    'reason' => 'invalid_webhook_secret',
                ],
            ];
        }

        $session = $this->resolveSession($provider, $request);

        if (! $session instanceof PaymentCheckoutSession) {
            $webhookLog->forceFill([
                'status' => 'ignored',
                'notes' => 'Webhook doğrulandı ancak eşleşen checkout oturumu bulunamadı.',
                'processed_at' => Carbon::now(),
            ])->save();

            return [
                'accepted' => true,
                'code' => 202,
                'payload' => [
                    'received' => true,
                    'matched' => false,
                    'provider' => $provider->provider_key,
                ],
            ];
        }

        $normalizedStatus = $this->normalizeStatus($request, $eventKey);

        \Illuminate\Support\Facades\DB::transaction(function () use ($session, $normalizedStatus, $request, $webhookLog): void {
            $this->checkoutStatusService->applyStatus(
                $session,
                $normalizedStatus,
                $this->transactionTypeFor($normalizedStatus),
                $request->all(),
                'Webhook işlendi ve checkout durumu güncellendi.'
            );

            $webhookLog->forceFill([
                'tenant_account_id' => $session->tenant_account_id,
                'status' => 'processed',
                'notes' => 'Webhook işlendi ve checkout durumu güncellendi.',
                'processed_at' => Carbon::now(),
            ])->save();
        });

        return [
            'accepted' => true,
            'code' => 202,
            'payload' => [
                'received' => true,
                'matched' => true,
                'provider' => $provider->provider_key,
                'checkout_status' => $normalizedStatus,
            ],
        ];
    }

    private function secretMatches(Request $request, ?PaymentGatewayCredential $credential): bool
    {
        if (! $credential instanceof PaymentGatewayCredential || ! $credential->is_active) {
            return false;
        }

        $expected = trim((string) data_get($credential->settings_json, 'webhook_secret', ''));

        if ($expected === '') {
            return true;
        }

        $signature = trim((string) ($request->header('X-Webhook-Signature') ?: $request->input('signature') ?: ''));

        if ($this->signatureService->verify($request->all(), $expected, $signature)) {
            return true;
        }

        $provided = trim((string) ($request->header('X-Webhook-Secret') ?: $request->input('webhook_secret') ?: ''));

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function resolveSession(PaymentProvider $provider, Request $request): ?PaymentCheckoutSession
    {
        $candidates = array_filter([
            $this->reference($request),
            trim((string) $request->input('external_reference')),
            trim((string) $request->input('gateway_reference')),
            trim((string) $request->header('X-Payment-Reference')),
        ]);

        if ($candidates === []) {
            return null;
        }

        return PaymentCheckoutSession::query()
            ->where('payment_provider_id', $provider->id)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('reference_no', $candidates)
                    ->orWhereIn('external_reference', $candidates)
                    ->orWhereIn('gateway_reference', $candidates);
            })
            ->latest('id')
            ->first();
    }

    private function normalizeStatus(Request $request, string $eventKey): string
    {
        $status = strtolower(trim((string) ($request->input('status') ?: '')));

        if (in_array($status, ['success', 'paid', 'completed'], true) || str_contains($eventKey, 'paid')) {
            return PaymentCheckoutSession::STATUS_PAID;
        }

        if (in_array($status, ['failed', 'error'], true) || str_contains($eventKey, 'failed')) {
            return PaymentCheckoutSession::STATUS_FAILED;
        }

        if (in_array($status, ['cancelled', 'canceled'], true) || str_contains($eventKey, 'cancel')) {
            return PaymentCheckoutSession::STATUS_CANCELLED;
        }

        if (in_array($status, ['expired'], true) || str_contains($eventKey, 'expired')) {
            return PaymentCheckoutSession::STATUS_EXPIRED;
        }

        return PaymentCheckoutSession::STATUS_PENDING;
    }

    private function transactionTypeFor(string $status): string
    {
        return match ($status) {
            PaymentCheckoutSession::STATUS_PAID => 'payment_confirmed',
            PaymentCheckoutSession::STATUS_FAILED => 'payment_failed',
            PaymentCheckoutSession::STATUS_CANCELLED => 'payment_cancelled',
            PaymentCheckoutSession::STATUS_EXPIRED => 'payment_expired',
            default => 'webhook_update',
        };
    }

    private function eventKey(Request $request): string
    {
        return trim((string) ($request->input('event') ?: $request->header('X-Event-Key') ?: 'unknown'));
    }

    private function reference(Request $request): string
    {
        return trim((string) ($request->input('reference') ?: $request->header('X-Payment-Reference') ?: ''));
    }
}
