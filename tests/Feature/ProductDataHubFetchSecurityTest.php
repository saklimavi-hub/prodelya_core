<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\ProductPageGalleryEnrichmentService;
use App\Services\ProductDataHub\SensitiveDataMasker;
use App\Services\ProductDataHub\SourceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDataHubFetchSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_scheme_url_is_rejected(): void
    {
        $source = $this->makeSource(['url' => 'file:///etc/passwd']);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_policy_blocked', $result['error_type']);
    }

    public function test_localhost_url_is_rejected(): void
    {
        $source = $this->makeSource(['url' => 'http://localhost/feed.xml']);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('private/local', $result['errors'][0]);
    }

    public function test_loopback_ip_url_is_rejected(): void
    {
        $source = $this->makeSource(['url' => 'http://127.0.0.1/feed.xml']);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_policy_blocked', $result['error_type']);
    }

    public function test_private_ipv4_ranges_are_rejected(): void
    {
        foreach (['10.0.0.8', '172.16.5.10', '192.168.1.20'] as $ip) {
            $source = $this->makeSource(['url' => 'http://' . $ip . '/feed.xml']);

            $result = app(SourceFetchService::class)->fetch($source);

            $this->assertFalse($result['ok'], $ip . ' must be blocked.');
        }
    }

    public function test_metadata_ip_is_rejected(): void
    {
        $source = $this->makeSource(['url' => 'http://169.254.169.254/latest/meta-data']);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_policy_blocked', $result['error_type']);
    }

    public function test_https_public_url_is_accepted(): void
    {
        Http::fake([
            'https://example.com/feed.json' => Http::response('{"items":[]}', 200, ['Content-Type' => 'application/json']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.json',
            'source_type' => 'api',
            'config' => ['format' => 'json'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
    }

    public function test_redirect_to_private_ip_is_rejected(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response('', 302, ['Location' => 'http://127.0.0.1/hidden.xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_policy_blocked', $result['error_type']);
    }

    public function test_sensitive_query_values_are_masked(): void
    {
        $masked = app(SensitiveDataMasker::class)->maskUrl('https://example.com/feed?api_key=SECRET&lang=tr&token=ABC');

        $this->assertSame('https://example.com/feed?api_key=%2A%2A%2A&lang=tr&token=%2A%2A%2A', $masked);
    }

    public function test_authorization_header_is_masked(): void
    {
        $masked = app(SensitiveDataMasker::class)->maskHeaders([
            'Authorization' => 'Bearer super-secret',
            'X-Client' => 'Prodelya',
        ]);

        $this->assertSame('[hidden]', $masked['Authorization']);
        $this->assertSame('Prodelya', $masked['X-Client']);
    }

    public function test_max_response_size_is_enforced(): void
    {
        config()->set('prodelya_product_data_hub.fetch_security.max_preview_bytes', 1024);

        Http::fake([
            'https://example.com/large.xml' => Http::response(str_repeat('X', 2048), 200, [
                'Content-Type' => 'application/xml',
                'Content-Length' => '2048',
            ]),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/large.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('response_too_large', $result['error_type']);
    }

    public function test_gallery_page_fetch_uses_same_safe_url_policy(): void
    {
        $result = app(ProductPageGalleryEnrichmentService::class)->fetchProductPage('http://localhost/product-page');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('güvenlik politikası', $result['warnings'][0]);
    }

    private function makeSource(array $attributes = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Security Supplier',
            'code' => 'SEC-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Security Source',
            'url' => null,
            'config' => [],
            'status' => 'active',
        ], $attributes));
    }
}
