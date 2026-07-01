<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SourceFetchService
{
    private const DEFAULT_USER_AGENT = 'Prodelya Product Data Hub';

    private const ALLOWED_CONTENT_TYPES = [
        'application/xml',
        'text/xml',
        'application/json',
        'text/json',
        'text/plain',
        'text/csv',
        'application/csv',
    ];

    public function __construct(
        private readonly SafeSourceUrlPolicyService $safeUrlPolicy,
        private readonly SensitiveDataMasker $masker,
    ) {
    }

    public function fetch(SupplierSource $source, array $options = []): array
    {
        $sourceFilePath = $source->config['source_file_path'] ?? null;
        $url = $source->url;

        if (filled($sourceFilePath)) {
            $result = $this->fetchLocalFile($sourceFilePath, $options);
        } elseif (filled($url)) {
            $result = $this->fetchUrl($source, $url, $options);
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

    public function fetchUrl(SupplierSource $source, string $url, array $options = []): array
    {
        $policy = $this->safeUrlPolicy->validate($url);

        if (!$policy['ok']) {
            return $this->blockedResult($source, (string) ($policy['message'] ?? 'Kaynak URL güvenlik politikası nedeniyle reddedildi.'), $url);
        }

        $userAgent = $this->resolveUserAgent($source);
        $headers = $this->resolveHeaders($source);
        $method = $this->resolveHttpMethod($source);
        $body = $this->resolveRequestBody($source);
        $security = $this->resolveFetchSecurity($source, $options);

        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            try {
                $request = $this->buildRequest($source, $userAgent, $headers, $security);
                $response = $this->sendRequest($request, $source, $method, $currentUrl, $body);

                if ($this->isRedirectResponse($response)) {
                    if ($redirectCount >= $security['max_redirects']) {
                        return $this->failedResult($source, $currentUrl, 'redirect_blocked', ['Kaynak URL güvenlik politikası nedeniyle reddedildi: yönlendirme limiti aşıldı.']);
                    }

                    $target = $this->safeUrlPolicy->resolveRedirectTarget($currentUrl, (string) $response->header('Location'));
                    if (!filled($target)) {
                        return $this->failedResult($source, $currentUrl, 'redirect_blocked', ['Kaynak URL güvenlik politikası nedeniyle reddedildi: yönlendirme hedefi çözümlenemedi.']);
                    }

                    $redirectPolicy = $this->safeUrlPolicy->validate($target);
                    if (!$redirectPolicy['ok']) {
                        return $this->blockedResult($source, (string) ($redirectPolicy['message'] ?? 'Kaynak URL güvenlik politikası nedeniyle reddedildi.'), $target);
                    }

                    $currentUrl = $target;
                    $redirectCount++;
                    continue;
                }

                if (!$response->successful()) {
                    Log::warning('Supplier source fetch failed', [
                        'url' => $this->safeUrlPolicy->maskedUrl($currentUrl),
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

                $declaredContentLength = (int) ($response->header('Content-Length') ?? 0);
                if ($declaredContentLength > 0 && $declaredContentLength > $security['max_bytes']) {
                    return $this->failedResult($source, $currentUrl, 'response_too_large', ['Kaynak güvenlik politikası nedeniyle reddedildi: yanıt boyutu izin verilen limiti aşıyor.']);
                }

                $rawContentType = $this->normalizeMimeType($response->header('Content-Type'));
                if (!$this->isAllowedContentType($source, $rawContentType, $options)) {
                    return $this->failedResult($source, $currentUrl, 'content_type_blocked', ['Kaynak güvenlik politikası nedeniyle reddedildi: içerik tipi desteklenmiyor.']);
                }

                $content = $response->body();
                if (strlen($content) > $security['max_bytes']) {
                    return $this->failedResult($source, $currentUrl, 'response_too_large', ['Kaynak güvenlik politikası nedeniyle reddedildi: yanıt boyutu izin verilen limiti aşıyor.']);
                }

                return [
                    'ok' => true,
                    'content' => $content,
                    'content_type' => $this->detectContentTypeFromString($rawContentType),
                    'status_code' => $response->status(),
                    'error_type' => 'none',
                    'errors' => [],
                    'warnings' => [],
                ];
            } catch (\Throwable $exception) {
                Log::warning('Supplier source fetch exception', [
                    'url' => $this->safeUrlPolicy->maskedUrl($currentUrl),
                    'method' => $method,
                    'message' => $this->masker->maskExceptionMessage($exception->getMessage()),
                ]);

                $errorType = $this->detectExceptionErrorType($exception->getMessage());
                $errors = ['Kaynak okunurken güvenli bağlantı hatası oluştu.'];
                $warnings = [];

                if ($errorType === 'ssl_certificate') {
                    $errors[] = 'SSL sertifika hatası';
                    $warnings[] = $this->sslWarningMessage();
                } elseif ($errorType === 'timeout') {
                    $errors[] = 'Kaynak isteği zaman aşımına uğradı.';
                }

                return [
                    'ok' => false,
                    'content' => null,
                    'content_type' => $this->detectContentTypeFromPath($currentUrl),
                    'status_code' => null,
                    'error_type' => $errorType,
                    'errors' => $errors,
                    'warnings' => $warnings,
                ];
            }
        }
    }

    public function fetchLocalFile(string $path, array $options = []): array
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

        $maxBytes = $this->resolveFetchSecurity(null, $options)['max_bytes'];
        if (strlen($content) > $maxBytes) {
            return [
                'ok' => false,
                'content' => null,
                'content_type' => $this->detectContentTypeFromPath($path),
                'status_code' => null,
                'error_type' => 'response_too_large',
                'errors' => ['Kaynak güvenlik politikası nedeniyle reddedildi: yanıt boyutu izin verilen limiti aşıyor.'],
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

        $result = $this->fetchUrl($source, (string) $source->url, [
            'context' => 'connection_test',
            'validate_content_type' => false,
        ]);

        if ($result['ok']) {
            return [
                'ok' => true,
                'status_code' => (int) ($result['status_code'] ?? 200),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error_type' => 'none',
                'message' => 'Bağlantı testi başarılı.',
            ];
        }

        $errorType = (string) ($result['error_type'] ?? 'connection_error');
        $message = match ($errorType) {
            'http_403' => 'Bağlantı testi başarısız. HTTP 403 döndü. Tedarikçi IP izni istiyor olabilir. Çözüm: Sunucu IP’sini tedarikçiye tanımlatın veya yerel dosya yolu ile onaylı XML dosyasını okutun.',
            'ssl_certificate' => 'Bağlantı testi başarısız: SSL sertifikası doğrulanamadı. Local PHP/Laragon CA sertifika ayarını kontrol edin veya onaylı XML dosyasını yerel dosya olarak kullanın.',
            'url_policy_blocked', 'redirect_blocked' => (string) ($result['errors'][0] ?? 'Bağlantı testi güvenlik politikası nedeniyle reddedildi.'),
            default => 'Bağlantı testi başarısız. Kaynak URL’sini kontrol edin.',
        };

        return [
            'ok' => false,
            'status_code' => $result['status_code'] ?? null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_type' => $errorType,
            'message' => $message,
        ];
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

        return $this->removeUserAgentHeaders($headers);
    }

    private function resolveUserAgent(SupplierSource $source): string
    {
        $configuredUserAgent = trim((string) ($source->config['user_agent'] ?? ''));
        if ($configuredUserAgent !== '') {
            return $configuredUserAgent;
        }

        $configuredHeaders = $source->config['request_headers'] ?? null;
        $headers = [];

        if (is_string($configuredHeaders) && trim($configuredHeaders) !== '') {
            $decoded = json_decode($configuredHeaders, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        } elseif (is_array($configuredHeaders)) {
            $headers = $configuredHeaders;
        }

        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'User-Agent') === 0 && filled($value)) {
                return trim((string) $value);
            }
        }

        return self::DEFAULT_USER_AGENT;
    }

    private function removeUserAgentHeaders(array $headers): array
    {
        return collect($headers)
            ->reject(fn ($value, $key) => strcasecmp((string) $key, 'User-Agent') === 0)
            ->all();
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
            && empty($this->resolveHeaders($source)[$source->config['api_key_name']])
        ) {
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

    private function buildRequest(SupplierSource $source, string $userAgent, array $headers, array $security): PendingRequest
    {
        $request = Http::timeout($security['timeout_seconds'])
            ->connectTimeout($security['connect_timeout_seconds'])
            ->accept('*/*')
            ->withUserAgent($userAgent)
            ->withHeaders($headers)
            ->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

        return $this->applyAuth($request, $source);
    }

    private function sendRequest(PendingRequest $request, SupplierSource $source, string $method, string $url, mixed $body): Response
    {
        return match ($method) {
            'POST' => $request->send('POST', $url, $this->buildSendOptions($source, $body)),
            default => $request->send('GET', $url, $this->buildSendOptions($source, null)),
        };
    }

    private function resolveFetchSecurity(?SupplierSource $source, array $options = []): array
    {
        $context = (string) ($options['context'] ?? 'preview');
        $config = config('prodelya_product_data_hub.fetch_security', []);
        $defaultTimeout = (int) ($config['timeout_seconds'] ?? 20);
        $defaultConnectTimeout = (int) ($config['connect_timeout_seconds'] ?? 10);

        return [
            'timeout_seconds' => max(1, min(120, (int) data_get($source?->config, 'timeout_seconds', $defaultTimeout))),
            'connect_timeout_seconds' => max(1, min(120, (int) ($config['connect_timeout_seconds'] ?? $defaultConnectTimeout))),
            'max_redirects' => max(0, min(10, (int) ($config['max_redirects'] ?? 3))),
            'max_bytes' => max(1024, (int) ($options['max_bytes'] ?? ($context === 'sync'
                ? ($config['max_sync_bytes'] ?? (150 * 1024 * 1024))
                : ($config['max_preview_bytes'] ?? (25 * 1024 * 1024))))),
        ];
    }

    private function applyAuth(PendingRequest $request, SupplierSource $source): PendingRequest
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
            str_contains($header, 'plain') => null,
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

    private function normalizeMimeType(?string $contentType): ?string
    {
        if (!is_string($contentType) || trim($contentType) === '') {
            return null;
        }

        return Str::lower(trim(strtok($contentType, ';')));
    }

    private function isAllowedContentType(SupplierSource $source, ?string $mimeType, array $options = []): bool
    {
        if (($options['validate_content_type'] ?? true) === false) {
            return true;
        }

        if ($mimeType === null || $mimeType === '') {
            return true;
        }

        if (in_array($mimeType, self::ALLOWED_CONTENT_TYPES, true)) {
            return true;
        }

        return $mimeType === 'application/octet-stream'
            && (bool) data_get($source->config, 'allow_octet_stream', false);
    }

    private function isRedirectResponse(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function blockedResult(SupplierSource $source, string $message, string $url): array
    {
        Log::warning('Supplier source URL blocked by safe policy', [
            'url' => $this->safeUrlPolicy->maskedUrl($url),
            'source_id' => $source->id,
        ]);

        return [
            'ok' => false,
            'content' => null,
            'content_type' => $this->detectContentTypeFromPath($url),
            'status_code' => null,
            'error_type' => 'url_policy_blocked',
            'errors' => [$message],
            'warnings' => [],
        ];
    }

    private function failedResult(SupplierSource $source, string $url, string $errorType, array $errors): array
    {
        Log::warning('Supplier source fetch blocked', [
            'url' => $this->safeUrlPolicy->maskedUrl($url),
            'source_id' => $source->id,
            'error_type' => $errorType,
        ]);

        return [
            'ok' => false,
            'content' => null,
            'content_type' => $this->detectContentTypeFromPath($url),
            'status_code' => null,
            'error_type' => $errorType,
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
