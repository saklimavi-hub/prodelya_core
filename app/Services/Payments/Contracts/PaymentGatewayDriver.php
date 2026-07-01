<?php

namespace App\Services\Payments\Contracts;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;

interface PaymentGatewayDriver
{
    public function driverKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function initializeCheckout(
        PaymentProvider $provider,
        PaymentGatewayCredential $credential,
        PaymentCheckoutSession $session
    ): array;
}
