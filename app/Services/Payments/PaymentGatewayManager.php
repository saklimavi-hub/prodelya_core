<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutSession;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Services\Payments\Contracts\PaymentGatewayDriver;
use App\Services\Payments\Drivers\IyzicoPaymentGatewayDriver;
use App\Services\Payments\Drivers\NullPaymentGatewayDriver;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayDriver>
     */
    private array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'iyzico' => new IyzicoPaymentGatewayDriver(),
            'null' => new NullPaymentGatewayDriver(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function driverOptions(): array
    {
        return [
            'iyzico' => 'Iyzico',
            'null' => 'Genel Hazırlık / Placeholder',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function initializeCheckout(
        PaymentProvider $provider,
        PaymentGatewayCredential $credential,
        PaymentCheckoutSession $session
    ): array {
        return $this->driverFor($provider)->initializeCheckout($provider, $credential, $session);
    }

    public function requiresSignature(string $driverKey): bool
    {
        return $driverKey === 'iyzico';
    }

    private function driverFor(PaymentProvider $provider): PaymentGatewayDriver
    {
        $key = trim((string) $provider->driver_key);

        if (! array_key_exists($key, $this->drivers)) {
            throw new InvalidArgumentException('Bu provider için tanımlı bir gateway driver bulunamadı.');
        }

        return $this->drivers[$key];
    }
}
