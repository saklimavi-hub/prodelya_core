<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductPageGalleryEnrichmentService
{
    public function __construct(
        private readonly SafeSourceUrlPolicyService $safeUrlPolicy,
        private readonly SensitiveDataMasker $masker,
    ) {
    }

    private array $pageCache = [];

    public function enrich(array $productRow, SupplierSource $source): array
    {
        if (!(bool) ($source->config['enrich_gallery_from_product_page'] ?? false)) {
            return $this->finalize($productRow, [], 'feed');
        }

        $pageUrl = $productRow['product_url'] ?? $productRow['detail_url'] ?? null;
        if (!filled($pageUrl)) {
            $productRow['warnings'][] = 'Ürün sayfası linki bulunmadığı için galeri zenginleştirme yapılmadı.';

            return $this->finalize($productRow, [], 'feed');
        }

        $selector = $source->config['product_page_gallery_selector'] ?? null;
        $maxGalleryImages = (int) ($source->config['max_gallery_images'] ?? 10);
        $maxGalleryImages = max(1, min(50, $maxGalleryImages));

        $pageResult = $this->fetchProductPage($pageUrl, $source);
        if (!$pageResult['ok']) {
            foreach (($pageResult['warnings'] ?? []) as $warning) {
                $productRow['warnings'][] = $warning;
            }

            return $this->finalize($productRow, [], 'feed');
        }

        $pageImages = $this->extractImagesFromHtml(
            (string) $pageResult['content'],
            $pageUrl,
            is_string($selector) && trim($selector) !== '' ? trim($selector) : null
        );

        $existingGalleryImages = collect($productRow['gallery_images'] ?? [])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->values()
            ->all();

        $mergedGalleryImages = collect(array_merge($existingGalleryImages, $pageImages))
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->take($maxGalleryImages)
            ->values()
            ->all();

        $pageOnlyImages = array_values(array_filter(
            $mergedGalleryImages,
            fn (string $image) => !in_array($image, $existingGalleryImages, true)
        ));

        if (!empty($pageOnlyImages)) {
            $productRow['gallery_images'] = $mergedGalleryImages;
            $productRow['page_gallery_images'] = $pageOnlyImages;
            $productRow['warnings'][] = 'Ürün sayfasından ' . count($pageOnlyImages) . ' galeri görseli alındı.';
        }

        if (blank($productRow['image_url'] ?? null) && !empty($mergedGalleryImages)) {
            $productRow['image_url'] = $mergedGalleryImages[0];
        }

        return $this->finalize(
            $productRow,
            $pageOnlyImages,
            empty($pageOnlyImages) ? 'feed' : (!empty($existingGalleryImages) ? 'feed+page' : 'page')
        );
    }

    public function fetchProductPage(string $url, ?SupplierSource $source = null): array
    {
        if (array_key_exists($url, $this->pageCache)) {
            return $this->pageCache[$url];
        }

        $policy = $this->safeUrlPolicy->validate($url);
        if (!$policy['ok']) {
            return $this->pageCache[$url] = [
                'ok' => false,
                'content' => null,
                'warnings' => [$this->resolveBlockedPageWarning($url, (string) ($policy['message'] ?? 'Ürün sayfası linki güvenlik politikası nedeniyle reddedildi.'))],
            ];
        }

        $security = config('prodelya_product_data_hub.fetch_security', []);
        $timeoutSeconds = max(1, min(60, (int) ($security['image_timeout_seconds'] ?? 12)));
        $connectTimeoutSeconds = max(1, min(60, (int) ($security['connect_timeout_seconds'] ?? 10)));
        $maxRedirects = max(0, min(10, (int) ($security['max_redirects'] ?? 3)));
        $maxBytes = max(1024, (int) ($security['max_preview_bytes'] ?? (25 * 1024 * 1024)));
        $currentUrl = $url;
        $redirectCount = 0;

        try {
            while (true) {
                $response = Http::timeout($timeoutSeconds)
                    ->connectTimeout($connectTimeoutSeconds)
                    ->accept('text/html,application/xhtml+xml')
                    ->withUserAgent('Prodelya Product Data Hub Gallery Enrichment')
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                    ])
                    ->get($currentUrl);

                if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    if ($redirectCount >= $maxRedirects) {
                        return $this->pageCache[$url] = [
                            'ok' => false,
                            'content' => null,
                            'warnings' => ['Ürün sayfası yönlendirme limiti aşıldığı için okunamadı.'],
                        ];
                    }

                    $target = $this->safeUrlPolicy->resolveRedirectTarget($currentUrl, (string) $response->header('Location'));
                    if (!filled($target)) {
                        return $this->pageCache[$url] = [
                            'ok' => false,
                            'content' => null,
                            'warnings' => ['Ürün sayfası yönlendirme hedefi çözümlenemedi.'],
                        ];
                    }

                    $redirectPolicy = $this->safeUrlPolicy->validate($target);
                    if (!$redirectPolicy['ok']) {
                        return $this->pageCache[$url] = [
                            'ok' => false,
                            'content' => null,
                            'warnings' => [$this->resolveBlockedPageWarning($target, (string) ($redirectPolicy['message'] ?? 'Ürün sayfası linki güvenlik politikası nedeniyle reddedildi.'))],
                        ];
                    }

                    $currentUrl = $target;
                    $redirectCount++;
                    continue;
                }

                if (!$response->successful()) {
                    return $this->pageCache[$url] = [
                        'ok' => false,
                        'content' => null,
                        'warnings' => ['Ürün sayfası okunamadı. HTTP durum kodu: ' . $response->status()],
                    ];
                }

                $contentType = Str::lower(trim((string) strtok((string) $response->header('Content-Type'), ';')));
                if ($contentType !== '' && !str_contains($contentType, 'html')) {
                    return $this->pageCache[$url] = [
                        'ok' => false,
                        'content' => null,
                        'warnings' => ['Ürün sayfası güvenlik politikası nedeniyle reddedildi: HTML içerik bekleniyordu.'],
                    ];
                }

                $declaredContentLength = (int) ($response->header('Content-Length') ?? 0);
                if ($declaredContentLength > 0 && $declaredContentLength > $maxBytes) {
                    return $this->pageCache[$url] = [
                        'ok' => false,
                        'content' => null,
                        'warnings' => ['Ürün sayfası güvenlik politikası nedeniyle reddedildi: yanıt boyutu çok büyük.'],
                    ];
                }

                $content = $response->body();
                if (blank($content)) {
                    return $this->pageCache[$url] = [
                        'ok' => false,
                        'content' => null,
                        'warnings' => ['Ürün sayfası boş döndüğü için galeri zenginleştirilemedi.'],
                    ];
                }

                if (strlen($content) > $maxBytes) {
                    return $this->pageCache[$url] = [
                        'ok' => false,
                        'content' => null,
                        'warnings' => ['Ürün sayfası güvenlik politikası nedeniyle reddedildi: yanıt boyutu çok büyük.'],
                    ];
                }

                return $this->pageCache[$url] = [
                    'ok' => true,
                    'content' => $content,
                    'warnings' => [],
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Product page gallery enrichment failed', [
                'url' => $this->safeUrlPolicy->maskedUrl($url),
                'message' => $this->masker->maskExceptionMessage($exception->getMessage()),
            ]);

            return $this->pageCache[$url] = [
                'ok' => false,
                'content' => null,
                'warnings' => ['Ürün sayfası okunamadı.'],
            ];
        }
    }

    public function extractImagesFromHtml(string $html, string $baseUrl, ?string $selector = null): array
    {
        if (blank($html)) {
            return [];
        }

        $images = [];

        libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $nodes = $this->resolveSelectorNodes($xpath, $selector);

        foreach ($nodes as $node) {
            foreach (['src', 'data-src', 'data-large', 'data-zoom-image'] as $attribute) {
                $normalized = $this->normalizeImageUrl($node->getAttribute($attribute), $baseUrl);
                if ($normalized) {
                    $images[] = $normalized;
                }
            }

            if ($node->parentNode instanceof \DOMElement && Str::lower($node->parentNode->tagName) === 'a') {
                $normalizedHref = $this->normalizeImageUrl($node->parentNode->getAttribute('href'), $baseUrl);
                if ($normalizedHref) {
                    $images[] = $normalizedHref;
                }
            }
        }

        foreach ($xpath->query('//meta[@property="og:image"]') ?: [] as $metaNode) {
            if ($metaNode instanceof \DOMElement) {
                $normalized = $this->normalizeImageUrl($metaNode->getAttribute('content'), $baseUrl);
                if ($normalized) {
                    $images[] = $normalized;
                }
            }
        }

        foreach ($xpath->query('//a[@href]') ?: [] as $anchorNode) {
            if ($anchorNode instanceof \DOMElement) {
                $normalized = $this->normalizeImageUrl($anchorNode->getAttribute('href'), $baseUrl);
                if ($normalized) {
                    $images[] = $normalized;
                }
            }
        }

        return collect($images)
            ->filter(fn ($url) => $this->isProductImageCandidate((string) $url))
            ->unique()
            ->values()
            ->all();
    }

    public function normalizeImageUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (preg_match('/\.(jpg|jpeg|png|webp)(\?.*)?$/i', $url) !== 1) {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            $policy = $this->safeUrlPolicy->validate($url);

            return $policy['ok'] ? $url : null;
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $root = $base['scheme'] . '://' . $base['host'];

        if (str_starts_with($url, '//')) {
            $resolved = $base['scheme'] . ':' . $url;
            $policy = $this->safeUrlPolicy->validate($resolved);

            return $policy['ok'] ? $resolved : null;
        }

        $resolved = null;

        if (str_starts_with($url, '/')) {
            $resolved = $root . $url;
        } else {
            $path = $base['path'] ?? '/';
            $directory = rtrim(str_replace('\\', '/', dirname($path)), '/.');
            $directory = $directory === '' ? '' : $directory;

            $resolved = $root . ($directory ? '/' . ltrim($directory, '/') : '') . '/' . ltrim($url, '/');
        }

        $policy = $this->safeUrlPolicy->validate($resolved);

        return $policy['ok'] ? $resolved : null;
    }

    private function resolveSelectorNodes(\DOMXPath $xpath, ?string $selector): \DOMNodeList|array
    {
        $selector = trim((string) $selector);
        if ($selector === '' || $selector === 'img') {
            return $xpath->query('//img') ?: [];
        }

        if (preg_match('/^\.(?<class>[A-Za-z0-9_-]+)\s+img$/', $selector, $matches) === 1) {
            $class = $matches['class'];

            return $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]//img | //section[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]//img | //ul[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]//img") ?: [];
        }

        if (preg_match('/^#(?<id>[A-Za-z0-9_-]+)\s+img$/', $selector, $matches) === 1) {
            $id = $matches['id'];

            return $xpath->query("//*[@id='{$id}']//img") ?: [];
        }

        return $xpath->query('//img') ?: [];
    }

    private function isProductImageCandidate(string $url): bool
    {
        $normalized = Str::lower($url);

        if (Str::contains($normalized, ['logo', 'icon', 'favicon', 'whatsapp', 'youtube', 'facebook', 'instagram', 'twitter', 'linkedin'])) {
            return false;
        }

        return Str::contains($normalized, ['urun', 'product', 'img', 'resim', 'gallery', 'galeri'])
            || preg_match('/\.(jpg|jpeg|png|webp)(\?.*)?$/i', $normalized) === 1;
    }

    private function resolveBlockedPageWarning(string $url, string $policyMessage): string
    {
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === '' || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return 'Ürün sayfası linki geçersiz olduğu için galeri zenginleştirme yapılamadı.';
        }

        return $policyMessage;
    }

    private function finalize(array $productRow, array $pageOnlyImages, string $origin): array
    {
        $feedGalleryImages = collect($productRow['feed_gallery_images'] ?? $productRow['gallery_images'] ?? [])
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();

        $productRow['feed_gallery_images'] = $feedGalleryImages;
        $productRow['page_gallery_images'] = $pageOnlyImages;
        $productRow['gallery_origin'] = $origin;
        $productRow['warnings'] = array_values(array_unique(array_filter($productRow['warnings'] ?? [])));

        return $productRow;
    }
}
