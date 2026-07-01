<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdmin\ProductionEnvironmentReadinessService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionEnvironmentReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_readiness_service_never_returns_secret_values(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://saklimavi.net',
            'session.secure' => true,
            'session.domain' => '.saklimavi.net',
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net'],
            'prodelya_domains.reserved_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'localhost'],
            'prodelya_domains.main_domain' => 'saklimavi.net',
            'prodelya_domains.force_https' => true,
            'cache.default' => 'database',
            'session.driver' => 'database',
            'filesystems.default' => 'local',
        ]);

        $checks = app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks();
        $json = strtolower((string) json_encode($checks, JSON_UNESCAPED_UNICODE));

        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('redis_password', $json);
        $this->assertStringNotContainsString('db_password', $json);
        $this->assertStringNotContainsString('api key', $json);
    }

    public function test_app_debug_true_returns_blocked_status(): void
    {
        config(['app.debug' => true]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());

        $this->assertSame('blocked', $checks->firstWhere('key', 'app_debug')['status']);
    }

    public function test_local_environment_and_localhost_url_raise_readiness_risks(): void
    {
        config([
            'app.env' => 'local',
            'app.url' => 'http://localhost',
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());

        $this->assertContains($checks->firstWhere('key', 'app_env')['status'], ['warning', 'blocked']);
        $this->assertSame('blocked', $checks->firstWhere('key', 'app_url')['status']);
    }

    public function test_log_mailer_and_missing_secure_cookie_raise_warnings(): void
    {
        config([
            'mail.default' => 'log',
            'session.secure' => false,
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());

        $this->assertContains($checks->firstWhere('key', 'mail_mailer')['status'], ['warning', 'blocked']);
        $this->assertContains($checks->firstWhere('key', 'session_secure_cookie')['status'], ['warning', 'blocked']);
    }

    public function test_dashboard_security_warnings_include_production_readiness_signals_without_secret_leakage(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'session.secure' => false,
            'mail.default' => 'log',
            'prodelya_domains.central_hosts' => [self::CENTRAL_HOST],
            'prodelya_domains.reserved_hosts' => ['prodelya_core.test', 'localhost', '127.0.0.1'],
            'prodelya_domains.main_domain' => 'saklimavi.net',
        ]);

        User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();
        $warnings = collect($context['security_warnings'] ?? []);
        $json = strtolower((string) json_encode($warnings, JSON_UNESCAPED_UNICODE));

        $this->assertTrue($warnings->contains(fn (array $item): bool => ($item['title'] ?? null) === 'APP_DEBUG canlıda kapalı olmalı'));
        $this->assertTrue($warnings->contains(fn (array $item): bool => ($item['title'] ?? null) === 'APP_URL canlı alan adına işaret etmeli'));
        $this->assertTrue($warnings->contains(fn (array $item): bool => ($item['title'] ?? null) === 'MAIL_MAILER log olmamalı'));
        $this->assertTrue($warnings->contains(fn (array $item): bool => ($item['title'] ?? null) === 'Güvenli Çerez açık olmalı'));
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('token', $json);
    }
}
