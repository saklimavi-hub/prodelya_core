<?php

namespace App\Services\SuperAdmin;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductionEnvironmentReadinessService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildReadinessChecks(): array
    {
        $centralHosts = $this->configArray('prodelya_domains.central_hosts');
        $reservedHosts = $this->configArray('prodelya_domains.reserved_hosts');
        $localHosts = $this->configArray('prodelya_domains.local_hosts');
        $appUrl = (string) config('app.url');
        $sessionDomain = trim((string) config('session.domain'));
        $mainDomain = trim((string) config('prodelya_domains.main_domain'));
        $appUrlHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appEnv = (string) config('app.env');
        $dbConnection = strtolower((string) config('database.default'));
        $sessionDriver = strtolower((string) config('session.driver'));
        $sqliteProtection = config('prodelya_local.sqlite_lock_protection', []);

        return [
            $this->check(
                'app_env',
                'APP_ENV üretim modunda olmalı',
                $this->statusForProductionEnvironment($appEnv),
                sprintf('Mevcut değer: %s', $appEnv)
            ),
            $this->check(
                'app_debug',
                'APP_DEBUG canlıda kapalı olmalı',
                (bool) config('app.debug') ? 'blocked' : 'ready',
                (bool) config('app.debug')
                    ? 'Hata ayrıntıları canlı ortamda açığa çıkabilir.'
                    : 'Canlı hata görünürlüğü güvenli modda.',
            ),
            $this->check(
                'app_url',
                'APP_URL canlı alan adına işaret etmeli',
                $this->statusForAppUrl($appUrlHost),
                $this->statusForAppUrl($appUrlHost) === 'blocked'
                    ? 'APP_URL localhost veya boş görünüyor.'
                    : 'APP_URL canlı yönlendirme için uygun görünüyor.'
            ),
            $this->check(
                'session_secure_cookie',
                'Güvenli Çerez açık olmalı',
                config('session.secure') === true ? 'ready' : 'blocked',
                config('session.secure') === true
                    ? 'HTTPS üzerinden güvenli çerez kullanımı açık.'
                    : 'SESSION_SECURE_COOKIE kapalı veya tanımsız.'
            ),
            $this->check(
                'session_domain',
                'Oturum Alan Adı merkezi alan adıyla uyumlu olmalı',
                $this->statusForSessionDomain($sessionDomain, $mainDomain),
                $sessionDomain !== ''
                    ? sprintf('Oturum alan adı: %s', $sessionDomain)
                    : 'SESSION_DOMAIN tanımlı değil.'
            ),
            $this->check(
                'mail_mailer',
                'MAIL_MAILER log olmamalı',
                strtolower((string) config('mail.default')) === 'log' ? 'blocked' : 'ready',
                sprintf('Mevcut gönderici: %s', (string) config('mail.default'))
            ),
            $this->check(
                'queue_connection',
                'QUEUE_CONNECTION senkron modda olmamalı',
                strtolower((string) config('queue.default')) === 'sync' ? 'blocked' : 'ready',
                sprintf('Mevcut kuyruk bağlantısı: %s', (string) config('queue.default'))
            ),
            $this->check(
                'central_hosts',
                'Merkezi alan adları tanımlı olmalı',
                $centralHosts !== [] ? 'ready' : 'blocked',
                $centralHosts !== []
                    ? sprintf('%d merkezi alan adı tanımlı.', count($centralHosts))
                    : 'PRODELYA_CENTRAL_HOSTS boş görünüyor.'
            ),
            $this->check(
                'reserved_hosts',
                'Ayrılmış alan adları tanımlı olmalı',
                $reservedHosts !== [] ? 'ready' : 'blocked',
                $reservedHosts !== []
                    ? sprintf('%d ayrılmış alan adı tanımlı.', count($reservedHosts))
                    : 'PRODELYA_RESERVED_HOSTS boş görünüyor.'
            ),
            $this->check(
                'force_https',
                'HTTPS zorlama açık olmalı',
                config('prodelya_domains.force_https') ? 'ready' : 'warning',
                config('prodelya_domains.force_https')
                    ? 'Merkezi yönlendirmelerde HTTPS bekleniyor.'
                    : 'PRODELYA_FORCE_HTTPS kapalı; canlıda doğrulanmalı.'
            ),
            $this->check(
                'filesystem_disk',
                'Dosya sistemi diski belirlenmiş olmalı',
                filled(config('filesystems.default')) ? 'ready' : 'warning',
                sprintf('Varsayılan disk: %s', (string) config('filesystems.default'))
            ),
            $this->check(
                'db_connection',
                'Veritabanı bağlantısı canlı için tanımlı olmalı',
                filled(config('database.default')) ? 'ready' : 'blocked',
                sprintf('Varsayılan bağlantı: %s', (string) config('database.default'))
            ),
            $this->check(
                'cache_store',
                'Önbellek sürücüsü canlı kullanım için net olmalı',
                filled(config('cache.default')) ? 'ready' : 'warning',
                sprintf('Önbellek sürücüsü: %s', (string) config('cache.default'))
            ),
            $this->check(
                'session_driver',
                'Oturum sürücüsü canlı kullanım için net olmalı',
                filled(config('session.driver')) ? 'ready' : 'warning',
                sprintf('Oturum sürücüsü: %s', (string) config('session.driver'))
            ),
            $this->check(
                'sqlite_session_lock_risk',
                'SQLite oturum kilidi riski kontrol edilmeli',
                $this->statusForSqliteSessionLockRisk($appEnv, $dbConnection, $sessionDriver, $sqliteProtection),
                $this->descriptionForSqliteSessionLockRisk($appEnv, $dbConnection, $sessionDriver, $sqliteProtection),
            ),
            $this->check(
                'local_hosts',
                'Yerel geliştirme hostları üretim hostlarından ayrılmalı',
                $localHosts !== [] ? 'ready' : 'unknown',
                $localHosts !== []
                    ? sprintf('%d yerel geliştirme hostu tanımlı.', count($localHosts))
                    : 'Yerel host listesi boş; geliştirme fallback davranışı kontrol edilmeli.'
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function actionableWarnings(int $limit = 5): array
    {
        return collect($this->buildReadinessChecks())
            ->filter(fn (array $check): bool => in_array($check['status'], ['blocked', 'warning'], true))
            ->sortBy([
                fn (array $check) => match ($check['status']) {
                    'blocked' => 0,
                    'warning' => 1,
                    default => 2,
                },
                fn (array $check) => $check['label'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function configArray(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            Arr::wrap(config($key, []))
        )));
    }

    protected function statusForProductionEnvironment(string $appEnv): string
    {
        return strtolower(trim($appEnv)) === 'production' ? 'ready' : 'warning';
    }

    protected function statusForAppUrl(string $host): string
    {
        if ($host === '' || $this->isLocalLikeHost($host)) {
            return 'blocked';
        }

        return 'ready';
    }

    protected function statusForSessionDomain(string $sessionDomain, string $mainDomain): string
    {
        if ($sessionDomain === '') {
            return 'blocked';
        }

        $normalizedSession = ltrim(strtolower($sessionDomain), '.');
        $normalizedMain = strtolower(trim($mainDomain));

        if ($normalizedMain !== '' && Str::endsWith($normalizedSession, $normalizedMain)) {
            return 'ready';
        }

        return 'warning';
    }

    protected function statusForSqliteSessionLockRisk(
        string $appEnv,
        string $dbConnection,
        string $sessionDriver,
        mixed $sqliteProtection = [],
    ): string
    {
        $appEnv = strtolower(trim($appEnv));
        $dbConnection = strtolower(trim($dbConnection));
        $sessionDriver = strtolower(trim($sessionDriver));
        $overrideActive = (bool) data_get($sqliteProtection, 'active', false);

        if ($dbConnection !== 'sqlite') {
            return 'ready';
        }

        if ($overrideActive) {
            return 'warning';
        }

        if ($sessionDriver !== 'database') {
            return 'ready';
        }

        if ($appEnv === 'production') {
            return 'blocked';
        }

        if (in_array($appEnv, ['local', 'development', 'testing'], true)) {
            return 'warning';
        }

        return 'warning';
    }

    protected function descriptionForSqliteSessionLockRisk(
        string $appEnv,
        string $dbConnection,
        string $sessionDriver,
        mixed $sqliteProtection = [],
    ): string
    {
        $appEnv = strtolower(trim($appEnv));
        $dbConnection = strtolower(trim($dbConnection));
        $sessionDriver = strtolower(trim($sessionDriver));
        $overrideActive = (bool) data_get($sqliteProtection, 'active', false);
        $sessionOverridden = (bool) data_get($sqliteProtection, 'session_driver_overridden', false);
        $cacheOverridden = (bool) data_get($sqliteProtection, 'cache_store_overridden', false);

        if ($dbConnection !== 'sqlite') {
            return 'Varsayılan veritabanı SQLite değil; bu kilit riski beklenmiyor.';
        }

        if ($overrideActive) {
            $parts = [];

            if ($sessionOverridden) {
                $parts[] = 'SESSION_DRIVER otomatik olarak file moduna alındı';
            }

            if ($cacheOverridden) {
                $parts[] = 'CACHE_STORE otomatik olarak file moduna alındı';
            }

            $summary = $parts !== [] ? implode(', ', $parts) : 'Local SQLite koruması devrede';

            return $summary . '. Kalıcı çözüm için local .env içinde file tabanlı ayarlar önerilir.';
        }

        if ($sessionDriver !== 'database') {
            return 'SQLite ile oturum sürücüsü database dışında yapılandırılmış; local kilit riski azaltılmış görünüyor.';
        }

        if ($appEnv === 'production') {
            return 'Production ortamında SQLite ile database session kullanımı kilit ve performans riski oluşturabilir.';
        }

        return 'Local SQLite ortamında database session kilitlenme riski oluşturabilir. Geliştirme için SESSION_DRIVER=file önerilir.';
    }

    protected function isLocalLikeHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1'], true)
            || Str::endsWith($host, ['.test', '.local']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function check(string $key, string $label, string $status, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'description' => Str::limit(trim(strip_tags($description)), 180),
            'is_secret_safe' => true,
        ];
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Hazır',
            'warning' => 'Kontrol Gerekir',
            'blocked' => 'Bloklu',
            default => 'Bilinmiyor',
        };
    }
}
