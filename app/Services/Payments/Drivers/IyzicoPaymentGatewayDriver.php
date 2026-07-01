<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Services\Payments\Contracts\PaymentGatewayDriver;
use App\Services\Payments\IyzicoApiClient;

class IyzicoPaymentGatewayDriver implements PaymentGatewayDriver
{
    public function driverKey(): string
    {
        return 'iyzico';
    }

    public function initializeCheckout(
        PaymentProvider $provider,
        PaymentGatewayCredential $credential,
        PaymentCheckoutSession $session
    ): array {
        $credentials = $credential->credentials_json ?? [];
        $settings = $credential->settings_json ?? [];
        $apiKeyConfigured = filled($credentials['api_key'] ?? null);
        $secretKeyConfigured = filled($credentials['secret_key'] ?? null);
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $sandboxMode = (bool) ($settings['sandbox_mode'] ?? true);
        $useLiveApi = (bool) ($settings['use_live_api'] ?? false);

        if ($useLiveApi && $apiKeyConfigured && $secretKeyConfigured && $baseUrl !== '') {
            return app(IyzicoApiClient::class)->initializeHostedCheckout($provider, $credential, $session);
        }

        $hostedCheckoutUrl = $this->buildHostedCheckoutUrl($baseUrl, $session, $sandboxMode);

        return [
            'status' => PaymentCheckoutSession::STATUS_PENDING,
            'external_reference' => 'iyzico-prep-' . $session->reference_no,
            'gateway_reference' => 'iyzico-gateway-skeleton',
            'checkout_url' => $hostedCheckoutUrl,
            'provider_payload_json' => [
                'driver' => 'iyzico',
                'stage' => 'hosted_checkout_prepared',
                'use_live_api' => $useLiveApi,
                'api_key_configured' => $apiKeyConfigured,
                'secret_key_configured' => $secretKeyConfigured,
                'sandbox_mode' => $sandboxMode,
                'base_url' => $baseUrl !== '' ? $baseUrl : null,
                'success_callback_url' => route('payment-checkouts.callbacks.success', $session),
                'failure_callback_url' => route('payment-checkouts.callbacks.failure', $session),
                'cancel_callback_url' => route('payment-checkouts.callbacks.cancel', $session),
                'webhook_url' => route('payment-webhooks.receive', $provider),
                'tenant_side_note' => 'Tenant tarafi odeme kabiliyeti ileride modül olarak acilacaktir.',
                'next_phase' => 'Gercek Iyzico API cagrisi sonraki entegrasyon fazinda eklenecek.',
            ],
        ];
    }

    private function buildHostedCheckoutUrl(string $baseUrl, PaymentCheckoutSession $session, bool $sandboxMode): string
    {
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/hosted-checkout/' . urlencode((string) $session->reference_no)
                . '?mode=' . ($sandboxMode ? 'sandbox' : 'live');
        }

        return route('admin.super.payment-checkouts.show', $session);
    }
}
