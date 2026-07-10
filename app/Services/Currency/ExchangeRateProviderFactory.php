<?php

namespace App\Services\Currency;

use App\Contracts\Currency\ExchangeRateProviderInterface;
use App\Exceptions\Currency\ExchangeRateProviderException;

class ExchangeRateProviderFactory
{
    /**
     * @var array<int, ExchangeRateProviderInterface>
     */
    private array $providers;

    public function __construct(
        TcmbExchangeRateProvider $tcmbExchangeRateProvider,
    ) {
        $this->providers = [$tcmbExchangeRateProvider];
    }

    public function make(string $provider): ExchangeRateProviderInterface
    {
        foreach ($this->providers as $service) {
            if ($service->supports($provider)) {
                return $service;
            }
        }

        throw new ExchangeRateProviderException('Desteklenmeyen provider.', 'unsupported_provider', ['provider' => $provider]);
    }
}
