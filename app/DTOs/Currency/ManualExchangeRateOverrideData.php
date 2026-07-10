<?php

namespace App\DTOs\Currency;

use App\Exceptions\Currency\ManualExchangeRateReasonRequiredException;

final class ManualExchangeRateOverrideData
{
    public function __construct(
        public readonly string $rate,
        public readonly string $reason,
        public readonly int|string|null $overriddenBy = null,
        public readonly ?string $overriddenAt = null,
    ) {
        if (trim($this->reason) === '') {
            throw new ManualExchangeRateReasonRequiredException();
        }
    }

    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'reason' => $this->reason,
            'overridden_by' => $this->overriddenBy,
            'override_at' => $this->overriddenAt,
        ];
    }
}
