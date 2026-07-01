<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayCredential;
use App\Models\PaymentProvider;
use App\Models\User;

class PaymentProviderConfigService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function saveProvider(?PaymentProvider $provider, array $payload, User $actor): PaymentProvider
    {
        $provider ??= new PaymentProvider();

        $provider->fill([
            'provider_key' => trim((string) $payload['provider_key']),
            'driver_key' => trim((string) $payload['driver_key']),
            'display_name' => trim((string) $payload['display_name']),
            'status' => trim((string) $payload['status']),
            'checkout_mode' => trim((string) ($payload['checkout_mode'] ?? 'hosted')),
            'supports_shared_saas_payments' => (bool) ($payload['supports_shared_saas_payments'] ?? true),
            'supports_tenant_module' => (bool) ($payload['supports_tenant_module'] ?? false),
            'notes' => filled($payload['notes'] ?? null) ? trim((string) $payload['notes']) : null,
            'updated_by' => $actor->id,
        ]);

        if (! $provider->exists) {
            $provider->created_by = $actor->id;
        }

        $provider->save();

        $provider->sharedCredential()->updateOrCreate(
            [
                'scope_type' => PaymentGatewayCredential::SCOPE_SUPER_ADMIN_SHARED,
                'tenant_account_id' => null,
            ],
            [
                'is_active' => (bool) ($payload['shared_credential_is_active'] ?? true),
                'credentials_json' => $this->credentialsPayload($payload),
                'settings_json' => $this->settingsPayload($payload),
                'notes' => filled($payload['shared_credential_notes'] ?? null) ? trim((string) $payload['shared_credential_notes']) : null,
                'created_by' => $provider->sharedCredential?->created_by ?? $actor->id,
                'updated_by' => $actor->id,
            ]
        );

        return $provider->fresh(['sharedCredential']) ?? $provider;
    }

    public function sharedCredentialReady(PaymentProvider $provider): bool
    {
        $credential = $provider->sharedCredential;

        if (! $credential || ! $credential->is_active) {
            return false;
        }

        $credentials = $credential->credentials_json ?? [];

        return filled($credentials['api_key'] ?? null) || filled($credentials['merchant_key'] ?? null);
    }

    public function liveApiEnabled(PaymentProvider $provider): bool
    {
        return (bool) data_get($provider->sharedCredential?->settings_json, 'use_live_api', false);
    }

    public function webhookSecretConfigured(PaymentProvider $provider): bool
    {
        return filled(data_get($provider->sharedCredential?->settings_json, 'webhook_secret'));
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return [
            PaymentProvider::STATUS_DRAFT => 'Taslak',
            PaymentProvider::STATUS_ACTIVE => 'Aktif',
            PaymentProvider::STATUS_PASSIVE => 'Pasif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function checkoutModeOptions(): array
    {
        return [
            'hosted' => 'Hosted Checkout',
            'api' => 'API / Server-to-Server',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function credentialsPayload(array $payload): array
    {
        return [
            'api_key' => $this->nullableString($payload['shared_api_key'] ?? null),
            'secret_key' => $this->nullableString($payload['shared_secret_key'] ?? null),
            'merchant_key' => $this->nullableString($payload['shared_merchant_key'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function settingsPayload(array $payload): array
    {
        return [
            'base_url' => $this->nullableString($payload['shared_base_url'] ?? null),
            'sandbox_mode' => (bool) ($payload['shared_sandbox_mode'] ?? true),
            'webhook_secret' => $this->nullableString($payload['shared_webhook_secret'] ?? null),
            'use_live_api' => (bool) ($payload['shared_use_live_api'] ?? false),
            'checkout_initialize_path' => $this->nullableString($payload['shared_checkout_initialize_path'] ?? null) ?: '/payment/iyzipos/checkoutform/initialize/auth/ecom',
            'timeout_seconds' => max(5, (int) ($payload['shared_timeout_seconds'] ?? 20)),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
