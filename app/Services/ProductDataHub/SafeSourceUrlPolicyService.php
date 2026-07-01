<?php

namespace App\Services\ProductDataHub;

use Illuminate\Support\Str;

class SafeSourceUrlPolicyService
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const BLOCKED_SCHEMES = ['file', 'ftp', 'sftp', 'gopher', 'dict', 'data', 'php', 'phar', 'jar', 'ldap', 'ssh'];

    private const BLOCKED_IPV4_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '169.254.169.254/32',
    ];

    private const BLOCKED_IPV6_RANGES = [
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function __construct(
        private readonly SensitiveDataMasker $masker
    ) {
    }

    public function validate(string $url): array
    {
        $url = trim($url);
        $maskedUrl = $this->masker->maskUrl($url);

        if ($url === '') {
            return $this->deny('Kaynak URL güvenlik politikası nedeniyle reddedildi: URL boş.', $maskedUrl);
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $this->deny('Kaynak URL güvenlik politikası nedeniyle reddedildi: URL çözümlenemedi.', $maskedUrl);
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $message = in_array($scheme, self::BLOCKED_SCHEMES, true)
                ? 'Kaynak URL güvenlik politikası nedeniyle reddedildi: yalnız http/https desteklenir.'
                : 'Kaynak URL güvenlik politikası nedeniyle reddedildi: desteklenmeyen URL şeması.';

            return $this->deny($message, $maskedUrl);
        }

        $host = Str::lower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return $this->deny('Kaynak URL güvenlik politikası nedeniyle reddedildi: host bilgisi boş.', $maskedUrl);
        }

        if ($this->isLocalhostHost($host)) {
            return $this->deny('Kaynak URL güvenlik politikası nedeniyle reddedildi: private/local adreslere erişilemez.', $maskedUrl);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->validateIp($host, $maskedUrl);
        }

        $resolvedIps = $this->resolveHostIps($host);
        if ($resolvedIps !== []) {
            foreach ($resolvedIps as $ip) {
                $ipValidation = $this->validateIp($ip, $maskedUrl);

                if (!$ipValidation['ok']) {
                    return $ipValidation;
                }
            }
        }

        return [
            'ok' => true,
            'message' => null,
            'masked_url' => $maskedUrl,
            'normalized_url' => $url,
        ];
    }

    public function resolveRedirectTarget(string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            $scheme = parse_url($currentUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $location;
        }

        $base = parse_url($currentUrl);
        if (!is_array($base) || blank($base['scheme'] ?? null) || blank($base['host'] ?? null)) {
            return null;
        }

        $origin = ($base['scheme'] ?? 'https') . '://' . $base['host'];

        if (filled($base['port'] ?? null)) {
            $origin .= ':' . $base['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $directory = $directory === '.' ? '' : $directory;

        return $origin . ($directory !== '' ? $directory : '') . '/' . ltrim($location, '/');
    }

    public function maskedUrl(string $url): ?string
    {
        return $this->masker->maskUrl($url);
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                        $ips[] = $record['ip'];
                    }

                    if (!empty($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === [] && function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);

            if (is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
        }

        return array_values(array_unique(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
    }

    private function validateIp(string $ip, ?string $maskedUrl): array
    {
        if ($this->isBlockedIp($ip)) {
            return $this->deny('Kaynak URL güvenlik politikası nedeniyle reddedildi: private/local adreslere erişilemez.', $maskedUrl);
        }

        return [
            'ok' => true,
            'message' => null,
            'masked_url' => $maskedUrl,
            'normalized_url' => null,
        ];
    }

    private function isLocalhostHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'localhost.localdomain'
            || Str::endsWith($host, '.localhost');
    }

    private function isBlockedIp(string $ip): bool
    {
        $ranges = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? self::BLOCKED_IPV6_RANGES
            : self::BLOCKED_IPV4_RANGES;

        foreach ($ranges as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr, 2);

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int) $prefix;
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $bits)) & 0xFF);

        return (ord($ipBinary[$bytes]) & ord($mask)) === (ord($subnetBinary[$bytes]) & ord($mask));
    }

    private function deny(string $message, ?string $maskedUrl): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'masked_url' => $maskedUrl,
            'normalized_url' => null,
        ];
    }
}
