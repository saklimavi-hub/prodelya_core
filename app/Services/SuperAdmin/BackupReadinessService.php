<?php

namespace App\Services\SuperAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackupReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function buildBackupStatus(): array
    {
        $checkedAt = now()->format('d.m.Y H:i');
        $enabled = (bool) config('prodelya_backup.enabled', true);
        $monitoredPaths = collect(config('prodelya_backup.monitored_paths', []))
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['path'] ?? null))
            ->values();

        $locations = $monitoredPaths->map(function (array $definition): array {
            $directory = (string) $definition['path'];
            $exists = File::isDirectory($directory);
            $files = [];

            if ($exists) {
                foreach (File::allFiles($directory) as $file) {
                    $maskedName = $this->maskFileName($file->getFilename());
                    $mtime = $file->getMTime();

                    $files[] = [
                        'name' => $maskedName,
                        'mtime' => $mtime,
                        'effective_mtime' => $this->effectiveTimestamp($maskedName, $mtime),
                        'size' => $file->getSize(),
                        'label' => (string) ($definition['label'] ?? 'Yedek kaynağı'),
                        'scope' => (string) ($definition['scope'] ?? 'general_backup'),
                    ];
                }
            }

            return [
                'key' => (string) ($definition['key'] ?? 'backup'),
                'label' => (string) ($definition['label'] ?? 'Yedek kaynağı'),
                'scope' => (string) ($definition['scope'] ?? 'general_backup'),
                'exists' => $exists,
                'files' => $files,
            ];
        });

        $allFiles = $locations->pluck('files')->flatten(1)->sortByDesc('effective_mtime')->values();
        $generalFiles = $allFiles->filter(fn (array $file): bool => ($file['scope'] ?? null) === 'general_backup')->values();
        $newest = $allFiles->first();

        $expectedHours = max(1, (int) config('prodelya_backup.expected_frequency_hours', 24));
        $warningAfterHours = max($expectedHours, (int) config('prodelya_backup.warning_after_hours', 30));
        $criticalAfterHours = max($warningAfterHours, (int) config('prodelya_backup.critical_after_hours', 48));

        $warnings = [];
        $details = [
            'İzlenen kaynak sayısı: ' . $locations->count(),
            'Bulunan yedek dosyası: ' . $allFiles->count(),
            'Politika özeti: beklenen en fazla ' . $expectedHours . ' saatte bir yeni yedek.',
        ];

        if (! $enabled) {
            return [
                'status' => 'unknown',
                'status_label' => 'Bilinmiyor',
                'last_backup_at' => null,
                'age_hours' => null,
                'monitored_locations_count' => $locations->count(),
                'found_backup_files_count' => $allFiles->count(),
                'newest_backup_display' => null,
                'policy_summary' => 'Yedekleme görünürlüğü config üzerinden kapalı.',
                'warnings' => ['Yedekleme görünürlüğü kapalı; canlıda ayrıca doğrulanmalı.'],
                'details' => $details,
                'is_placeholder' => true,
                'checked_at' => $checkedAt,
            ];
        }

        if ($newest === null) {
            return [
                'status' => 'unknown',
                'status_label' => 'Bilinmiyor',
                'last_backup_at' => null,
                'age_hours' => null,
                'monitored_locations_count' => $locations->count(),
                'found_backup_files_count' => 0,
                'newest_backup_display' => null,
                'policy_summary' => 'İzlenen kaynaklarda erişilebilir yedek dosyası bulunamadı.',
                'warnings' => ['Genel uygulama ve veritabanı yedeği ayrıca doğrulanmalıdır.'],
                'details' => array_merge($details, ['En yeni yedek: bulunamadı']),
                'is_placeholder' => false,
                'checked_at' => $checkedAt,
            ];
        }

        $newestAt = Carbon::createFromTimestamp((int) ($newest['effective_mtime'] ?? $newest['mtime']));
        $ageHours = abs((int) $newestAt->diffInHours(now(), false));
        $newestDisplay = ($newest['label'] ?? 'Yedek kaynağı') . ' · ' . ($newest['name'] ?? 'yedek');

        $details[] = 'En yeni yedek: ' . $newestDisplay;
        $details[] = 'En yeni yedek zamanı: ' . $newestAt->format('d.m.Y H:i');
        $details[] = 'Yaklaşık yaş: ' . $ageHours . ' saat';
        $details[] = 'En yeni dosya boyutu: ' . $this->formatBytes((int) ($newest['size'] ?? 0));

        $status = 'healthy';
        $policySummary = 'Genel uygulama yedeği beklenen tazelik içinde görünüyor.';

        if ($generalFiles->isEmpty()) {
            $status = 'warning';
            $policySummary = 'Yalnız Product Data Hub kategori yedeği bulundu; genel uygulama/veritabanı yedeği ayrıca doğrulanmalıdır.';
            $warnings[] = 'Product Data Hub kategori yedeği genel uygulama yedeğinin yerine geçmez.';
        } elseif ($ageHours >= $criticalAfterHours) {
            $status = 'critical';
            $policySummary = 'Genel yedek çok eski görünüyor; canlıya çıkmadan önce yeni yedek doğrulanmalıdır.';
            $warnings[] = 'Yedek tazeliği kritik eşiği aştı.';
        } elseif ($ageHours >= $warningAfterHours) {
            $status = 'warning';
            $policySummary = 'Genel yedek birkaç saatlik tolerans eşiğini geçti; operasyon ekibi kontrol etmelidir.';
            $warnings[] = 'Yedek tazeliği kontrol gerektiriyor.';
        }

        if ($locations->contains(fn (array $location): bool => ! $location['exists'])) {
            $warnings[] = 'İzlenen yedek kaynaklarından en az biri erişilebilir görünmüyor.';
        }

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'last_backup_at' => $newestAt->format('d.m.Y H:i'),
            'age_hours' => $ageHours,
            'monitored_locations_count' => $locations->count(),
            'found_backup_files_count' => $allFiles->count(),
            'newest_backup_display' => $newestDisplay,
            'policy_summary' => $policySummary,
            'warnings' => array_values(array_unique($warnings)),
            'details' => array_values(array_unique($details)),
            'is_placeholder' => false,
            'checked_at' => $checkedAt,
        ];
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'healthy' => 'Sağlıklı',
            'warning' => 'Kontrol Gerekir',
            'critical' => 'Kritik',
            default => 'Bilinmiyor',
        };
    }

    protected function maskFileName(string $fileName): string
    {
        $safe = trim(strip_tags($fileName));

        if ($safe === '') {
            return 'yedek-dosyasi';
        }

        $extension = pathinfo($safe, PATHINFO_EXTENSION);
        $name = pathinfo($safe, PATHINFO_FILENAME);

        return Str::limit($name, 24, '...') . ($extension !== '' ? '.' . $extension : '');
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : 1, ',', '.') . ' ' . $units[$power];
    }

    protected function effectiveTimestamp(string $maskedName, int $mtime): int
    {
        $inferred = $this->inferTimestampFromName($maskedName);

        if ($inferred === null) {
            return $mtime;
        }

        return min($mtime, $inferred);
    }

    protected function inferTimestampFromName(string $fileName): ?int
    {
        if (!preg_match('/\b(20\d{2})[-_]?(\d{2})[-_]?(\d{2})\b/', $fileName, $matches)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%s-%s-%s 00:00:00', $matches[1], $matches[2], $matches[3]))
                ->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }
}
