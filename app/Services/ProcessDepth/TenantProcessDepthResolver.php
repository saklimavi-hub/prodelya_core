<?php

namespace App\Services\ProcessDepth;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\PackageCatalogService;
use App\Support\ProcessDepth\ProcessDepth;
use Illuminate\Support\Facades\Log;

class TenantProcessDepthResolver
{
    public function __construct(
        protected PackageCatalogService $packageCatalogService,
    ) {
    }

    public function resolve(TenantAccount $tenant): array
    {
        $tenantOverride = TenantSetting::getValue($tenant->id, 'process_depth');
        if (is_string($tenantOverride) && trim($tenantOverride) !== '') {
            if (ProcessDepth::isValid($tenantOverride)) {
                return $this->resolvedPayload(ProcessDepth::normalize($tenantOverride), 'tenant_override');
            }

            $this->logInvalidDepth('tenant_override', $tenant, (string) $tenantOverride);
        }

        $packageKey = trim((string) ($tenant->package_key ?? ''));
        if ($packageKey !== '') {
            $package = $this->packageCatalogService->getPackageByKey($packageKey);

            if ($package) {
                $rawPackageDepth = $package->getRawOriginal('process_depth');

                if (is_string($rawPackageDepth) && trim($rawPackageDepth) !== '') {
                    if (ProcessDepth::isValid($rawPackageDepth)) {
                        return $this->resolvedPayload(ProcessDepth::normalize($rawPackageDepth), 'package_default');
                    }

                    $this->logInvalidDepth('package_default', $tenant, (string) $rawPackageDepth, [
                        'package_id' => $package->id,
                        'package_key' => $package->key,
                    ]);
                } else {
                    return $this->resolvedPayload(ProcessDepth::default(), 'package_default');
                }
            }
        }

        return $this->resolvedPayload(ProcessDepth::default(), 'system_default');
    }

    protected function resolvedPayload(string $depth, string $source): array
    {
        return [
            'key' => $depth,
            'label' => ProcessDepth::label($depth),
            'source' => $source,
            'source_label' => ProcessDepth::sourceLabel($source),
            'is_overridden' => $source === 'tenant_override',
        ];
    }

    protected function logInvalidDepth(string $source, TenantAccount $tenant, string $value, array $extra = []): void
    {
        Log::warning('Invalid process depth value encountered.', array_merge([
            'tenant_id' => $tenant->id,
            'source' => $source,
            'invalid_value' => $value,
            'normalized_fallback' => ProcessDepth::default(),
        ], $extra));
    }
}
