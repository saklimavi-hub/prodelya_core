<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductHubAsset;
use App\Models\StandardProduct;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use Illuminate\Support\Str;

class ProductHubSafeImageUrlService
{
    private const CUSTOMER_FACING_CONTEXTS = [
        'work_form_pdf',
        'public_tracking',
        'customer_portal',
        'email',
        'whatsapp',
        'export',
    ];

    private const SUPPLIER_EXTERNAL_ALLOWED_CONTEXTS = [
        'super_admin_preview',
        'super_admin_report',
        'tenant_catalog',
        'quote_workspace',
        'order_show',
        'work_form_admin',
    ];

    public function __construct(
        private readonly SafeSourceUrlPolicyService $safeSourceUrlPolicyService,
        private readonly ProductHubAssetService $productHubAssetService,
    ) {
    }

    public function resolveFromSnapshot(array $snapshot, string $context): ?string
    {
        foreach ($this->candidateUrlsFromSnapshot($snapshot) as $candidate) {
            $resolved = $this->resolveCandidate($candidate, $context);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->fallback($context);
    }

    public function resolveForTenantCatalogProduct(TenantCatalogProduct $catalogProduct, string $context): ?string
    {
        $assetUrl = $this->assetUrlFromValue($catalogProduct->meta['image_asset'] ?? null);
        $imageSnapshot = data_get($catalogProduct->meta, 'image_snapshot.image_url');

        foreach (array_filter([
            $assetUrl,
            $catalogProduct->image_url,
            $imageSnapshot,
        ]) as $candidate) {
            $resolved = $this->resolveCandidate((string) $candidate, $context);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->fallback($context);
    }

    public function resolveForTenantCatalogVariant(TenantCatalogProductVariant $catalogVariant, string $context): ?string
    {
        $assetUrl = $this->assetUrlFromValue($catalogVariant->meta['image_asset'] ?? null);

        foreach (array_filter([
            $assetUrl,
            $catalogVariant->image_url,
            data_get($catalogVariant->meta, 'image_snapshot.image_url'),
        ]) as $candidate) {
            $resolved = $this->resolveCandidate((string) $candidate, $context);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->fallback($context);
    }

    public function resolveForStandardProduct(StandardProduct $product, string $context): ?string
    {
        $primaryImageUrl = null;

        if ($product->relationLoaded('primaryImage')) {
            $primaryImageUrl = $product->primaryImage?->image_url;
        } elseif (method_exists($product, 'primaryImage')) {
            $primaryImageUrl = $product->primaryImage()->value('image_url');
        }

        foreach (array_filter([
            $this->assetUrlFromValue($product->meta['image_asset'] ?? null),
            $primaryImageUrl,
            $product->image_url,
            data_get($product->meta, 'image_snapshot.image_url'),
        ]) as $candidate) {
            $resolved = $this->resolveCandidate((string) $candidate, $context);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->fallback($context);
    }

    public function fallback(string $context): ?string
    {
        $placeholder = trim((string) config('prodelya_product_data_hub.assets.placeholder_url', ''));

        if ($placeholder !== '') {
            return $this->isCustomerFacingContext($context) && !$this->isProdelyaAssetUrl($placeholder)
                ? null
                : $placeholder;
        }

        return null;
    }

    public function isCustomerFacingContext(string $context): bool
    {
        return in_array($context, self::CUSTOMER_FACING_CONTEXTS, true);
    }

    public function isSupplierExternalUrlAllowed(string $context): bool
    {
        return in_array($context, self::SUPPLIER_EXTERNAL_ALLOWED_CONTEXTS, true);
    }

    public function isProdelyaAssetUrl(?string $url): bool
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === null) {
            return false;
        }

        if (preg_match('/^data:image\//i', $normalized) === 1) {
            return true;
        }

        if (preg_match('#^/?storage/(?!private/)#i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^https?:\/\//i', $normalized) !== 1) {
            return false;
        }

        $parts = parse_url($normalized);
        if (!is_array($parts)) {
            return false;
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');

        if ($host === '') {
            return false;
        }

        if ($this->appHosts()->contains($host)) {
            return !Str::startsWith($path, '/storage/private/');
        }

        foreach ($this->configuredDiskUrls() as $diskUrl) {
            $diskParts = parse_url($diskUrl);

            if (!is_array($diskParts)) {
                continue;
            }

            $diskHost = Str::lower((string) ($diskParts['host'] ?? ''));
            if ($diskHost === '' || $diskHost !== $host) {
                continue;
            }

            $diskPath = '/' . ltrim((string) ($diskParts['path'] ?? '/'), '/');

            if ($diskPath === '/' || Str::startsWith($path, $diskPath)) {
                return true;
            }
        }

        return false;
    }

    public function sanitizeExternalUrlForAdmin(?string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === null) {
            return null;
        }

        $validation = $this->safeSourceUrlPolicyService->validate($normalized);

        return ($validation['ok'] ?? false) ? ($validation['normalized_url'] ?? $normalized) : null;
    }

    /**
     * @return array<int, string>
     */
    private function candidateUrlsFromSnapshot(array $snapshot): array
    {
        $candidates = [
            $this->assetUrlFromValue($snapshot['image_asset'] ?? null),
            $snapshot['safe_image_url'] ?? null,
            $snapshot['image_url'] ?? null,
            $snapshot['product_image_url'] ?? null,
            data_get($snapshot, 'image_snapshot.image_url'),
            data_get($snapshot, 'meta.image_snapshot.image_url'),
        ];

        return array_values(array_filter(array_map(
            fn ($value) => $this->normalizeUrl(is_string($value) ? $value : null),
            $candidates
        )));
    }

    private function resolveCandidate(string $url, string $context): ?string
    {
        if ($this->isProdelyaAssetUrl($url)) {
            return $url;
        }

        if (!$this->isSupplierExternalUrlAllowed($context)) {
            return null;
        }

        return $this->sanitizeExternalUrlForAdmin($url);
    }

    private function assetUrlFromValue(mixed $asset): ?string
    {
        if ($asset instanceof ProductHubAsset) {
            return $this->productHubAssetService->publicUrlFor($asset)
                ?: $this->productHubAssetService->signedUrlFor($asset);
        }

        if (is_array($asset)) {
            return $this->productHubAssetService->publicUrlFor($asset)
                ?: $this->productHubAssetService->signedUrlFor($asset);
        }

        return null;
    }

    private function normalizeUrl(?string $url): ?string
    {
        $normalized = trim((string) ($url ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function appHosts()
    {
        $urls = array_filter([
            config('app.url'),
            config('app.asset_url'),
        ]);

        return collect($urls)
            ->map(function ($url) {
                $parts = parse_url((string) $url);

                return Str::lower((string) ($parts['host'] ?? ''));
            })
            ->filter();
    }

    /**
     * @return array<int, string>
     */
    private function configuredDiskUrls(): array
    {
        $disks = ['public', 'pdh_public', 'product_images', 'exports'];
        $urls = [];

        foreach ($disks as $disk) {
            $diskUrl = config('filesystems.disks.' . $disk . '.url');

            if (is_string($diskUrl) && trim($diskUrl) !== '') {
                $urls[] = trim($diskUrl);
            }
        }

        return array_values(array_unique($urls));
    }
}
