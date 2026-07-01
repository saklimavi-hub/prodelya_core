<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class ProductHubStorageService
{
    public function keyForRawFeedSnapshot(SupplierSource $source, ?ProductDataHubSyncRun $run, string $extension = 'json', string $filename = 'source'): string
    {
        $date = $run?->started_at instanceof CarbonInterface ? $run->started_at : now();

        return $this->buildObjectKey([
            'pdh',
            'private',
            'suppliers',
            $this->supplierCode($source),
            'sources',
            (string) $source->id,
            'sync-runs',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            'run-' . ($run?->id ?? 'pending'),
            'raw',
        ], $filename . '.' . $this->sanitizeExtension($extension));
    }

    public function keyForSyncLog(SupplierSource $source, ?ProductDataHubSyncRun $run, string $filename = 'sync.log'): string
    {
        $date = $run?->started_at instanceof CarbonInterface ? $run->started_at : now();

        return $this->buildObjectKey([
            'pdh',
            'private',
            'suppliers',
            $this->supplierCode($source),
            'sources',
            (string) $source->id,
            'sync-runs',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            'run-' . ($run?->id ?? 'pending'),
            'logs',
        ], $filename);
    }

    public function keyForProductImage(string $supplierCode, string $productCode, string $checksum, string $variant = 'original', string $extension = 'webp'): string
    {
        return $this->buildObjectKey([
            'pdh',
            'public',
            'products',
            'images',
            $this->sanitizeSegment($supplierCode),
            $this->sanitizeSegment($productCode),
            $this->sanitizeSegment($checksum),
        ], $this->sanitizeSegment($variant) . '.' . $this->sanitizeExtension($extension));
    }

    public function keyForTenantExport(TenantAccount|string $tenant, string $filename, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        $tenantSlug = $tenant instanceof TenantAccount
            ? (string) ($tenant->slug ?: $tenant->id)
            : (string) $tenant;

        return $this->buildObjectKey([
            'pdh',
            'private',
            'tenants',
            $this->sanitizeSegment($tenantSlug),
            'exports',
            $date->format('Y'),
            $date->format('m'),
        ], $filename);
    }

    public function buildObjectKey(array $segments, string $filename): string
    {
        $safeSegments = collect($segments)
            ->map(fn ($segment) => $this->sanitizeSegment((string) $segment))
            ->filter()
            ->values()
            ->all();

        $safeFilename = $this->sanitizeFilename($filename);

        return implode('/', array_merge($safeSegments, [$safeFilename]));
    }

    public function sanitizeObjectKey(string $objectKey): string
    {
        $segments = preg_split('#[\\\\/]+#', $objectKey) ?: [];
        $filename = array_pop($segments) ?: 'asset.bin';

        return $this->buildObjectKey($segments, $filename);
    }

    public function sanitizeSegment(string $segment): string
    {
        $segment = trim(str_replace(['\\', '/'], '-', $segment));
        $segment = preg_replace('/\.+/', '.', $segment) ?? $segment;
        $segment = str_replace('..', '-', $segment);
        $segment = Str::ascii($segment);
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '-', $segment) ?? $segment;
        $segment = trim($segment, '.-_');

        return $segment !== '' ? $segment : 'na';
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '-', $filename));
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $safeName = $this->sanitizeSegment($name !== '' ? $name : 'asset');
        $safeExtension = $this->sanitizeExtension($extension !== '' ? $extension : 'bin');

        return $safeName . '.' . $safeExtension;
    }

    private function sanitizeExtension(string $extension): string
    {
        $extension = Str::lower(trim($extension));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? $extension;

        return $extension !== '' ? $extension : 'bin';
    }

    private function supplierCode(SupplierSource $source): string
    {
        return $this->sanitizeSegment((string) ($source->supplier?->code ?: $source->supplier_id ?: 'supplier'));
    }
}
