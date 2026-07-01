<?php

namespace App\Services\System;

use App\Models\SystemHeartbeat;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SystemHeartbeatService
{
    public function touch(string $key, array $meta = []): void
    {
        if (! $this->heartbeatTableExists()) {
            return;
        }

        $heartbeat = $this->findOrNew($key);
        $heartbeat->label = $this->resolveLabel($heartbeat->label, $meta, $key);
        $heartbeat->status = $heartbeat->status ?: SystemHeartbeat::STATUS_SEEN;
        $heartbeat->last_seen_at = now();
        $heartbeat->meta_json = $this->sanitizeMeta($meta);
        $heartbeat->save();
    }

    public function success(string $key, array $meta = []): void
    {
        if (! $this->heartbeatTableExists()) {
            return;
        }

        $heartbeat = $this->findOrNew($key);
        $heartbeat->label = $this->resolveLabel($heartbeat->label, $meta, $key);
        $heartbeat->status = SystemHeartbeat::STATUS_SUCCESS;
        $heartbeat->last_seen_at = now();
        $heartbeat->last_success_at = now();
        $heartbeat->failure_count = 0;
        $heartbeat->meta_json = $this->sanitizeMeta($meta);
        $heartbeat->save();
    }

    public function failure(string $key, ?Throwable $exception = null, array $meta = []): void
    {
        if (! $this->heartbeatTableExists()) {
            return;
        }

        $heartbeat = $this->findOrNew($key);
        $heartbeat->label = $this->resolveLabel($heartbeat->label, $meta, $key);
        $heartbeat->status = SystemHeartbeat::STATUS_FAILURE;
        $heartbeat->last_seen_at = now();
        $heartbeat->last_failure_at = now();
        $heartbeat->failure_count = (int) $heartbeat->failure_count + 1;

        $safeMeta = $this->sanitizeMeta($meta);
        if ($exception instanceof Throwable) {
            $safeMeta['error_message'] = $this->safeExceptionMessage($exception->getMessage());
        }

        $heartbeat->meta_json = $safeMeta;
        $heartbeat->save();
    }

    public function get(string $key): ?SystemHeartbeat
    {
        if (! $this->heartbeatTableExists()) {
            return null;
        }

        return SystemHeartbeat::query()->where('key', $key)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusFor(string $key, int $warningAfterMinutes, int $criticalAfterMinutes): array
    {
        $heartbeat = $this->get($key);

        if (! $heartbeat instanceof SystemHeartbeat || ! $heartbeat->last_seen_at instanceof Carbon) {
            return [
                'key' => $key,
                'status' => 'unknown',
                'status_label' => 'Bilinmiyor',
                'description' => 'Heartbeat sinyali görünmedi.',
                'checked_at' => now()->format('d.m.Y H:i'),
                'last_seen_at' => null,
                'details' => [],
                'is_placeholder' => true,
                'heartbeat' => null,
            ];
        }

        $minutesSinceSeen = (int) $heartbeat->last_seen_at->diffInMinutes(now());
        $details = array_filter([
            'Son sinyal: ' . $heartbeat->last_seen_at->format('d.m.Y H:i'),
            $heartbeat->last_success_at ? 'Son başarılı çalışma: ' . $heartbeat->last_success_at->format('d.m.Y H:i') : null,
            $heartbeat->last_failure_at ? 'Son hata zamanı: ' . $heartbeat->last_failure_at->format('d.m.Y H:i') : null,
            $heartbeat->failure_count > 0 ? 'Toplam hata sayısı: ' . $heartbeat->failure_count : null,
        ]);

        if ($minutesSinceSeen >= $criticalAfterMinutes) {
            return [
                'key' => $key,
                'status' => 'critical',
                'status_label' => 'Kritik',
                'description' => 'Heartbeat sinyali beklenenden uzun süredir güncellenmedi.',
                'checked_at' => now()->format('d.m.Y H:i'),
                'last_seen_at' => $heartbeat->last_seen_at->format('d.m.Y H:i'),
                'details' => array_values($details),
                'is_placeholder' => false,
                'heartbeat' => $heartbeat,
            ];
        }

        if ($minutesSinceSeen >= $warningAfterMinutes) {
            return [
                'key' => $key,
                'status' => 'warning',
                'status_label' => 'Kontrol Gerekir',
                'description' => 'Heartbeat sinyali güncel değil; süreç doğrulanmalı.',
                'checked_at' => now()->format('d.m.Y H:i'),
                'last_seen_at' => $heartbeat->last_seen_at->format('d.m.Y H:i'),
                'details' => array_values($details),
                'is_placeholder' => false,
                'heartbeat' => $heartbeat,
            ];
        }

        if ($heartbeat->status === SystemHeartbeat::STATUS_FAILURE) {
            return [
                'key' => $key,
                'status' => $heartbeat->failure_count >= 3 ? 'critical' : 'warning',
                'status_label' => $heartbeat->failure_count >= 3 ? 'Kritik' : 'Kontrol Gerekir',
                'description' => 'Heartbeat süreci hata sinyali bildirdi.',
                'checked_at' => now()->format('d.m.Y H:i'),
                'last_seen_at' => $heartbeat->last_seen_at->format('d.m.Y H:i'),
                'details' => array_values(array_merge(
                    $details,
                    array_filter([(string) data_get($heartbeat->meta_json, 'error_message') ?: null])
                )),
                'is_placeholder' => false,
                'heartbeat' => $heartbeat,
            ];
        }

        return [
            'key' => $key,
            'status' => 'healthy',
            'status_label' => 'Sağlıklı',
            'description' => 'Heartbeat sinyali güncel görünüyor.',
            'checked_at' => now()->format('d.m.Y H:i'),
            'last_seen_at' => $heartbeat->last_seen_at->format('d.m.Y H:i'),
            'details' => array_values($details),
            'is_placeholder' => false,
            'heartbeat' => $heartbeat,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, array<string, mixed>>
     */
    public function listStatuses(array $definitions): array
    {
        $statuses = [];

        foreach ($definitions as $key => $definition) {
            $statuses[$key] = $this->statusFor(
                $key,
                (int) ($definition['warning_after_minutes'] ?? 15),
                (int) ($definition['critical_after_minutes'] ?? 30),
            );
        }

        return $statuses;
    }

    protected function findOrNew(string $key): SystemHeartbeat
    {
        return SystemHeartbeat::query()->firstOrNew(['key' => trim($key)]);
    }

    protected function heartbeatTableExists(): bool
    {
        try {
            return Schema::hasTable('system_heartbeats');
        } catch (Throwable) {
            return false;
        }
    }

    protected function resolveLabel(?string $currentLabel, array $meta, string $key): string
    {
        $label = trim((string) ($meta['label'] ?? $currentLabel ?? ''));

        return $label !== '' ? $label : Str::headline(str_replace('_', ' ', $key));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sanitizeMeta(array $meta): array
    {
        $sanitized = $this->sanitizeValue($meta);

        return is_array($sanitized) ? $sanitized : [];
    }

    protected function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return null;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $nestedKey => $nestedValue) {
                $clean = $this->sanitizeValue($nestedValue, is_string($nestedKey) ? $nestedKey : null);

                if ($clean === null || $clean === []) {
                    continue;
                }

                $result[$nestedKey] = $clean;
            }

            return $result;
        }

        if ($value instanceof Throwable) {
            return $this->safeExceptionMessage($value->getMessage());
        }

        if (is_string($value)) {
            $safe = trim(strip_tags($value));
            $safe = str_replace(base_path(), '[uygulama]', $safe);
            $safe = str_replace(storage_path(), '[storage]', $safe);

            if ($this->containsSensitiveText($safe)) {
                return 'Hassas detay gizlendi.';
            }

            return Str::limit($safe, 180);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return Str::limit(trim(strip_tags((string) $value)), 180);
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalized = Str::lower(str_replace(['-', ' '], '_', $key));

        foreach (['password', 'token', 'secret', 'api_key', 'apikey', 'smtp_password', 'trace', 'payload'] as $needle) {
            if (Str::contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function containsSensitiveText(string $text): bool
    {
        $normalized = Str::lower($text);

        foreach (['password', 'token', 'secret', 'api key', 'api_key', 'smtp_password'] as $needle) {
            if (Str::contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function safeExceptionMessage(string $message): string
    {
        $safe = trim(strip_tags($message));
        $safe = str_replace(base_path(), '[uygulama]', $safe);
        $safe = str_replace(storage_path(), '[storage]', $safe);

        if ($this->containsSensitiveText($safe)) {
            return 'Hassas detay gizlendi.';
        }

        return Str::limit($safe, 180);
    }
}
