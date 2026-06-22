<?php

namespace App\Services\Notifications;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class TenantNotificationSettingsService
{
    public const ENCRYPTED_PREFIX = 'enc::';

    public const SMTP_KEYS = [
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_name',
        'smtp_from_email',
        'smtp_reply_to_email',
        'smtp_is_active',
        'smtp_test_email',
    ];

    public const WHATSAPP_KEYS = [
        'whatsapp_default_country_code',
        'whatsapp_sender_label',
        'whatsapp_default_signature',
        'whatsapp_test_phone',
        'whatsapp_is_active',
    ];

    public const CHANNEL_KEYS = [
        'notification_email_enabled',
        'notification_whatsapp_enabled',
        'notification_sms_enabled',
        'customer_notification_enabled',
        'internal_notification_enabled',
    ];

    public function getSettings(TenantAccount $tenant): array
    {
        $values = [];

        foreach ($this->defaults() as $key => $meta) {
            $raw = TenantSetting::getValue($tenant->id, $key, $meta['default']);
            $values[$key] = $key === 'smtp_password'
                ? $this->decryptPassword($raw)
                : $raw;
        }

        return $values;
    }

    public function updateSmtpSettings(TenantAccount $tenant, array $data, ?User $user = null): void
    {
        $settings = $this->getSettings($tenant);

        foreach (self::SMTP_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            if ($key === 'smtp_password') {
                $incoming = trim((string) ($data[$key] ?? ''));
                if ($incoming === '') {
                    continue;
                }

                $this->persistSetting($tenant, $key, $this->encryptPassword($incoming), 'string', $user);
                continue;
            }

            $type = $this->defaults()[$key]['type'] ?? 'string';
            $normalized = $this->normalizeValue($data[$key], $type, $settings[$key] ?? null);
            $this->persistSetting($tenant, $key, $normalized, $type, $user);
        }
    }

    public function updateChannelSettings(TenantAccount $tenant, array $data, ?User $user = null): void
    {
        foreach (array_merge(self::WHATSAPP_KEYS, self::CHANNEL_KEYS) as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $type = $this->defaults()[$key]['type'] ?? 'string';
            $normalized = $this->normalizeValue($data[$key], $type, null);
            $this->persistSetting($tenant, $key, $normalized, $type, $user);
        }
    }

    public function isEmailEnabled(TenantAccount $tenant): bool
    {
        $settings = $this->getSettings($tenant);

        return (bool) ($settings['smtp_is_active'] ?? false)
            && (bool) ($settings['notification_email_enabled'] ?? true);
    }

    public function isWhatsappEnabled(TenantAccount $tenant): bool
    {
        $settings = $this->getSettings($tenant);

        return (bool) ($settings['whatsapp_is_active'] ?? false)
            && (bool) ($settings['notification_whatsapp_enabled'] ?? true);
    }

    public function isSmsEnabled(TenantAccount $tenant): bool
    {
        $settings = $this->getSettings($tenant);

        return (bool) ($settings['notification_sms_enabled'] ?? false);
    }

    public function getWhatsappConfig(TenantAccount $tenant): array
    {
        $settings = $this->getSettings($tenant);

        return [
            'default_country_code' => $this->normalizeWhatsappCountryCode((string) ($settings['whatsapp_default_country_code'] ?? '90')),
            'sender_label' => $settings['whatsapp_sender_label'] ?: 'Prodelya',
            'default_signature' => $settings['whatsapp_default_signature'] ?? null,
            'test_phone' => $settings['whatsapp_test_phone'] ?? null,
            'is_active' => (bool) ($settings['whatsapp_is_active'] ?? false),
        ];
    }

    public function maskWhatsappSettingsForDisplay(TenantAccount $tenant): array
    {
        return $this->getWhatsappConfig($tenant);
    }

    public function getSmtpConfig(TenantAccount $tenant): array
    {
        $settings = $this->getSettings($tenant);

        return [
            'host' => $settings['smtp_host'],
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'username' => $settings['smtp_username'],
            'password' => $settings['smtp_password'],
            'encryption' => $settings['smtp_encryption'],
            'from_name' => $settings['smtp_from_name'],
            'from_email' => $settings['smtp_from_email'],
            'reply_to_email' => $settings['smtp_reply_to_email'],
            'is_active' => (bool) ($settings['smtp_is_active'] ?? false),
            'test_email' => $settings['smtp_test_email'],
        ];
    }

    public function maskSmtpSettingsForDisplay(TenantAccount $tenant): array
    {
        $settings = $this->getSettings($tenant);

        return [
            'smtp_host' => $settings['smtp_host'],
            'smtp_port' => $settings['smtp_port'],
            'smtp_username' => $settings['smtp_username'],
            'smtp_encryption' => $settings['smtp_encryption'],
            'smtp_from_name' => $settings['smtp_from_name'],
            'smtp_from_email' => $settings['smtp_from_email'],
            'smtp_reply_to_email' => $settings['smtp_reply_to_email'],
            'smtp_is_active' => (bool) ($settings['smtp_is_active'] ?? false),
            'smtp_test_email' => $settings['smtp_test_email'],
            'smtp_password_configured' => filled($settings['smtp_password']),
        ];
    }

