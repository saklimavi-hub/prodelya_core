<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IyzicoApiClient
{
    /**
     * @return array<string, mixed>
     */
    public function initializeHostedCheckout(
        PaymentProvider $provider,
        PaymentGatewayCredential $credential,
        PaymentCheckoutSession $session
    ): array {
        $settings = $credential->settings_json ?? [];
        $baseUrl = rtrim((string) ($settings['base_url'] ?? ''), '/');
        $path = trim((string) ($settings['checkout_initialize_path'] ?? '/payment/iyzipos/checkoutform/initialize/auth/ecom'));
        $timeoutSeconds = max(5, (int) ($settings['timeout_seconds'] ?? 20));

        if ($baseUrl === '') {
            throw new RuntimeException('Iyzico canlı API çağrısı için base URL tanımlı değil.');
        }

        $requestUrl = $baseUrl . '/' . ltrim($path, '/');
        $payload = $this->buildPayload($session);
        $request = $this->buildRequest($credential, $timeoutSeconds);
        $response = $request->post($requestUrl, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Iyzico checkout initialize isteği başarısız oldu: HTTP ' . $response->status());
        }

        $responseData = $response->json();

        if (! is_array($responseData)) {
            throw new RuntimeException('Iyzico checkout initialize cevabı beklenen JSON formatında değil.');
        }

        $statusValue = strtolower((string) ($responseData['status'] ?? ''));
        $mappedStatus = $statusValue === 'success'
            ? PaymentCheckoutSession::STATUS_PENDING
            : PaymentCheckoutSession::STATUS_FAILED;

        return [
            'status' => $mappedStatus,
            'external_reference' => (string) ($responseData['conversationId'] ?? $session->reference_no),
            'gateway_reference' => (string) ($responseData['token'] ?? ($responseData['paymentId'] ?? '')),
            'checkout_url' => (string) ($responseData['paymentPageUrl'] ?? route('admin.super.payment-checkouts.show', $session)),
            'provider_payload_json' => [
                'driver' => 'iyzico',
                'stage' => 'live_initialize_called',
                'request_url' => $requestUrl,
                'request_payload' => $payload,
                'response' => $responseData,
                'success_callback_url' => route('payment-checkouts.callbacks.success', $session),
                'failure_callback_url' => route('payment-checkouts.callbacks.failure', $session),
                'cancel_callback_url' => route('payment-checkouts.callbacks.cancel', $session),
                'webhook_url' => route('payment-webhooks.receive', $provider),
                'tenant_side_note' => 'Tenant tarafi odeme kabiliyeti ileride modül olarak acilacaktir.',
                'next_phase' => 'Provider-specific signature/header sertlestirmesi sonraki fazda tamamlanacak.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(PaymentCheckoutSession $session): array
    {
        $tenant = $session->tenant;
        $meta = $session->meta_json ?? [];
        $title = trim((string) data_get($meta, 'title', 'Prodelya SaaS Tahsilati'));
        $tenantName = trim((string) ($tenant?->name ?: 'Prodelya Tenant'));
        $email = trim((string) ($tenant?->email ?: data_get($meta, 'billing_email', 'odeme@prodelya.local')));
        $phone = preg_replace('/\D+/', '', (string) ($tenant?->phone ?: data_get($meta, 'billing_phone', '905000000000')));
        $address = trim((string) data_get($meta, 'billing_address', 'Merkez Ofis'));
        $city = trim((string) data_get($meta, 'billing_city', 'Istanbul'));
        $country = trim((string) data_get($meta, 'billing_country', 'Turkey'));
        $postalCode = trim((string) data_get($meta, 'billing_postal_code', '34000'));

        return [
            'locale' => 'tr',
            'conversationId' => $session->reference_no,
            'price' => number_format((float) $session->amount, 2, '.', ''),
            'paidPrice' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency ?: 'TRY',
            'basketId' => $session->reference_no,
            'paymentGroup' => 'SUBSCRIPTION',
            'callbackUrl' => route('payment-checkouts.callbacks.success', $session),
            'buyer' => [
                'id' => (string) ($tenant?->id ?: '0'),
                'name' => $tenantName,
                'surname' => 'Tenant',
                'email' => $email,
                'gsmNumber' => $phone !== '' ? '+' . $phone : '+905000000000',
                'identityNumber' => '11111111111',
                'registrationAddress' => $address,
                'city' => $city,
                'country' => $country,
                'zipCode' => $postalCode,
            ],
            'billingAddress' => [
                'contactName' => $tenantName,
                'city' => $city,
                'country' => $country,
                'address' => $address,
                'zipCode' => $postalCode,
            ],
            'basketItems' => [
                [
                    'id' => $session->reference_no,
                    'name' => $title,
                    'category1' => 'SaaS',
                    'itemType' => 'VIRTUAL',
                    'price' => number_format((float) $session->amount, 2, '.', ''),
                ],
            ],
        ];
    }

    private function buildRequest(PaymentGatewayCredential $credential, int $timeoutSeconds): PendingRequest
    {
        $credentials = $credential->credentials_json ?? [];
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));
        $merchantKey = trim((string) ($credentials['merchant_key'] ?? ''));

        return Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-IYZICO-API-KEY' => $apiKey,
                'X-IYZICO-SECRET-KEY' => $secretKey,
                'X-IYZICO-MERCHANT-KEY' => $merchantKey,
                'User-Agent' => 'Prodelya Payment Gateway Foundation',
            ]);
    }
}
