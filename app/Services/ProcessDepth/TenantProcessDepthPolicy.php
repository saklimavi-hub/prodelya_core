<?php

namespace App\Services\ProcessDepth;

use App\Models\TenantAccount;
use App\Support\ProcessDepth\ProcessDepth;

class TenantProcessDepthPolicy
{
    public function __construct(
        protected TenantProcessDepthResolver $resolver,
    ) {
    }

    public function forTenant(TenantAccount $tenant): array
    {
        return $this->forDepth((string) data_get($this->resolver->resolve($tenant), 'key', ProcessDepth::default()));
    }

    public function forDepth(string $depth): array
    {
        $normalized = ProcessDepth::normalize($depth);
        $capabilities = (array) config("process_depth.capabilities.{$normalized}", []);

        return $capabilities !== []
            ? $capabilities
            : (array) config('process_depth.capabilities.standard', []);
    }

    public function capability(TenantAccount $tenant, string $key, mixed $default = null): mixed
    {
        return data_get($this->forTenant($tenant), $key, $default);
    }

    public function usesCompactOperationCards(TenantAccount $tenant): bool
    {
        return $this->capability($tenant, 'operation_card_density') === 'compact';
    }

    public function showsExtendedReadinessDetails(TenantAccount $tenant): bool
    {
        return (bool) $this->capability($tenant, 'show_extended_readiness_details', false);
    }
}
