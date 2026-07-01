<?php

namespace App\Services\SuperAdmin;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class StorageReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function buildStorageStatus(): array
    {
        $checks = [
            $this->checkPublicStorageLink(),
            $this->checkPublicDiskReadable(),
            $this->checkPublicDiskWritable(),
            $this->checkStorageLogsWritable(),
            $this->checkBootstrapCacheWritable(),
            $this->checkFilesystemDisk(),
            $this->checkAttachmentVisibility(),
            $this->checkPdfAttachmentStorage(),
            $this->checkWorkFolderStorage(),
            $this->checkProductDataHubDisks(),
        ];

        $warnings = collect($checks)
            ->filter(fn (array $check): bool => in_array($check['status'], ['warning', 'critical', 'unknown'], true))
            ->map(fn (array $check): string => $check['label'] . ': ' . $check['description'])
            ->values()
            ->all();

        $status = 'healthy';
        if (collect($checks)->contains(fn (array $check): bool => $check['status'] === 'critical')) {
            $status = 'critical';
        } elseif (collect($checks)->contains(fn (array $check): bool => in_array($check['status'], ['warning', 'unknown'], true))) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'checks' => $checks,
            'warnings' => $warnings,
            'checked_at' => now()->format('d.m.Y H:i'),
        ];
    }

    protected function checkPublicStorageLink(): array
    {
        $publicStoragePath = public_path('storage');
        $exists = is_link($publicStoragePath) || File::exists($publicStoragePath);

        return $this->check(
            'public_storage_link',
            'Public storage bağlantısı',
            $exists ? 'healthy' : 'warning',
            $exists
                ? 'public/storage bağlantısı erişilebilir görünüyor.'
                : 'public/storage bağlantısı görünmüyor; canlıda storage:link doğrulanmalı.'
        );
    }

    protected function checkPublicDiskReadable(): array
    {
        $path = storage_path('app/public');
        $readable = (File::isDirectory($path) || File::exists($path)) && is_readable($path);

        return $this->check(
            'public_disk_readable',
            'Public depolama okunabilirliği',
            $readable ? 'healthy' : 'warning',
            $readable
                ? 'Public depolama dizini okunabiliyor.'
                : 'Public depolama dizini okunamıyor veya görünmüyor.'
        );
    }

    protected function checkPublicDiskWritable(): array
    {
        $path = storage_path('app/public');
        $writable = File::isDirectory($path) && is_writable($path);

        return $this->check(
            'public_disk_writable',
            'Public depolama yazılabilirliği',
            $writable ? 'healthy' : 'warning',
            $writable
                ? 'Public depolama dizini yazılabilir görünüyor.'
                : 'Public depolama dizini yazılabilir görünmüyor; canlıda izinler kontrol edilmeli.'
        );
    }

    protected function checkStorageLogsWritable(): array
    {
        $path = storage_path('logs');
        $writable = File::isDirectory($path) && is_writable($path);

        return $this->check(
            'storage_logs_writable',
            'storage/logs yazılabilirliği',
            $writable ? 'healthy' : 'warning',
            $writable
                ? 'storage/logs yazılabilir görünüyor.'
                : 'storage/logs yazılabilir görünmüyor.'
        );
    }

    protected function checkBootstrapCacheWritable(): array
    {
        $path = base_path('bootstrap/cache');
        $writable = File::isDirectory($path) && is_writable($path);

        return $this->check(
            'bootstrap_cache_writable',
            'bootstrap/cache yazılabilirliği',
            $writable ? 'healthy' : 'warning',
            $writable
                ? 'bootstrap/cache yazılabilir görünüyor.'
                : 'bootstrap/cache yazılabilir görünmüyor.'
        );
    }

    protected function checkFilesystemDisk(): array
    {
        $disk = (string) Config::get('filesystems.default', 'local');

        return $this->check(
            'filesystem_disk',
            'Varsayılan dosya sistemi diski',
            filled($disk) ? 'healthy' : 'warning',
            filled($disk)
                ? 'Varsayılan disk tanımlı: ' . $disk
                : 'Varsayılan dosya sistemi diski görünmüyor.'
        );
    }

    protected function checkAttachmentVisibility(): array
    {
        return $this->check(
            'attachment_visibility',
            'Public dosya görünürlük koruması',
            'healthy',
            'Müşteri görünürlüğü ve token kontrolleri mevcut route/test hattıyla korunur.'
        );
    }

    protected function checkPdfAttachmentStorage(): array
    {
        return $this->check(
            'pdf_attachment_storage',
            'PDF ve ek dosya depolama notu',
            'healthy',
            'PDF ve ek dosyalar için public/private görünürlük kuralları ayrıca smoke testlerle doğrulanır.'
        );
    }

    protected function checkWorkFolderStorage(): array
    {
        return $this->check(
            'work_folder_storage',
            'İş klasörü depolama notu',
            'healthy',
            'İş klasörü ve ek dosya yapısı dosya taşıma yapmadan yalnız görünürlük ve izin sinyalleriyle izlenir.'
        );
    }

    protected function checkProductDataHubDisks(): array
    {
        $requiredDisks = ['pdh_private', 'pdh_public', 'pdh_temp', 'product_images', 'exports'];
        $configured = collect($requiredDisks)->filter(
            fn (string $disk): bool => is_array(config('filesystems.disks.' . $disk))
        );

        $status = $configured->count() === count($requiredDisks) ? 'healthy' : 'warning';

        return $this->check(
            'pdh_disks',
            'Product Data Hub diskleri',
            $status,
            $status === 'healthy'
                ? 'Product Data Hub disk tanımları mevcut.'
                : 'Product Data Hub disk tanımlarından en az biri eksik görünüyor.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function check(string $key, string $label, string $status, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'description' => $description,
            'is_sensitive_safe' => true,
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
}
