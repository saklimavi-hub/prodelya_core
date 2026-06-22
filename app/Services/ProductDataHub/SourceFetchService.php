<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SourceFetchService
{
    public function fetch(SupplierSource $source): array
    {
        $sourceFilePath = $source->config['source_file_path'] ?? null;
        $url = $source->url;

        if (filled($sourceFilePath)) {
            $result = $this->fetchLocalFile($sourceFilePath);
        } elseif (filled($url)) {
            $result = $this->fetchUrl($source, $url);
        } else {
            $result = [
                'ok' => false,
                'content' => null,
                'content_type' => $this->detectContentType($source, null),
                'status_code' => null,
                'error_type' => 'connection_error',
                'errors' => ['Kaynak URL veya dosya yolu tanımlı değil.'],
                'warnings' => [],
            ];
        }

        if ($result['ok']) {
            $result['content_type'] = $this->detectContentType($source, $result['content_type'] ?? null);
        }

        return $result;
    }

    public function fetchUrl(SupplierSource $source, string $url): array
    {
        $headers = $this->resolveHeaders($source);
        $method = $this->resolveHttpMethod($source);
        $body = $this->resolveRequestBody($source);

        try {
            $request = Http::timeout(25)
                ->accept('*/*')
                ->withUserAgent('Prodelya Product Data Hub')
                ->withHeaders($headers);

            $request = $this->applyAuth($request, $source);

            $response = match ($method) {
                'POST' => $request->send('POST', $url, $this->buildSendOptions($source, $body)),
                default => $request->send('GET', $url, $this->buildSendOptions($source, null)),
            };

            if (!$response->successful()) {
                Log::warning('Supplier source fetch failed', [
                    'url' => $url,
                    'method' => $method,
                    'status_code' => $response->status(),
                ]);

                $errors = ['Kaynak isteği başarısız oldu. HTTP durum kodu: ' . $response->status()];
                $warnings = [];

                if ($response->status() === 403) {
                    $warnings[] = $this->whitelistWarningMessage();
                }

                return [
                    'ok' => false,
                    'content' => null,
                    'content_type' => $this->detectContentTypeFromString($response->header('Content-Type')),
                    'status_code' => $response->status(),
                    'error_type' => $response->status() === 403 ? 'http_403' : 'http_error',
                    'errors' => $errors,
                    'warnings' => $warnings,
                ];
            }

            return [
                'ok' => true,
                'content' => $response->body(),
                'content_type' => $this->detectContentTypeFromString($response->header('Content-Type')),
                'status_code' => $response->status(),
                'error_type' => 'none',
                'errors' => [],
                'warnings' => [],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Supplier source fetch exception', [
                'url' => $url,
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);

            $errorType = $this->detectExceptionErrorType($exception->getMessage());
            $errors = ['Kaynak okunurken hata oluştu: ' . $exception->getMessage()];
            $warnings = [];

            if ($errorType === 'ssl_certificate') {
                $errors[] = 'SSL sertifika hatası';
                $warnings[] = $this->sslWarningMessage();
            }

            return [
                'ok' => false,
                'content' => null,
                'content_type' => $this->detectContentTypeFromPath($url),
                'status_code' => null,
                'error_type' => $errorType,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }
    }

    public function fetchLocalFile(string $path): array
    {
        if (!is_file($path)) {
            return [
                'ok' => false,
                'content' => null,
                'content_type' => $this->detectContentTypeFromPath($path),
                'status_code' => null,
                'error_type' => 'connection_error',
                'errors' => ['Kaynak dosyası bulunamadı: ' . $path],
                'warnings' => [],
            ];
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return [
                'ok' => false,
                'content' => null,
                'content_type' => $this->detectContentTypeFromPath($path),
                'status_code' => null,
                'error_type' => 'connection_error',
                'errors' => ['Kaynak dosyası okunamadı: ' . $path],
                'warnings' => [],
            ];
        }

        return [
            'ok' => true,
            'content' => $content,
            'content_type' => $this->detectContentTypeFromPath($path),
            'status_code' => 200,
            'error_type' => 'none',
            'errors' => [],
            'warnings' => [],
        ];
    }

    public function testConnection(SupplierSource $source): array
    {
        $sourceFilePath = $source->config['source_file_path'] ?? null;

        if (filled($sourceFilePath)) {
            return is_file($sourceFilePath)
                ? [
                    'ok' => true,
                    'status_code' => 200,
                    'duration_ms' => 0,
                    'error_type' => 'none',
                    'message' => 'Bağlantı testi başarılı. Yerel dosya erişilebilir.',
                ]
                : [
                    'ok' => false,
                    'status_code' => null,
                    'duration_ms' => 0,
                    'error_type' => 'connection_error',
                    'message' => 'Bağlantı testi başarısız. Yerel dosya bulunamadı.',
                ];
        }

        if (blank($source->url)) {
            return [
                'ok' => false,
                'status_code' => null,
                'duration_ms' => 0,
                'error_type' => 'connection_error',
                'message' => 'Bağlantı testi başarısız. Kaynak URL’sini kontrol edin.',
            ];
        }

        $startedAt = microtime(true);

        try {
            $method = $this->resolveHttpMethod($source);
            $headers = $this->resolveHeaders($source);
            $body = $this->resolveRequestBody($source);

            $request = Http::timeout(15)
                ->withUserAgent('Prodelya Product Data Hub')
                ->withHeaders($headers);

            $request = $this->applyAuth($request, $source);
            $response = $request->send($method, $source->url, $this->buildSendOptions($source, $method === 'POST' ? $body : null));

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $message = $response->successful()
                ? 'Bağlantı testi başarılı.'
                : ($response->status() === 403
                    ? 'Bağlantı testi başarısız. HTTP 403 döndü. Tedarikçi IP izni istiyor olabilir. Çözüm: Sunucu IP’sini tedarikçiye tanımlatın veya yerel dosya yolu ile onaylı XML dosyasını okutun.'
                    : 'Bağlantı testi başarısız. Kaynak URL’sini kontrol edin.');

            return [
                'ok' => $response->successful(),
                'status_code' => $response->status(),
                'duration_ms' => $durationMs,
                'error_type' => $response->successful() ? 'none' : ($response->status() === 403 ? 'http_403' : 'http_error'),
                'message' => $message,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Supplier source connection test failed', [
                'source_id' => $source->id,
                'url' => $source->url,
                'message' => $exception->getMessage(),
            ]);

            $errorType = $this->detectExceptionErrorType($exception->getMessage());
            $message = $errorType === 'ssl_certificate'
                ? 'Bağlantı testi başarısız: SSL sertifikası doğrulanamadı. Local PHP/Laragon CA sertifika ayarını kontrol edin veya onaylı XML dosyasını yerel dosya olarak kullanın.'
                : 'Bağlantı testi başarısız. Kaynak URL’sini kontrol edin.';

            return [
                'ok' => false,
                'status_code' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error_type' => $errorType,
                'message' => $message,
            ];
        }
    }

    private function resolveHttpMethod(SupplierSource $source): string
    {
        $method = Str::upper((string) ($source->config['http_method'] ?? 'GET'));

        return in_array($method, ['GET', 'POST'], true) ? $method : 'GET';
    }

    private function resolveHeaders(SupplierSource $source): array
    {
        $headers = [];
        $configuredHeaders = $source->config['request_headers'] ?? null;

        if (is_string($configuredHeaders) && trim($configuredHeaders) !== '') {
            $decoded = json_decode($configuredHeaders, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        } elseif (is_array($configuredHeaders)) {
            $headers = $configuredHeaders;
        }

        $authType = (string) ($source->config['auth_type'] ?? 'none');

        if ($authType === 'bearer' && filled($source->config['auth_token'] ?? null)) {
            $headers['Authorization'] = 'Bearer ' . $source->config['auth_token'];
        }

        if ($authType === 'api_key' && filled($source->config['api_key_name'] ?? null) && filled($source->config['api_key_value'] ?? null)) {
            $headers[(string) $source->config['api_key_name']] = (string) $source->config['api_key_value'];
        } elseif ($authType === 'api_key' && filled($source->config['api_key'] ?? null)) {
            $headers['X-API-KEY'] = (string) $source->config['api_key'];
        }

        return $headers;
    }

    private function resolveRequestBody(SupplierSource $source): mixed
    {
        $body = $source->config['request_body'] ?? null;

        if (!is_string($body) || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    private function buildSendOptions(SupplierSource $source, mixed $body): array
    {
        $options = [];

        if (($source->config['auth_type'] ?? 'none') === 'api_key'
            && filled($source->config['api_key_name'] ?? null)
            && filled($source->config['api_key_value'] ?? null)
            && empty($this->resolveHeaders($source)[$source->config['api_key_name']])) {
            $options['query'] = [
                (string) $source->config['api_key_name'] => (string) $source->config['api_key_value'],
            ];
        }

        if (is_array($body)) {
            $options['json'] = $body;
        } elseif (is_string($body) && trim($body) !== '') {
            $options['body'] = $body;
        }

        return $options;
    }

    private function applyAuth($request, SupplierSource $source)
    {
        return match ((string) ($source->config['auth_type'] ?? 'none')) {
            'basic' => $request->withBasicAuth(
                (string) ($source->config['auth_username'] ?? $source->config['username'] ?? ''),
                (string) ($source->config['auth_password'] ?? $source->config['password'] ?? '')
            ),
            default => $request,
        };
    }

    private function whitelistWarningMessage(): string
    {
        return 'Kaynak HTTP 403 döndü. Bu tedarikçi IP izni/whitelist istiyor olabilir. Canlı sunucu IP adresinizi tedarikçiye bildirmeniz veya onaylı IP’den alınan XML dosyasını local file olarak okutmanız gerekir.';
    }

    private function sslWarningMessage(): string
    {
        return 'SSL sertifika doğrulama hatası oluştu. Local PHP/Laragon ortamında CA sertifika dosyası eksik olabilir. Canlı ortamda geçerli SSL sertifikası kullanılmalı veya PHP cacert.pem ayarı düzeltilmelidir.';
    }

    private function detectExceptionErrorType(string $message): string
    {
        $message = Str::lower($message);

        return match (true) {
            str_contains($message, 'curl error 60'),
            str_contains($message, 'ssl certificate problem'),
            str_contains($message, 'unable to get local issuer certificate') => 'ssl_certificate',
            str_contains($message, 'timed out'),
            str_contains($message, 'timeout') => 'timeout',
            default => 'connection_error',
        };
    }

    private function detectContentType(SupplierSource $source, ?string $detectedType): string
    {
        if (filled($detectedType)) {
            return $detectedType;
        }

        $configuredFormat = $source->config['format'] ?? null;
        if (filled($configuredFormat)) {
            return Str::lower((string) $configuredFormat);
        }

        return $this->detectContentTypeFromPath($source->config['source_file_path'] ?? $source->url ?? null)
            ?? Str::lower((string) $source->source_type);
    }

    private function detectContentTypeFromString(?string $header): ?string
    {
        $header = Str::lower((string) $header);

        return match (true) {
            str_contains($header, 'xml') => 'xml',
            str_contains($header, 'json') => 'json',
            str_contains($header, 'csv') => 'csv',
            default => null,
        };
    }

    private function detectContentTypeFromPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $extension = Str::lower(pathinfo((string) $path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xml' => 'xml',
            'json' => 'json',
            'csv', 'txt' => 'csv',
            default => null,
        };
    }
}