    private function persistSetting(TenantAccount $tenant, string $key, mixed $value, string $type, ?User $user): void
    {
        $tenant->settings()->updateOrCreate(
            ['key' => $key],
            [
                'value' => match ($type) {
                    'boolean' => $value ? '1' : '0',
                    'integer' => (string) ((int) $value),
                    default => (string) ($value ?? ''),
                },
                'type' => $type,
                'description' => $this->descriptions()[$key] ?? null,
                'is_public' => false,
            ]
        );
    }

    private function normalizeValue(mixed $value, string $type, mixed $fallback): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => is_numeric($value) ? (int) $value : (int) ($fallback ?? 0),
            default => filled($value) ? trim((string) $value) : $fallback,
        };
    }

    public function normalizeWhatsappCountryCode(string $value): string
    {
        $normalized = preg_replace('/\D+/', '', $value) ?: '90';

        return $normalized !== '' ? $normalized : '90';
    }

    private function encryptPassword(string $value): string
    {
        return self::ENCRYPTED_PREFIX . Crypt::encryptString($value);
    }

    private function decryptPassword(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $value = (string) $value;

        if (!str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function defaults(): array
    {
        return [
            'smtp_host' => ['default' => null, 'type' => 'string'],
            'smtp_port' => ['default' => 587, 'type' => 'integer'],
            'smtp_username' => ['default' => null, 'type' => 'string'],
            'smtp_password' => ['default' => null, 'type' => 'string'],
            'smtp_encryption' => ['default' => 'tls', 'type' => 'string'],
            'smtp_from_name' => ['default' => null, 'type' => 'string'],
            'smtp_from_email' => ['default' => null, 'type' => 'string'],
            'smtp_reply_to_email' => ['default' => null, 'type' => 'string'],
            'smtp_is_active' => ['default' => false, 'type' => 'boolean'],
            'smtp_test_email' => ['default' => null, 'type' => 'string'],
            'whatsapp_default_country_code' => ['default' => '+90', 'type' => 'string'],
            'whatsapp_sender_label' => ['default' => 'Prodelya', 'type' => 'string'],
            'whatsapp_default_signature' => ['default' => null, 'type' => 'string'],
            'whatsapp_test_phone' => ['default' => null, 'type' => 'string'],
            'whatsapp_is_active' => ['default' => false, 'type' => 'boolean'],
            'notification_email_enabled' => ['default' => true, 'type' => 'boolean'],
            'notification_whatsapp_enabled' => ['default' => true, 'type' => 'boolean'],
            'notification_sms_enabled' => ['default' => false, 'type' => 'boolean'],
            'customer_notification_enabled' => ['default' => true, 'type' => 'boolean'],
            'internal_notification_enabled' => ['default' => true, 'type' => 'boolean'],
        ];
    }

    private function descriptions(): array
    {
        return [
            'smtp_host' => 'Tenant SMTP sunucu adresi',
            'smtp_port' => 'Tenant SMTP portu',
            'smtp_username' => 'Tenant SMTP kullanıcı adı',
            'smtp_password' => 'Tenant SMTP şifresi',
            'smtp_encryption' => 'Tenant SMTP şifreleme tipi',
            'smtp_from_name' => 'Bildirim gönderen adı',
            'smtp_from_email' => 'Bildirim gönderen e-posta',
            'smtp_reply_to_email' => 'Reply-To e-posta adresi',
            'smtp_is_active' => 'Tenant SMTP aktiflik durumu',
            'smtp_test_email' => 'SMTP test e-posta adresi',
            'whatsapp_default_country_code' => 'WhatsApp varsayılan ülke kodu',
            'whatsapp_sender_label' => 'WhatsApp gönderen etiketi',
            'whatsapp_default_signature' => 'WhatsApp varsayılan imza metni',
            'whatsapp_test_phone' => 'WhatsApp test telefon numarası',
            'whatsapp_is_active' => 'WhatsApp bildirim kanalı aktiflik durumu',
            'notification_email_enabled' => 'E-posta bildirim kanalı aktiflik durumu',
            'notification_whatsapp_enabled' => 'WhatsApp bildirim kanalı aktiflik durumu',
            'notification_sms_enabled' => 'SMS bildirim kanalı aktiflik durumu',
            'customer_notification_enabled' => 'Müşteri bildirimlerine izin ver',
            'internal_notification_enabled' => 'İç bildirimlere izin ver',
        ];
    }
}
