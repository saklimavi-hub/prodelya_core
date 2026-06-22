<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\User;

class NotificationDispatchService
{
    public function __construct(
        protected TenantNotificationSettingsService $tenantNotificationSettingsService,
        protected NotificationEventCatalogService $eventCatalogService,
    ) {}

    public function logPending(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, ['status' => NotificationLog::STATUS_PENDING]));
    }

    public function logSent(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
        ]));
    }

    public function logPreview(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, [
            'status' => NotificationLog::STATUS_PREVIEW,
        ]));
    }

    public function logFailed(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, [
            'status' => NotificationLog::STATUS_FAILED,
            'sent_at' => now(),
        ]));
    }

    public function logSkipped(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, ['status' => NotificationLog::STATUS_SKIPPED]));
    }

    public function logWhatsappLinkCreated(array $data): NotificationLog
    {
        return $this->createLog(array_merge($data, [
            'status' => NotificationLog::STATUS_LINK_CREATED,
            'dispatch_mode' => NotificationTemplate::CHANNEL_WHATSAPP_LINK === ($data['channel'] ?? null) ? 'link' : ($data['dispatch_mode'] ?? 'link'),
            'sent_at' => now(),
        ]));
    }

    public function createLog(array $data): NotificationLog
    {
        $notificationKey = filled($data['notification_key'] ?? null)
            ? $this->eventCatalogService->normalizeEventKey((string) $data['notification_key'])
            : null;

        return NotificationLog::query()->create([
            'tenant_account_id' => (int) $data['tenant_account_id'],
            'notification_key' => $notificationKey,
            'template_id' => $data['template_id'] ?? null,
            'channel' => $data['channel'] ?? NotificationTemplate::CHANNEL_INTERNAL,
            'audience_type' => $data['audience_type'] ?? null,
            'recipient_type' => $data['recipient_type'] ?? null,
            'recipient_name' => $this->sanitizeScalar($data['recipient_name'] ?? null, 190),
            'recipient_email' => $this->sanitizeScalar($data['recipient_email'] ?? null, 190),
            'recipient_phone' => $this->sanitizeScalar($data['recipient_phone'] ?? null, 40),
            'subject' => $this->sanitizeScalar($data['subject'] ?? null, 255),
            'message_preview' => $this->sanitizePreview($data['message_preview'] ?? null),
            'status' => $data['status'] ?? NotificationLog::STATUS_PENDING,
            'attempt_count' => (int) ($data['attempt_count'] ?? 0),
            'error_message' => $this->sanitizeScalar($data['error_message'] ?? null, 500),
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'dispatch_mode' => $data['dispatch_mode'] ?? 'sync',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'next_retry_at' => $data['next_retry_at'] ?? null,
            'provider_response' => $this->sanitizeStructuredValue($data['provider_response'] ?? null),
            'response_code' => $this->sanitizeScalar($data['response_code'] ?? null, 60),
            'meta_json' => $this->sanitizeStructuredValue($data['meta_json'] ?? null),
            'sent_at' => $data['sent_at'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    public function dispatchEmailPreview(TenantAccount $tenant, array $payload, ?User $user = null): NotificationLog
    {
        return $this->logPreview([
            'tenant_account_id' => $tenant->id,
            'notification_key' => $payload['notification_key'] ?? null,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => $payload['audience_type'] ?? null,
            'recipient_type' => $payload['recipient_type'] ?? 'other',
            'recipient_name' => $payload['recipient_name'] ?? null,
            'recipient_email' => $payload['recipient_email'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'message_preview' => $payload['body'] ?? null,
            'dispatch_mode' => 'preview',
            'template_id' => $payload['template_id'] ?? null,
            'meta_json' => $payload['meta_json'] ?? null,
            'created_by' => $user?->id,
            'related_type' => $payload['related_type'] ?? null,
            'related_id' => $payload['related_id'] ?? null,
        ]);
    }

    public function createWhatsappLink(TenantAccount $tenant, string $phone, string $message, array $context = [], ?User $user = null): array
    {
        $normalized = $this->normalizeWhatsappPhone($tenant, $phone);
        $link = 'https://wa.me/' . $normalized . '?text=' . rawurlencode($message);

        $log = $this->logWhatsappLinkCreated([
            'tenant_account_id' => $tenant->id,
            'notification_key' => $context['notification_key'] ?? null,
            'template_id' => $context['template_id'] ?? null,
            'channel' => NotificationTemplate::CHANNEL_WHATSAPP_LINK,
            'audience_type' => $context['audience_type'] ?? null,
            'recipient_type' => $context['recipient_type'] ?? 'customer',
            'recipient_name' => $context['recipient_name'] ?? null,
            'recipient_phone' => $normalized,
            'subject' => $context['subject'] ?? 'WhatsApp Hazır Mesaj',
            'message_preview' => $message,
            'dispatch_mode' => 'link',
            'meta_json' => ['url' => $link],
            'created_by' => $user?->id,
            'related_type' => $context['related_type'] ?? null,
            'related_id' => $context['related_id'] ?? null,
        ]);

        return [
            'url' => $link,
            'phone' => $normalized,
            'log' => $log,
        ];
    }

    public function dispatchInternal(TenantAccount $tenant, array $payload, ?User $user = null): NotificationLog
    {
        return $this->logSent([
            'tenant_account_id' => $tenant->id,
            'notification_key' => $payload['notification_key'] ?? null,
            'template_id' => $payload['template_id'] ?? null,
            'channel' => NotificationTemplate::CHANNEL_INTERNAL,
            'audience_type' => $payload['audience_type'] ?? null,
            'recipient_type' => $payload['recipient_type'] ?? 'team',
            'recipient_name' => $payload['recipient_name'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'message_preview' => $payload['body'] ?? null,
            'dispatch_mode' => 'manual',
            'meta_json' => $payload['meta_json'] ?? null,
            'created_by' => $user?->id,
            'related_type' => $payload['related_type'] ?? null,
            'related_id' => $payload['related_id'] ?? null,
        ]);
    }

    public function dispatch(TenantAccount $tenant, string $channel, array $payload, ?User $user = null): NotificationLog|array
    {
        return match ($channel) {
            NotificationTemplate::CHANNEL_EMAIL => $this->dispatchEmailPreview($tenant, $payload, $user),
            NotificationTemplate::CHANNEL_WHATSAPP_LINK => $this->createWhatsappLink(
                $tenant,
                (string) ($payload['recipient_phone'] ?? ''),
                (string) ($payload['body'] ?? ''),
                $payload,
                $user
            ),
            NotificationTemplate::CHANNEL_INTERNAL => $this->dispatchInternal($tenant, $payload, $user),
            default => $this->logSkipped([
                'tenant_account_id' => $tenant->id,
                'notification_key' => $payload['notification_key'] ?? null,
                'channel' => $channel,
                'recipient_type' => $payload['recipient_type'] ?? null,
                'message_preview' => $payload['body'] ?? null,
                'error_message' => 'Kanal bu fazda pasif veya desteklenmiyor.',
                'dispatch_mode' => 'manual',
                'created_by' => $user?->id,
            ]),
        };
    }

    private function sanitizeScalar(mixed $value, int $maxLength): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $value = strip_tags((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace($this->hiddenPattern(), '[hidden]', $value) ?? $value;
        $value = trim($value);

        return mb_substr($value, 0, $maxLength);
    }

    private function sanitizePreview(mixed $value): ?string
    {
        return $this->sanitizeScalar($value, 500);
    }

    private function sanitizeStructuredValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $normalizedKey = mb_strtolower((string) $key);

                if (preg_match('/smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|supplier_cost|subcontractor_cost|profit/u', $normalizedKey)) {
                    continue;
                }

                $sanitized[$key] = $this->sanitizeStructuredValue($item);
            }

            return $sanitized;
        }

        if (is_scalar($value)) {
            return $this->sanitizeScalar((string) $value, 500);
        }

        return null;
    }

    private function hiddenPattern(): string
    {
        return '/(smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|supplier_cost|subcontractor_cost|profit|storage\/app|[A-Z]:\\\\|\/var\/)/iu';
    }

    private function normalizeWhatsappPhone(TenantAccount $tenant, string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';
        $settings = $this->tenantNotificationSettingsService->getSettings($tenant);
        $countryCode = preg_replace('/\D+/', '', (string) ($settings['whatsapp_default_country_code'] ?? '90')) ?: '90';

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
}
