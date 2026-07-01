<?php

namespace App\Services\SuperAdmin;

use App\Services\System\SystemHeartbeatService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SuperAdminSystemHealthService
{
    public function __construct(
        private readonly SystemHeartbeatService $heartbeatService,
        private readonly BackupReadinessService $backupReadinessService,
        private readonly StorageReadinessService $storageReadinessService,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildHealthContext(): array
    {
        return [
            'queue_worker' => $this->safeHealthItem('queue_worker', 'Kuyruk Çalışanı', fn (): array => $this->buildQueueWorkerHealthItem()),
            'scheduler' => $this->safeHealthItem('scheduler', 'Zamanlayıcı', fn (): array => $this->buildSchedulerHealthItem()),
            'failed_jobs' => $this->safeHealthItem('failed_jobs', 'Başarısız İşler', fn (): array => $this->buildFailedJobsHealthItem()),
            'backup' => $this->safeHealthItem('backup', 'Son Yedekleme', fn (): array => $this->buildBackupHealthItem()),
            'disk_usage' => $this->safeHealthItem('disk_usage', 'Disk Kullanımı', fn (): array => $this->buildDiskUsageHealthItem()),
            'database' => $this->safeHealthItem('database', 'Veritabanı', fn (): array => $this->buildDatabaseHealthItem()),
            'cache' => $this->safeHealthItem('cache', 'Önbellek / Redis', fn (): array => $this->buildCacheHealthItem()),
            'storage_link' => $this->safeHealthItem('storage_link', 'Depolama Bağlantısı', fn (): array => $this->buildStorageLinkHealthItem()),
            'log_errors' => $this->safeHealthItem('log_errors', 'Log Hataları', fn (): array => $this->buildLogErrorsHealthItem()),
            'php_compatibility' => $this->safeHealthItem('php_compatibility', 'PHP Uyumu', fn (): array => $this->buildPhpCompatibilityHealthItem()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildQueueWorkerHealthItem(): array
    {
        $queueDefault = (string) Config::get('queue.default', 'sync');
        $hasJobsTable = Schema::hasTable('jobs');
        $hasFailedJobsTable = Schema::hasTable('failed_jobs');
        $pendingJobs = $hasJobsTable ? (int) DB::table('jobs')->count() : null;
        $failedJobs = $hasFailedJobsTable ? (int) DB::table('failed_jobs')->count() : null;
        $lastQueuedAt = $hasJobsTable ? DB::table('jobs')->max('created_at') : null;
        $heartbeat = $this->heartbeatService->statusFor('queue_worker', 15, 45);

        if ($queueDefault === 'sync') {
            return $this->healthItem(
                'queue_worker',
                'Kuyruk Çalışanı',
                'healthy',
                'Kuyruk bağlantısı senkron modda. Ayrı kuyruk çalışanı beklenmiyor.',
                false,
                [
                    'Bağlantı: senkron',
                    'Bekleyen iş kuyruğu kullanılmıyor.',
                ]
            );
        }

        $status = $heartbeat['status'];
        $description = 'Kuyruk heartbeat sinyali doğrulanamadı.';
        $isPlaceholder = (bool) ($heartbeat['is_placeholder'] ?? false);

        if ($heartbeat['status'] === 'healthy') {
            $description = 'Kuyruk çalışanı heartbeat sinyali güncel görünüyor.';
        } elseif ($heartbeat['status'] === 'warning') {
            $description = 'Kuyruk çalışanı sinyali güncel değil; worker süreci doğrulanmalı.';
        } elseif ($heartbeat['status'] === 'critical') {
            $description = 'Kuyruk çalışanı heartbeat sinyali kritik eşik dışında kaldı.';
        }

        if (!$hasJobsTable && !$hasFailedJobsTable) {
            $status = $heartbeat['status'] === 'healthy' ? 'warning' : $heartbeat['status'];
            $description = 'Kuyruk tabloları görünmüyor; worker sinyali ve ortam kurulumu birlikte doğrulanmalı.';
        }

        if (($failedJobs ?? 0) >= 20 || ($pendingJobs ?? 0) >= 250) {
            $status = 'critical';
            $description = 'Kuyrukta bekleyen veya başarısız iş sayısı kritik eşiğe yaklaştı.';
        } elseif ($status === 'healthy' && (($failedJobs ?? 0) > 0 || ($pendingJobs ?? 0) > 0)) {
            $status = 'warning';
            $description = 'Kuyruk heartbeat sinyali güncel, ancak bekleyen veya başarısız iş sayısı kontrol gerektiriyor.';
        }

        return $this->healthItem(
            'queue_worker',
            'Kuyruk Çalışanı',
            $status,
            $description,
            $isPlaceholder,
            array_filter([
                'Bağlantı: ' . $queueDefault,
                $heartbeat['last_seen_at'] ? 'Son heartbeat: ' . $heartbeat['last_seen_at'] : 'Heartbeat kaydı görünmüyor.',
                $pendingJobs !== null ? 'Bekleyen iş: ' . $pendingJobs : null,
                $failedJobs !== null ? 'Toplam başarısız iş: ' . $failedJobs : null,
                $lastQueuedAt ? 'Son kuyruk kaydı: ' . $this->formatTimestampValue($lastQueuedAt) : 'Son kuyruk zamanı alınamadı.',
                ...array_slice((array) ($heartbeat['details'] ?? []), 0, 2),
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSchedulerHealthItem(): array
    {
        $schedulerHeartbeat = $this->heartbeatService->statusFor('scheduler', 10, 20);
        $hourlyHeartbeat = $this->heartbeatService->statusFor('product_data_hub_hourly', 90, 180);
        $dailyHeartbeat = $this->heartbeatService->statusFor('product_data_hub_daily', 1560, 2880);
        $weeklyHeartbeat = $this->heartbeatService->statusFor('product_data_hub_weekly', 11520, 14400);

        $status = $schedulerHeartbeat['status'];
        $description = match ($schedulerHeartbeat['status']) {
            'healthy' => 'Zamanlayıcı heartbeat sinyali güncel görünüyor.',
            'warning' => 'Zamanlayıcı heartbeat sinyali güncel değil; cron veya görev zamanlayıcısı doğrulanmalı.',
            'critical' => 'Zamanlayıcı heartbeat sinyali kritik eşik dışında kaldı.',
            default => 'Zamanlayıcı heartbeat sinyali henüz görünmedi.',
        };

        return $this->healthItem(
            'scheduler',
            'Zamanlayıcı',
            $status,
            $description,
            (bool) ($schedulerHeartbeat['is_placeholder'] ?? false),
            array_filter([
                $schedulerHeartbeat['last_seen_at'] ? 'Son scheduler heartbeat: ' . $schedulerHeartbeat['last_seen_at'] : 'Scheduler heartbeat görünmüyor.',
                'Product Data Hub saatlik: ' . ($hourlyHeartbeat['status_label'] ?? 'Bilinmiyor'),
                'Product Data Hub günlük: ' . ($dailyHeartbeat['status_label'] ?? 'Bilinmiyor'),
                'Product Data Hub haftalık: ' . ($weeklyHeartbeat['status_label'] ?? 'Bilinmiyor'),
                $hourlyHeartbeat['last_seen_at'] ? 'Saatlik son sinyal: ' . $hourlyHeartbeat['last_seen_at'] : null,
                $dailyHeartbeat['last_seen_at'] ? 'Günlük son sinyal: ' . $dailyHeartbeat['last_seen_at'] : null,
                $weeklyHeartbeat['last_seen_at'] ? 'Haftalık son sinyal: ' . $weeklyHeartbeat['last_seen_at'] : null,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildFailedJobsHealthItem(): array
    {
        if (!Schema::hasTable('failed_jobs')) {
            return $this->healthItem(
                'failed_jobs',
                'Başarısız İşler',
                'unknown',
                'Başarısız iş tablosu görünmüyor. Son hata sinyali okunamadı.',
                false,
                [
                    'failed_jobs tablosu bu ortamda erişilebilir değil.',
                ]
            );
        }

        $last24Hours = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();
        $last7Days = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(7))
            ->count();
        $total = (int) DB::table('failed_jobs')->count();
        $lastFailedAt = DB::table('failed_jobs')->max('failed_at');

        $status = 'healthy';
        $description = 'Son 24 saatte başarısız iş görünmüyor.';

        if ($last24Hours >= 10 || $last7Days >= 30) {
            $status = 'critical';
            $description = 'Başarısız iş sayısı kritik eşiğe ulaştı.';
        } elseif ($last24Hours > 0 || $last7Days > 0 || $total > 0) {
            $status = 'warning';
            $description = 'Son 24 saatte ' . $last24Hours . ' başarısız iş var.';
        }

        return $this->healthItem(
            'failed_jobs',
            'Başarısız İşler',
            $status,
            $description,
            false,
            array_filter([
                'Son 24 saat: ' . $last24Hours,
                'Son 7 gün: ' . $last7Days,
                'Toplam kayıt: ' . $total,
                $lastFailedAt ? 'Son hata zamanı: ' . $this->formatTimestampValue($lastFailedAt) : 'Son hata zamanı alınamadı.',
                'Retry işlemi dashboard üzerinden yapılmaz; hata sebebi ayrıca incelenmelidir.',
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildBackupHealthItem(): array
    {
        $backup = $this->backupReadinessService->buildBackupStatus();

        return $this->healthItem(
            'backup',
            'Son Yedekleme',
            (string) ($backup['status'] ?? 'unknown'),
            (string) ($backup['policy_summary'] ?? $backup['status_label'] ?? 'Yedekleme görünürlüğü hazırlanamadı.'),
            (bool) ($backup['is_placeholder'] ?? false),
            array_values(array_filter(array_merge(
                (array) ($backup['details'] ?? []),
                (array) ($backup['warnings'] ?? [])
            )))
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDiskUsageHealthItem(): array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (!is_numeric($total) || !is_numeric($free) || $total <= 0) {
            return $this->healthItem(
                'disk_usage',
                'Disk Kullanımı',
                'unknown',
                'Disk alanı bilgisi bu ortamda okunamadı.',
                false,
                ['Sunucu disk kullanımı ayrıca doğrulanmalı.']
            );
        }

        $usedPercent = max(0, min(100, (int) round((1 - ($free / $total)) * 100)));
        $status = $usedPercent > 90 ? 'critical' : ($usedPercent >= 75 ? 'warning' : 'healthy');

        return $this->healthItem(
            'disk_usage',
            'Disk Kullanımı',
            $status,
            'Uygulamanın bulunduğu diskin yaklaşık %' . $usedPercent . ' bölümü kullanılıyor.',
            false,
            [
                'Boş alan: ' . $this->formatBytes((int) $free),
                'Toplam alan: ' . $this->formatBytes((int) $total),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDatabaseHealthItem(): array
    {
        $connectionName = (string) Config::get('database.default', 'sqlite');
        DB::connection()->select('select 1 as health_ok');

        $details = ['Bağlantı: ' . $connectionName];
        if (Schema::hasTable('migrations')) {
            $details[] = 'Migrations tablosu okunabiliyor.';
        }

        return $this->healthItem(
            'database',
            'Veritabanı',
            'healthy',
            'Veritabanı bağlantısı ve temel sorgu başarılı.',
            false,
            $details
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCacheHealthItem(): array
    {
        $store = (string) Config::get('cache.default', 'file');

        if ($store === 'redis') {
            $redisConnection = (string) Config::get('cache.stores.redis.connection', 'cache');

            try {
                $response = Redis::connection($redisConnection)->ping();
                $normalized = strtoupper((string) $response);
                $healthy = $normalized === '1' || $normalized === 'PONG' || $normalized === '+PONG';

                return $this->healthItem(
                    'cache',
                    'Önbellek / Redis',
                    $healthy ? 'healthy' : 'warning',
                    $healthy
                        ? 'Redis önbellek bağlantısı yanıt veriyor.'
                        : 'Redis önbellek bağlantısı beklenen yanıtı vermedi.',
                    false,
                    [
                        'Sürücü: redis',
                        'Bağlantı adı: ' . $redisConnection,
                    ]
                );
            } catch (\Throwable $exception) {
                return $this->healthItem(
                    'cache',
                    'Önbellek / Redis',
                    'critical',
                    'Redis önbellek bağlantısı doğrulanamadı.',
                    false,
                    [
                        'Sürücü: redis',
                        'Hassas detay gizlendi.',
                    ]
                );
            }
        }

        $description = match ($store) {
            'file' => 'Önbellek sürücüsü dosya tabanlı. Canlı yüksek trafik için Redis ayrıca değerlendirilebilir.',
            'database' => 'Önbellek sürücüsü veritabanı tabanlı. Ek ölçek ihtiyacı varsa Redis düşünülebilir.',
            default => 'Önbellek sürücüsü ' . $store . ' olarak ayarlı.',
        };

        return $this->healthItem(
            'cache',
            'Önbellek / Redis',
            'healthy',
            $description,
            false,
            ['Sürücü: ' . $store]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStorageLinkHealthItem(): array
    {
        $storage = $this->storageReadinessService->buildStorageStatus();

        return $this->healthItem(
            'storage_link',
            'Depolama Bağlantısı',
            (string) ($storage['status'] ?? 'unknown'),
            match ($storage['status'] ?? 'unknown') {
                'healthy' => 'Depolama bağlantıları ve temel yazılabilir alanlar hazır görünüyor.',
                'critical' => 'Depolama hazırlığında kritik eksik görünüyor.',
                default => 'Depolama bağlantısı veya görünürlük kontrollerinden en az biri doğrulanmalı.',
            },
            false,
            array_values(array_filter(array_merge(
                collect((array) ($storage['checks'] ?? []))
                    ->map(fn (array $check): string => ($check['label'] ?? 'Kontrol') . ': ' . ($check['status_label'] ?? 'Bilinmiyor'))
                    ->take(6)
                    ->all(),
                (array) ($storage['warnings'] ?? [])
            )))
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildLogErrorsHealthItem(): array
    {
        $latestLog = $this->latestLogFile();

        if ($latestLog === null) {
            return $this->healthItem(
                'log_errors',
                'Log Hataları',
                'unknown',
                'Laravel log dosyası görünmüyor veya okunamıyor.',
                false,
                ['Log görünürlüğü sunucuda ayrıca doğrulanmalı.']
            );
        }

        $errorCount = $this->countErrorsInTail($latestLog['path']);
        $fileSize = (int) $latestLog['size'];
        $status = $fileSize > 50 * 1024 * 1024 || $errorCount >= 20
            ? 'critical'
            : (($errorCount > 0 || $fileSize > 10 * 1024 * 1024) ? 'warning' : 'healthy');

        $description = $errorCount > 0
            ? 'Son log dosyasının incelenen bölümünde ' . $errorCount . ' hata kaydı izi görüldü.'
            : 'Son log dosyasında belirgin hata yoğunluğu görünmüyor.';

        return $this->healthItem(
            'log_errors',
            'Log Hataları',
            $status,
            $description,
            false,
            [
                'Son güncelleme: ' . $this->formatTimestampValue($latestLog['mtime']),
                'Dosya boyutu: ' . $this->formatBytes($fileSize),
                'İncelenen bölüm: son ' . $this->formatBytes(min($fileSize, 262144)),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPhpCompatibilityHealthItem(): array
    {
        $status = version_compare(PHP_VERSION, '8.2.0', '>=') ? 'warning' : 'critical';
        $description = version_compare(PHP_VERSION, '8.2.0', '>=')
            ? 'Web uygulama bağlamında PHP sürümü okunuyor. Komut satırı sürümü sunucuda ayrıca doğrulanmalı.'
            : 'PHP sürümü canlı gereksinimleri için düşük olabilir.';

        return $this->healthItem(
            'php_compatibility',
            'PHP Uyumu',
            $status,
            $description,
            false,
            [
                'Web PHP: ' . PHP_VERSION,
                'SAPI: ' . PHP_SAPI,
                'Laravel: ' . app()->version(),
            ]
        );
    }

    /**
     * @param callable(): array<string, mixed> $resolver
     * @return array<string, mixed>
     */
    protected function safeHealthItem(string $key, string $label, callable $resolver): array
    {
        try {
            $item = $resolver();

            return array_merge([
                'key' => $key,
                'label' => $label,
                'checked_at' => $this->checkedAt(),
                'route' => null,
                'is_placeholder' => false,
                'details' => [],
            ], $item);
        } catch (\Throwable $exception) {
            return $this->healthItem(
                $key,
                $label,
                'unknown',
                $label . ' sinyali hazırlanamadı: ' . $this->safeExceptionMessage($exception->getMessage()),
                true,
                ['Hata güvenli biçimde maskelendi.']
            );
        }
    }

    /**
     * @param array<int, string> $details
     * @return array<string, mixed>
     */
    protected function healthItem(
        string $key,
        string $label,
        string $status,
        string $description,
        bool $isPlaceholder,
        array $details = [],
        ?string $route = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'description' => $description,
            'checked_at' => $this->checkedAt(),
            'route' => $route,
            'is_placeholder' => $isPlaceholder,
            'details' => array_values(array_filter(array_map(
                fn (string $detail): string => Str::limit(trim(strip_tags($detail)), 180),
                $details
            ))),
        ];
    }

    /**
     * @return array{path:string,mtime:int,size:int}|null
     */
    protected function latestLogFile(): ?array
    {
        $logDirectory = storage_path('logs');
        if (!File::isDirectory($logDirectory)) {
            return null;
        }

        $latest = null;
        foreach (File::files($logDirectory) as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            if ($latest === null || $file->getMTime() > $latest['mtime']) {
                $latest = [
                    'path' => $file->getPathname(),
                    'mtime' => $file->getMTime(),
                    'size' => $file->getSize(),
                ];
            }
        }

        return $latest;
    }

    protected function countErrorsInTail(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }

        try {
            $size = filesize($path);
            if (!is_int($size) || $size <= 0) {
                return 0;
            }

            $chunkSize = min($size, 262144);
            fseek($handle, -$chunkSize, SEEK_END);
            $tail = fread($handle, $chunkSize);
            if (!is_string($tail)) {
                return 0;
            }

            return substr_count(Str::upper($tail), 'ERROR');
        } finally {
            fclose($handle);
        }
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

    protected function checkedAt(): string
    {
        return now()->format('d.m.Y H:i');
    }

    protected function formatTimestampValue(mixed $value): string
    {
        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value)->format('d.m.Y H:i');
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->format('d.m.Y H:i');
            }

            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse($value)->format('d.m.Y H:i');
            }
        } catch (\Throwable) {
            // Safe fallback below.
        }

        return 'Bilinmiyor';
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

    protected function safeExceptionMessage(string $message): string
    {
        $safe = trim(strip_tags($message));

        foreach (['password', 'token', 'secret', 'smtp_password', 'api key', 'api_key', 'redis', 'database password'] as $needle) {
            if (Str::contains(Str::lower($safe), $needle)) {
                return 'Hassas detay gizlendi.';
            }
        }

        $safe = str_replace(base_path(), '[uygulama]', $safe);
        $safe = str_replace(storage_path(), '[storage]', $safe);

        return Str::limit($safe, 180);
    }
}
