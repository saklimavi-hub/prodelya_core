<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;

class NotificationPolicyService
{
    public function __construct(
        protected TenantNotificationSettingsService $settingsService,
        protected NotificationEventCatalogService $eventCatalogService,
    ) {}

    public function canNotify(string $notificationKey, string $channel, string $audienceType, ?TenantAccount $tenant = null): bool
    {
        $notificationKey = $this->eventCatalogService->normalizeEventKey($notificationKey);

        if ($this->shouldSkip($notificationKey, $channel, $audienceType, $tenant)) {
            return false;
        }

        if ($tenant === null) {
            return $channel !== NotificationTemplate::CHANNEL_SMS;
        }

        return $this->isChannelEnabled($tenant, $channel);
    }

    public function isCustomerFacing(string $notificationKey): bool
    {
        $event = $this->eventCatalogService->getEvent($notificationKey);

        return ($event['default_audience'] ?? null) === NotificationTemplate::AUDIENCE_CUSTOMER;
    }

    public function isInternalOnly(string $notificationKey): bool
    {
        $event = $this->eventCatalogService->getEvent($notificationKey);

        return ($event['default_audience'] ?? null) === NotificationTemplate::AUDIENCE_INTERNAL
            || ($event['default_audience'] ?? null) === NotificationTemplate::AUDIENCE_ADMIN;
    }

    public function isChannelEnabled(?TenantAccount $tenant, string $channel): bool
    {
        if ($channel === NotificationTemplate::CHANNEL_SMS) {
            return false;
        }

        if ($tenant === null) {
            return $channel !== NotificationTemplate::CHANNEL_SMS;
        }

        return match ($channel) {
            NotificationTemplate::CHANNEL_EMAIL => $this->settingsService->isEmailEnabled($tenant),
            NotificationTemplate::CHANNEL_WHATSAPP_LINK => $this->settingsService->isWhatsappEnabled($tenant),
            NotificationTemplate::CHANNEL_INTERNAL => (bool) ($this->settingsService->getSettings($tenant)['internal_notification_enabled'] ?? true),
            default => false,
        };
    }

    public function shouldSkip(string $notificationKey, string $channel, string $audienceType, ?TenantAccount $tenant = null): bool
    {
        $notificationKey = $this->eventCatalogService->normalizeEventKey($notificationKey);
        $event = $this->eventCatalogService->getEvent($notificationKey);

        if (!$event || !$this->eventCatalogService->isActive($notificationKey)) {
            return true;
        }

        if ($channel === NotificationTemplate::CHANNEL_SMS) {
            return true;
        }

        if (!in_array($channel, $this->eventCatalogService->allowedChannels($notificationKey), true)) {
            return true;
        }

        if ($audienceType === NotificationTemplate::AUDIENCE_CUSTOMER) {
            if ($this->isInternalOnly($notificationKey) || ($event['category'] ?? null) === 'finance') {
                return true;
            }

            if (!$this->isCustomerFacing($notificationKey)) {
                return true;
            }
        }

        if ($audienceType === NotificationTemplate::AUDIENCE_FINANCE
            && ($event['category'] ?? null) !== 'finance') {
            return true;
        }

        if ($tenant !== null) {
            $settings = $this->settingsService->getSettings($tenant);

            if ($audienceType === NotificationTemplate::AUDIENCE_CUSTOMER
                && !(bool) ($settings['customer_notification_enabled'] ?? true)) {
                return true;
            }

            if ($audienceType !== NotificationTemplate::AUDIENCE_CUSTOMER
                && !(bool) ($settings['internal_notification_enabled'] ?? true)) {
                return true;
            }
        }

        return false;
    }
}
