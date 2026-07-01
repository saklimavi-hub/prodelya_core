<?php

namespace App\Services\Payments;

class PaymentWebhookSignatureService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload, string $secret): string
    {
        return hash_hmac('sha256', $this->canonicalString($payload), $secret);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verify(array $payload, string $secret, ?string $signature): bool
    {
        if (! filled($signature)) {
            return false;
        }

        return hash_equals($this->sign($payload, $secret), trim((string) $signature));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalString(array $payload): string
    {
        return implode('|', [
            trim((string) ($payload['event'] ?? '')),
            trim((string) ($payload['reference'] ?? '')),
            trim((string) ($payload['status'] ?? '')),
            trim((string) ($payload['gateway_reference'] ?? '')),
            trim((string) ($payload['external_reference'] ?? '')),
        ]);
    }
}
