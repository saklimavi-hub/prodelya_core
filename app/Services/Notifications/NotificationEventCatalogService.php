<?php

namespace App\Services\Notifications;

class NotificationEventCatalogService
{
    public function events(): array
    {
        return config('prodelya_notifications.events', []);
    }

    public function getEvent(string $key): ?array
    {
        $normalizedKey = $this->normalizeEventKey($key);

        return $this->events()[$normalizedKey] ?? null;
    }

    public function normalizeEventKey(string $key): string
    {
        $normalizedKey = trim((string) $key);

        return config('prodelya_notifications.aliases.' . $normalizedKey, $normalizedKey);
    }

    public function allowedChannels(string $eventKey): array
    {
        return (array) ($this->getEvent($eventKey)['allowed_channels'] ?? []);
    }

    public function defaultAudience(string $eventKey): string
    {
        return (string) ($this->getEvent($eventKey)['default_audience'] ?? 'internal');
    }

    public function isActive(string $eventKey): bool
    {
        return ($this->getEvent($eventKey)['status'] ?? 'passive') === 'active';
    }

    public function eventOptionsForAdmin(): array
    {
        return collect($this->events())
            ->filter(fn (array $event) => ($event['status'] ?? 'passive') !== 'passive')
            ->map(fn (array $event) => [
                'key' => $event['key'],
                'label' => $event['label'],
                'category' => $event['category'],
                'status' => $event['status'],
                'default_audience' => $event['default_audience'],
            ])
            ->values()
            ->all();
    }
}
