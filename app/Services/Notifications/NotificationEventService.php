<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationEventService
{
    public function __construct(
        protected NotificationEventCatalogService $eventCatalogService,
        protected NotificationRecipientResolver $recipientResolver,
        protected NotificationTemplateService $templateService,
        protected NotificationVariableBuilder $variableBuilder,
        protected NotificationPolicyService $policyService,
        protected NotificationDispatchService $dispatchService,
        protected TenantNotificationSettingsService $notificationSettingsService,
    ) {
    }

    public function dispatchEvent(
        TenantAccount $tenant,
        string $eventKey,
        mixed $source = null,
        array $options = []
    ): array {
        $originalEventKey = trim($eventKey);
        $normalizedEventKey = $this->eventCatalogService->normalizeEventKey($originalEventKey);
        $catalogEvent = $this->eventCatalogService->getEvent($normalizedEventKey);
        $audienceType = $this->resolveAudienceType($normalizedEventKey, $catalogEvent, $options);
        $channels = $this->resolveChannels($normalizedEventKey, $catalogEvent, $options);
        $createdBy = $options['created_by'] ?? null;
        $context = $this->sanitizeContext((array) ($options['context'] ?? []), $audienceType);
        $metaContext = $this->sanitizeMetaContext($context);
        $recipients = $this->resolveRecipients($tenant, $normalizedEventKey, $audienceType, $source, $options);

        $result = [
            'event_key' => $originalEventKey,
            'normalized_event_key' => $normalizedEventKey,
            'logs' => [],
            'skipped' => [],
            'errors' => [],
            'recipients_count' => count($recipients),
            'channels' => $channels,
        ];

        if (empty($channels)) {
            return $result;
        }

        if (empty($recipients)) {
            foreach ($channels as $channel) {
                $log = $this->dispatchService->logSkipped([
                    'tenant_account_id' => $tenant->id,
                    'notification_key' => $normalizedEventKey,
                    'channel' => $channel,
                    'audience_type' => $audienceType,
                    'recipient_type' => 'unresolved',
                    'error_message' => 'Alıcı bulunamadı.',
                    'meta_json' => $this->buildMeta($normalizedEventKey, $originalEventKey, $audienceType, $channel, $source, [], $metaContext),
                    'created_by' => $createdBy instanceof User ? $createdBy->id : null,
                    'related_type' => $options['related_type'] ?? $this->resolveRelatedType($source),
                    'related_id' => $options['related_id'] ?? $this->resolveRelatedId($source),
                ]);

                $result['logs'][] = $log;
                $result['skipped'][] = $channel;
            }

            return $result;
        }

        foreach ($channels as $channel) {
            foreach ($recipients as $recipient) {
                $policySkipped = !$this->policyService->canNotify($normalizedEventKey, $channel, $audienceType, $tenant);

                if ($policySkipped) {
                    $log = $this->dispatchService->logSkipped([
                        'tenant_account_id' => $tenant->id,
                        'notification_key' => $normalizedEventKey,
                        'channel' => $channel,
                        'audience_type' => $audienceType,
                        'recipient_type' => $recipient['type'] ?? null,
                        'recipient_name' => $recipient['name'] ?? null,
                        'recipient_email' => $recipient['email'] ?? null,
                        'recipient_phone' => $recipient['phone'] ?? null,
                        'error_message' => 'Kanal veya hedef kitle ayarları nedeniyle bildirim atlandı.',
                        'meta_json' => $this->buildMeta($normalizedEventKey, $originalEventKey, $audienceType, $channel, $source, $recipient, $metaContext),
                        'created_by' => $createdBy instanceof User ? $createdBy->id : null,
                        'related_type' => $options['related_type'] ?? $this->resolveRelatedType($source),
                        'related_id' => $options['related_id'] ?? $this->resolveRelatedId($source),
                    ]);

                    $result['logs'][] = $log;
                    $result['skipped'][] = $channel;
                    continue;
                }

                if ($this->isRecipientMissingForChannel($channel, $recipient)) {
                    $log = $this->dispatchService->logSkipped([
                        'tenant_account_id' => $tenant->id,
                        'notification_key' => $normalizedEventKey,
                        'channel' => $channel,
                        'audience_type' => $audienceType,
                        'recipient_type' => $recipient['type'] ?? null,
                        'recipient_name' => $recipient['name'] ?? null,
                        'recipient_email' => $recipient['email'] ?? null,
                        'recipient_phone' => $recipient['phone'] ?? null,
                        'error_message' => 'Seçilen kanal için uygun alıcı bilgisi bulunamadı.',
                        'meta_json' => $this->buildMeta($normalizedEventKey, $originalEventKey, $audienceType, $channel, $source, $recipient, $metaContext),
                        'created_by' => $createdBy instanceof User ? $createdBy->id : null,
                        'related_type' => $options['related_type'] ?? $this->resolveRelatedType($source),
                        'related_id' => $options['related_id'] ?? $this->resolveRelatedId($source),
                    ]);

                    $result['logs'][] = $log;
                    $result['skipped'][] = $channel;
                    continue;
                }

                $template = $this->templateService->findTemplate($tenant, $normalizedEventKey, $channel, $audienceType);
                $variables = array_merge(
                    $this->variableBuilder->buildForSource($source, $audienceType),
                    $context
                );

                $rendered = $template
                    ? $this->templateService->render($template, $variables, $audienceType)
                    : [
                        'subject' => (string) ($catalogEvent['default_template_subject'] ?? ucfirst(str_replace('_', ' ', $normalizedEventKey))),
                        'body' => (string) ($catalogEvent['default_template_body'] ?? ucfirst(str_replace('_', ' ', $normalizedEventKey))),
                        'blocked_variables' => [],
                        'missing_variables' => [],
                    ];

                if ($normalizedEventKey === 'quote_sent_to_customer' && $channel === NotificationTemplate::CHANNEL_WHATSAPP_LINK) {
                    $rendered['body'] = $this->normalizeQuoteWhatsappBody((string) ($rendered['body'] ?? ''), $variables);
                }

                $payload = [
                    'notification_key' => $normalizedEventKey,
                    'template_id' => $template?->id,
                    'channel' => $channel,
                    'audience_type' => $audienceType,
                    'recipient_type' => $recipient['type'] ?? null,
                    'recipient_name' => $recipient['name'] ?? null,
                    'recipient_email' => $recipient['email'] ?? null,
                    'recipient_phone' => $recipient['phone'] ?? null,
                    'subject' => $rendered['subject'],
                    'body' => $rendered['body'],
                    'meta_json' => $this->buildMeta($normalizedEventKey, $originalEventKey, $audienceType, $channel, $source, $recipient, array_merge($metaContext, [
                        'blocked_variables' => $rendered['blocked_variables'] ?? [],
                        'missing_variables' => $rendered['missing_variables'] ?? [],
                    ])),
                    'created_by' => $createdBy instanceof User ? $createdBy->id : null,
                    'related_type' => $options['related_type'] ?? $this->resolveRelatedType($source),
                    'related_id' => $options['related_id'] ?? $this->resolveRelatedId($source),
                ];

                $dispatched = $this->dispatchService->dispatch($tenant, $channel, $payload, $createdBy instanceof User ? $createdBy : null);
                $log = is_array($dispatched) ? ($dispatched['log'] ?? null) : $dispatched;

                if ($log instanceof NotificationLog) {
                    $result['logs'][] = $log;
                }
            }
        }

        return $result;
    }

    private function normalizeQuoteWhatsappBody(string $body, array $variables): string
    {
        $publicUrl = trim((string) ($variables['public_quote_url'] ?? $variables['public_quote_approval_url'] ?? ''));

        if ($publicUrl === '' || ! preg_match('/^https?:\/\//i', $publicUrl)) {
            return $body;
        }

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", trim(strip_tags($body)));
        $withoutUrl = preg_replace('/\s*' . preg_quote($publicUrl, '/') . '\s*/u', "\n", $normalizedBody, 1) ?? $normalizedBody;
        $withoutUrl = preg_replace("/\n{3,}/u", "\n\n", trim($withoutUrl)) ?? trim($withoutUrl);

        return $withoutUrl === ''
            ? $publicUrl
            : rtrim($withoutUrl) . "\n" . $publicUrl;
    }

    private function resolveAudienceType(string $eventKey, ?array $catalogEvent, array $options): string
    {
        $audience = trim((string) ($options['audience_type'] ?? ''));

        if ($audience !== '') {
            return $audience;
        }

        if ($catalogEvent) {
            return $this->eventCatalogService->defaultAudience($eventKey);
        }

        return NotificationTemplate::AUDIENCE_INTERNAL;
    }

    private function resolveChannels(string $eventKey, ?array $catalogEvent, array $options): array
    {
        $channels = array_values(array_filter((array) ($options['channels'] ?? [])));

        if (empty($channels) && $catalogEvent) {
            $channels = $this->eventCatalogService->allowedChannels($eventKey);
        }

        return array_values(array_unique(array_map(
            fn ($channel) => trim((string) $channel),
            $channels
        )));
    }

    private function resolveRecipients(TenantAccount $tenant, string $eventKey, string $audienceType, mixed $source, array $options): array
    {
        $override = $options['recipient_override'] ?? null;

        if (is_array($override) && array_is_list($override) && !empty($override)) {
            return $this->normalizeRecipients($override, $audienceType);
        }

        if (is_array($override) && !array_is_list($override) && !empty($override)) {
            return $this->normalizeRecipients([$override], $audienceType);
        }

        $resolved = $this->recipientResolver->resolve($eventKey, $audienceType, $source);

        if (!empty($resolved)) {
            return $resolved;
        }

        return $this->recipientResolver->fallbackRecipients($tenant);
    }

    private function normalizeRecipients(array $recipients, string $audienceType): array
    {
        return collect($recipients)
            ->filter(fn ($recipient) => is_array($recipient))
            ->map(function (array $recipient) use ($audienceType): array {
                return [
                    'type' => $recipient['type'] ?? 'other',
                    'name' => $recipient['name'] ?? null,
                    'email' => $recipient['email'] ?? null,
                    'phone' => $recipient['phone'] ?? null,
                    'user_id' => $recipient['user_id'] ?? null,
                    'company_id' => $recipient['company_id'] ?? null,
                    'audience_type' => $recipient['audience_type'] ?? $audienceType,
                ];
            })
            ->values()
            ->all();
    }

    private function isRecipientMissingForChannel(string $channel, array $recipient): bool
    {
        return match ($channel) {
            NotificationTemplate::CHANNEL_EMAIL => !filled($recipient['email'] ?? null),
            NotificationTemplate::CHANNEL_WHATSAPP_LINK => !filled($recipient['phone'] ?? null),
            NotificationTemplate::CHANNEL_INTERNAL => !filled($recipient['user_id'] ?? null) && !filled($recipient['email'] ?? null),
            default => false,
        };
    }

    private function buildMeta(
        string $normalizedEventKey,
        string $originalEventKey,
        string $audienceType,
        string $channel,
        mixed $source,
        array $recipient,
        array $context
    ): array {
        return array_filter([
            'normalized_event_key' => $normalizedEventKey,
            'original_event_key' => $originalEventKey,
            'audience_type' => $audienceType,
            'channel' => $channel,
            'source_type' => $this->resolveRelatedType($source),
            'source_id' => $this->resolveRelatedId($source),
            'recipient_type' => $recipient['type'] ?? null,
            'recipient_user_id' => $recipient['user_id'] ?? null,
            'recipient_company_id' => $recipient['company_id'] ?? null,
            'context' => $context,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function sanitizeContext(array $context, string $audienceType): array
    {
        $forbiddenKeys = $this->templateService->forbiddenVariablesForAudience($audienceType);
        $alwaysHidden = [
            'smtp_password',
            'api_key',
            'token',
            'file_path',
            'physical_path',
            'group_code',
            'pdh_raw',
            'raw_xml',
            'raw_json',
        ];
        $blocked = array_flip(array_unique(array_map('mb_strtolower', array_merge($forbiddenKeys, $alwaysHidden))));

        return collect($context)
            ->filter(function ($value, $key) use ($blocked) {
                return !array_key_exists(mb_strtolower((string) $key), $blocked)
                    && !is_array($value)
                    && !is_object($value);
            })
            ->map(function ($value) {
                $string = trim(strip_tags((string) $value));
                $string = preg_replace('/(smtp_password|api_key|token|file_path|physical_path|group_code|pdh_raw|raw_xml|raw_json)/iu', '', $string) ?? $string;

                return trim($string);
            })
            ->filter(fn ($value) => $value !== '')
            ->all();
    }

    private function sanitizeMetaContext(array $context): array
    {
        $hiddenKeys = [
            'public_quote_url',
            'public_tracking_url',
            'public_graphic_approval_url',
        ];
        $blocked = array_flip(array_map('mb_strtolower', $hiddenKeys));

        return collect($context)
            ->reject(function ($value, $key) use ($blocked) {
                return array_key_exists(mb_strtolower((string) $key), $blocked);
            })
            ->map(function ($value) {
                $string = trim((string) $value);

                if (preg_match('/\/(teklif\/onay|takip\/is-formu)\//iu', $string)) {
                    return '[hidden-public-link]';
                }

                return $string;
            })
            ->all();
    }

    private function resolveRelatedType(mixed $source): ?string
    {
        return $source instanceof Model ? $source->getMorphClass() : null;
    }

    private function resolveRelatedId(mixed $source): mixed
    {
        return $source instanceof Model ? $source->getKey() : null;
    }
}
