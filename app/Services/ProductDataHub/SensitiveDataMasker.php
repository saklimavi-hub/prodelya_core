<?php

namespace App\Services\ProductDataHub;

class SensitiveDataMasker
{
    private const DEFAULT_URL_MASK = '***';

    private const DEFAULT_HEADER_MASK = '[hidden]';

    private const SENSITIVE_QUERY_KEYS = [
        'token',
        'api_key',
        'apikey',
        'key',
        'secret',
        'password',
        'pass',
        'auth',
        'access_token',
        'refresh_token',
        'signature',
    ];

    private const SENSITIVE_HEADER_KEYS = [
        'authorization',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'cookie',
        'set-cookie',
    ];

    public function maskUrl(?string $url, string $replacement = self::DEFAULT_URL_MASK): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $maskedQuery = '';

        if (array_key_exists('query', $parts) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach ($query as $key => $value) {
                if ($this->isSensitiveQueryKey((string) $key)) {
                    $query[$key] = is_array($value)
                        ? array_fill(0, count($value), $replacement)
                        : $replacement;
                }
            }

            $maskedQuery = http_build_query($query);
        }

        $rebuilt = '';

        if (!empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }

        if (!empty($parts['user'])) {
            $rebuilt .= $parts['user'];

            if (array_key_exists('pass', $parts)) {
                $rebuilt .= ':' . $replacement;
            }

            $rebuilt .= '@';
        }

        $rebuilt .= $parts['host'] ?? '';

        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        if ($maskedQuery !== '') {
            $rebuilt .= '?' . $maskedQuery;
        } elseif (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    public function maskHeaders(array $headers, string $replacement = self::DEFAULT_HEADER_MASK): array
    {
        $masked = [];

        foreach ($headers as $key => $value) {
            $masked[$key] = $this->isSensitiveHeaderKey((string) $key) && filled($value)
                ? $replacement
                : $value;
        }

        return $masked;
    }

    public function restoreMaskedHeaders(array $newHeaders, array $existingHeaders, string $maskedValue = self::DEFAULT_HEADER_MASK): array
    {
        foreach ($newHeaders as $key => $value) {
            if ($this->isSensitiveHeaderKey((string) $key)
                && $value === $maskedValue
                && array_key_exists($key, $existingHeaders)
            ) {
                $newHeaders[$key] = $existingHeaders[$key];
            }
        }

        return $newHeaders;
    }

    public function maskExceptionMessage(?string $message): ?string
    {
        if (!is_string($message) || trim($message) === '') {
            return $message;
        }

        $masked = preg_replace('/\b(authorization|x-api-key|api-key|x-auth-token|cookie|set-cookie)\b\s*[:=]\s*([^\s,;]+)/i', '$1=' . self::DEFAULT_HEADER_MASK, $message);
        $masked = preg_replace('/\b(token|api_key|apikey|key|secret|password|pass|auth|access_token|refresh_token|signature)\b\s*=\s*([^\s&]+)/i', '$1=' . self::DEFAULT_URL_MASK, $masked ?? $message);

        return $this->maskUrl($masked ?? $message) ?? ($masked ?? $message);
    }

    public function isSensitiveHeaderKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::SENSITIVE_HEADER_KEYS, true)
            || (bool) preg_match('/authorization|token|api[-_ ]?key|secret|password/i', $key);
    }

    public function isSensitiveQueryKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::SENSITIVE_QUERY_KEYS, true);
    }
}
