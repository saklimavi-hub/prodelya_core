<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\User;

class TenantWhatsappLinkService
{
    public const TYPE_QUOTE_LINK = 'quote_link';
    public const TYPE_ORDER_TRACKING = 'order_tracking';
    public const TYPE_DELIVERY_INFO = 'delivery_info';
    public const TYPE_GENERAL = 'general';

    public function __construct(
        protected TenantNotificationSettingsService $settingsService,
        protected NotificationDispatchService $dispatchService,
    ) {
    }

    public function buildPreview(TenantAccount $tenant, array $payload): array
    {
        $settings = $this->settingsService->getWhatsappConfig($tenant);
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $messageType = (string) ($payload['message_type'] ?? self::TYPE_GENERAL);
        $publicLink = $this->sanitizePublicLink((string) ($payload['public_link'] ?? ''));
        $body = $this->buildMessageBody(
            $messageType,
            $customerName,
            (string) ($payload['message'] ?? ''),
            $publicLink,
            (string) ($settings['sender_label'] ?? $tenant->name),
            (string) ($settings['default_signature'] ?? '')
        );

        return [
            'customer_name' => $customerName,
            'message_type' => $messageType,
            'phone' => $this->normalizePhone($tenant, (string) ($payload['recipient_phone'] ?? '')),
            'public_link' => $publicLink,
            'message' => $body,
        ];
    }

    public function createManualLink(TenantAccount $tenant, array $payload, ?User $user = null): array
    {
        $preview = $this->buildPreview($tenant, $payload);

        return $this->dispatchService->createWhatsappLink(
            $tenant,
            $preview['phone'],
            $preview['message'],
            [
                'notification_key' => 'whatsapp_manual_link',
                'channel' => NotificationTemplate::CHANNEL_WHATSAPP_LINK,
                'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
                'recipient_type' => 'phone',
                'recipient_name' => $preview['customer_name'] !== '' ? $preview['customer_name'] : 'WhatsApp Alıcısı',
                'subject' => 'WhatsApp Hazır Mesaj',
                'meta_json' => [
                    'message_type' => $preview['message_type'],
                    'public_link' => $preview['public_link'],
                ],
            ],
            $user
        );
    }

    public function normalizePhone(TenantAccount $tenant, string $phone): string
    {
        $settings = $this->settingsService->getWhatsappConfig($tenant);
        $countryCode = preg_replace('/\D+/', '', (string) ($settings['default_country_code'] ?? '90')) ?: '90';
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';

        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }

        if (str_starts_with($normalized, $countryCode)) {
            return $normalized;
        }

        if (str_starts_with($normalized, '0')) {
            return $countryCode . substr($normalized, 1);
        }

        return $countryCode . $normalized;
    }

    private function buildMessageBody(
        string $messageType,
        string $customerName,
        string $manualMessage,
        ?string $publicLink,
        string $companyLabel,
        string $signature
    ): string {
        $safeCustomerName = $customerName !== '' ? $customerName : 'Müşterimiz';
        $safeCompanyLabel = trim($companyLabel) !== '' ? trim($companyLabel) : 'Prodelya';
        $safeManualMessage = $this->sanitizeMessage($manualMessage);
        $lines = match ($messageType) {
            self::TYPE_QUOTE_LINK => [
                "Merhaba {$safeCustomerName},",
                "{$safeCompanyLabel} tarafından hazırlanan teklifinizi aşağıdaki bağlantıdan inceleyebilirsiniz:",
                $publicLink,
            ],
            self::TYPE_ORDER_TRACKING => [
                "Merhaba {$safeCustomerName},",
                'Siparişinizin güncel durumunu aşağıdaki bağlantıdan takip edebilirsiniz:',
                $publicLink,
            ],
            self::TYPE_DELIVERY_INFO => [
                "Merhaba {$safeCustomerName},",
                'Siparişiniz teslimat aşamasındadır. Detaylar için:',
                $publicLink,
            ],
            default => [
                "Merhaba {$safeCustomerName},",
                $safeManualMessage,
            ],
        };

        if ($signature !== '') {
            $lines[] = '';
            $lines[] = $this->sanitizeMessage($signature);
        }

        return trim(collect($lines)
            ->filter(fn ($line) => filled($line))
            ->implode("\n"));
    }

    private function sanitizePublicLink(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function sanitizeMessage(string $message): string
    {
        $sanitized = strip_tags($message);
        $sanitized = preg_replace('/\{\{\s*(total_amount|vat_total|balance|balance_due|profit|cost|supplier_cost|subcontractor_cost|pdh_raw|group_code|file_path|physical_path|token|api_key)\s*\}\}/iu', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(KDV|bakiye|maliyet|k[aâ]r|profit|supplier_cost|subcontractor_cost|group_code|file_path|physical_path|pdh_raw|api_key|token)\b/iu', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\s{2,}/u', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace("/\n{3,}/u", "\n\n", $sanitized) ?? $sanitized;

        return trim($sanitized);
    }
}
