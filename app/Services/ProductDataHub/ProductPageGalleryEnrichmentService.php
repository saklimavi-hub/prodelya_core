<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductPageGalleryEnrichmentService
{
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

        $pageResult = $this->fetchProductPage($pageUrl);
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

    public function fetchProductPage(string $url): array
    {
        if (array_key_exists($url, $this->pageCache)) {
            return $this->pageCache[$url];
        }

        if (!$this->isValidHttpUrl($url)) {
            return $this->pageCache[$url] = [
                'ok' => false,
                'content' => null,
                'warnings' => ['Ürün sayfası linki geçersiz olduğu için galeri zenginleştirme yapılamadı.'],
            ];
        }

        try {
            $response = Http::timeout(15)
                ->accept('text/html,application/xhtml+xml')
                ->withUserAgent('Prodelya Product Data Hub Gallery Enrichment')
                ->get($url);

            if (!$response->successful()) {
                return $this->pageCache[$url] = [
                    'ok' => false,
                    'content' => null,
                    'warnings' => ['Ürün sayfası okunamadı. HTTP durum kodu: ' . $response->status()],
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

            return $this->pageCache[$url] = [
                'ok' => true,
                'content' => $content,
                'warnings' => [],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Product page gallery enrichment failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            return $this->pageCache[$url] = [
                'ok' => false,
                'content' => null,
                'warnings' => ['Ürün sayfası okunamadı: ' . $exception->getMessage()],
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
            return $url;
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $root = $base['scheme'] . '://' . $base['host'];

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $root . $url;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/.');
        $directory = $directory === '' ? '' : $directory;

        return $root . ($directory ? '/' . ltrim($directory, '/') : '') . '/' . ltrim($url, '/');
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

    private function isValidHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(Str::lower((string) $scheme), ['http', 'https'], true);
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
