<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Services\Payments\Contracts\PaymentGatewayDriver;

class NullPaymentGatewayDriver implements PaymentGatewayDriver
{
    public function driverKey(): string
    {
        return 'null';
    }

    public function initializeCheckout(
        PaymentProvider $provider,
        PaymentGatewayCredential $credential,
        PaymentCheckoutSession $session
    ): array {
        return [
            'status' => PaymentCheckoutSession::STATUS_PENDING,
            'external_reference' => 'provider-skeleton-' . $session->reference_no,
            'gateway_reference' => 'provider-skeleton',
            'checkout_url' => route('admin.super.payment-checkouts.show', $session),
            'provider_payload_json' => [
                'driver' => 'null',
                'stage' => 'foundation_only',
                'note' => 'Bu provider için canlı entegrasyon henüz eklenmedi.',
            ],
        ];
    }
}
