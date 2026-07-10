<?php

namespace App\Exceptions\Currency;

use RuntimeException;

class ExchangeRateProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'provider_error',
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailableForDate(string $provider, string $date): self
    {
        return new self(
            "{$provider} için {$date} tarihinde kur bulunamadı.",
            'rate_date_unavailable',
            ['provider' => $provider, 'date' => $date],
        );
    }
}
