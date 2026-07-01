<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdmin\ProductionEnvironmentReadinessService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalSqliteEnvironmentReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_local_sqlite_with_database_sessions_returns_warning(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'sqlite',
            'session.driver' => 'database',
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());
        $check = $checks->firstWhere('key', 'sqlite_session_lock_risk');

        $this->assertNotNull($check);
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('SESSION_DRIVER=file önerilir', $check['description']);
    }

    public function test_local_sqlite_with_file_sessions_clears_lock_warning(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'sqlite',
            'session.driver' => 'file',
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());
        $check = $checks->firstWhere('key', 'sqlite_session_lock_risk');

        $this->assertNotNull($check);
        $this->assertSame('ready', $check['status']);
        $this->assertStringContainsString('kilit riski azaltılmış', $check['description']);
    }

    public function test_production_sqlite_with_database_sessions_returns_blocked(): void
    {
        config([
            'app.env' => 'production',
            'database.default' => 'sqlite',
            'session.driver' => 'database',
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());
        $check = $checks->firstWhere('key', 'sqlite_session_lock_risk');

        $this->assertNotNull($check);
        $this->assertSame('blocked', $check['status']);
        $this->assertStringContainsString('Production ortamında SQLite', $check['description']);
    }

    public function test_dashboard_security_warnings_include_local_sqlite_session_lock_signal(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => false,
            'app.url' => 'http://localhost',
            'database.default' => 'sqlite',
            'session.driver' => 'database',
        ]);

        User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();
        $warnings = collect($context['security_warnings'] ?? []);

        $this->assertTrue($warnings->contains(
            fn (array $item): bool => ($item['title'] ?? null) === 'SQLite oturum kilidi riski kontrol edilmeli'
        ));
    }
}
