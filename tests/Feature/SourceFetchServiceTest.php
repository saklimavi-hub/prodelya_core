<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\SourceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_returns_whitelist_warning_for_http_403(): void
    {
        Http::fake([
            'https://example.com/akdeniz.xml' => Http::response('Forbidden', 403, ['Content-Type' => 'application/xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/akdeniz.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['status_code']);
        $this->assertTrue(collect($result['warnings'])->contains(fn (string $warning) => str_contains($warning, 'IP izni/whitelist istiyor olabilir')));
    }

    public function test_fetch_uses_get_method_by_default(): void
    {
        Http::fake([
            'https://example.com/feed.json' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.json',
            'config' => ['format' => 'json'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_fetch_uses_post_method_headers_and_body_from_settings(): void
    {
        Http::fake([
            'https://example.com/feed-endpoint' => Http::response('<xml>ok</xml>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed-endpoint',
            'config' => [
                'format' => 'xml',
                'http_method' => 'POST',
                'auth_type' => 'bearer',
                'auth_token' => 'secret-token',
                'request_headers' => '{"X-Client":"Prodelya"}',
                'request_body' => '{"feed":"full"}',
            ],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request->hasHeader('X-Client', 'Prodelya')
                && str_contains($request->body(), 'full');
        });
    }

    public function test_fetch_prefers_local_file_when_present(): void
    {
        $filePath = storage_path('app/testing/akdeniz-local.xml');
        @mkdir(dirname($filePath), 0777, true);
        file_put_contents($filePath, '<ROOT><RECORD><urun_id>1</urun_id></RECORD></ROOT>');

        Http::fake();

        $source = $this->makeSource([
            'url' => 'https://example.com/remote.xml',
            'config' => [
                'format' => 'xml',
                'source_file_path' => $filePath,
            ],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('<ROOT>', (string) $result['content']);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_fetch_returns_error_when_local_file_is_missing(): void
    {
        $filePath = storage_path('app/testing/not-found-akdeniz.xml');

        $source = $this->makeSource([
            'config' => [
                'format' => 'xml',
                'source_file_path' => $filePath,
            ],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn (string $error) => str_contains($error, 'Kaynak dosyası bulunamadı')));
    }

    public function test_test_connection_returns_clear_message_for_http_403(): void
    {
        Http::fake([
            'https://example.com/ip-protected.xml' => Http::response('Forbidden', 403),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/ip-protected.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->testConnection($source);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['status_code']);
        $this->assertStringContainsString('HTTP 403 döndü', $result['message']);
        $this->assertStringContainsString('Sunucu IP’sini tedarikçiye tanımlatın', $result['message']);
    }

    public function test_fetch_returns_ssl_certificate_error_type_for_curl_error_60(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        });

        $source = $this->makeSource([
            'url' => 'https://example.com/ssl-protected.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('ssl_certificate', $result['error_type']);
        $this->assertTrue(collect($result['errors'])->contains('SSL sertifika hatası'));
        $this->assertTrue(collect($result['warnings'])->contains(fn (string $warning) => str_contains($warning, 'SSL sertifika doğrulama hatası oluştu')));
    }

    public function test_test_connection_returns_clear_message_for_ssl_certificate_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        });

        $source = $this->makeSource([
            'url' => 'https://example.com/ssl-protected.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->testConnection($source);

        $this->assertFalse($result['ok']);
        $this->assertSame('ssl_certificate', $result['error_type']);
        $this->assertStringContainsString('SSL sertifikası doğrulanamadı', $result['message']);
        $this->assertStringContainsString('CA sertifika ayarını kontrol edin', $result['message']);
    }

    private function makeSource(array $attributes = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Test Supplier',
            'code' => 'TEST-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Test Source',
            'url' => null,
            'config' => [],
            'status' => 'active',
        ], $attributes));
    }
}
