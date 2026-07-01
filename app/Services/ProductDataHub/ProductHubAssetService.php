<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductHubAsset;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

class ProductHubAssetService
{
    public function __construct(
        private readonly ProductHubStorageService $storageService,
        private readonly SensitiveDataMasker $masker,
    ) {
    }

    public function registerPending(string $assetType, string $objectKey, array $attributes = []): ProductHubAsset
    {
        $disk = (string) ($attributes['disk'] ?? $this->diskForAssetType($assetType));
        $visibility = (string) ($attributes['visibility'] ?? $this->visibilityForAssetType($assetType));

        return ProductHubAsset::query()->create(array_merge($attributes, [
            'asset_type' => $assetType,
            'disk' => $disk,
            'object_key' => $this->storageService->sanitizeObjectKey($objectKey),
            'visibility' => $visibility,
            'status' => ProductHubAsset::STATUS_PENDING,
            'storage_provider' => $this->storageProviderForDisk($disk),
            'failed_reason' => null,
        ]));
    }

    public function markStored(ProductHubAsset|int $asset, array $attributes = []): ProductHubAsset
    {
        $record = $this->resolveAsset($asset);
        $disk = (string) ($attributes['disk'] ?? $record->disk);
        $objectKey = $this->storageService->sanitizeObjectKey((string) ($attributes['object_key'] ?? $record->object_key));
        $visibility = (string) ($attributes['visibility'] ?? $record->visibility);
        $publicUrl = array_key_exists('public_url', $attributes)
            ? $this->masker->maskUrl((string) $attributes['public_url'])
            : $this->publicUrlFor([
                'disk' => $disk,
                'object_key' => $objectKey,
                'visibility' => $visibility,
            ]);

        $record->fill(array_merge($attributes, [
            'disk' => $disk,
            'object_key' => $objectKey,
            'visibility' => $visibility,
            'public_url' => $publicUrl,
            'status' => ProductHubAsset::STATUS_STORED,
            'stored_at' => $attributes['stored_at'] ?? now(),
            'storage_provider' => $this->storageProviderForDisk($disk),
            'failed_reason' => null,
        ]));
        $record->save();

        return $record->fresh();
    }

    public function markFailed(ProductHubAsset|int $asset, string $reason, array $attributes = []): ProductHubAsset
    {
        $record = $this->resolveAsset($asset);
        $safeReason = $this->masker->maskExceptionMessage($this->masker->maskUrl($reason) ?? $reason);

        $record->fill(array_merge($attributes, [
            'status' => ProductHubAsset::STATUS_FAILED,
            'failed_reason' => $safeReason,
        ]));
        $record->save();

        return $record->fresh();
    }

    public function publicUrlFor(ProductHubAsset|array $asset): ?string
    {
        $payload = $asset instanceof ProductHubAsset ? $asset->toArray() : $asset;
        $visibility = (string) ($payload['visibility'] ?? ProductHubAsset::VISIBILITY_PRIVATE);
        $publicUrl = $payload['public_url'] ?? null;

        if (is_string($publicUrl) && $publicUrl !== '') {
            return $this->masker->maskUrl($publicUrl);
        }

        if ($visibility !== ProductHubAsset::VISIBILITY_PUBLIC) {
            return null;
        }

        $disk = (string) ($payload['disk'] ?? '');
        $objectKey = (string) ($payload['object_key'] ?? '');

        if ($disk === '' || $objectKey === '') {
            return null;
        }

        return Storage::disk($disk)->url($objectKey);
    }

    public function signedUrlFor(ProductHubAsset|array $asset, ?CarbonInterface $expiresAt = null): ?string
    {
        $payload = $asset instanceof ProductHubAsset ? $asset : new ProductHubAsset($asset);
        $disk = (string) $payload->disk;
        $objectKey = (string) $payload->object_key;

        if ($objectKey === '') {
            return null;
        }

        if ($payload->visibility === ProductHubAsset::VISIBILITY_PUBLIC) {
            return $this->publicUrlFor($payload);
        }

        $expiresAt ??= now()->addMinutes(15);
        $storage = Storage::disk($disk);

        if (method_exists($storage, 'temporaryUrl')) {
            try {
                return $storage->temporaryUrl($objectKey, $expiresAt);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function maskStoragePathForUi(?string $path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#^[A-Za-z]:/#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^/+?#', '', $normalized) ?? $normalized;
        $segments = array_values(array_filter(explode('/', $normalized)));

        if (count($segments) <= 3) {
            return '.../' . implode('/', $segments);
        }

        return '.../' . implode('/', array_slice($segments, -3));
    }

    public function assetLabel(string $assetType): string
    {
        return (string) config('prodelya_product_data_hub.assets.types.' . $assetType, $assetType);
    }

    public function diskForAssetType(string $assetType): string
    {
        return (string) config('prodelya_product_data_hub.assets.disk_map.' . $assetType, 'pdh_private');
    }

    public function visibilityForAssetType(string $assetType): string
    {
        return (string) config('prodelya_product_data_hub.assets.visibility_map.' . $assetType, ProductHubAsset::VISIBILITY_PRIVATE);
    }

    public function findByChecksum(string $checksum): ?ProductHubAsset
    {
        return ProductHubAsset::query()
            ->where('checksum_sha256', $checksum)
            ->first();
    }

    private function resolveAsset(ProductHubAsset|int $asset): ProductHubAsset
    {
        return $asset instanceof ProductHubAsset
            ? $asset
            : ProductHubAsset::query()->findOrFail($asset);
    }

    private function storageProviderForDisk(string $disk): string
    {
        $driver = (string) config('filesystems.disks.' . $disk . '.driver', 'local');

        return match ($driver) {
            's3' => 's3',
            default => $driver,
        };
    }
}
