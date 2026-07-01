<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdmin\BackupReadinessService;
use App\Services\SuperAdmin\StorageReadinessService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use App\Services\SuperAdmin\SuperAdminSystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SuperAdminSystemHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('logs/zz-super-admin-health-test.log'));

        parent::tearDown();
    }

    public function test_health_context_builds_all_expected_keys(): void
    {
        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();

        $this->assertArrayHasKey('queue_worker', $context);
        $this->assertArrayHasKey('scheduler', $context);
        $this->assertArrayHasKey('failed_jobs', $context);
        $this->assertArrayHasKey('backup', $context);
        $this->assertArrayHasKey('disk_usage', $context);
        $this->assertArrayHasKey('database', $context);
        $this->assertArrayHasKey('cache', $context);
        $this->assertArrayHasKey('storage_link', $context);
        $this->assertArrayHasKey('log_errors', $context);
        $this->assertArrayHasKey('php_compatibility', $context);

        $this->assertSame('queue_worker', $context['queue_worker']['key']);
        $this->assertSame('Kuyruk Çalışanı', $context['queue_worker']['label']);
        $this->assertNotEmpty($context['queue_worker']['checked_at']);
    }

    public function test_failed_jobs_summary_is_safe_and_does_not_expose_raw_payload(): void
    {
        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['token' => 'super-secret-token', 'password' => 'danger']),
                'exception' => 'RuntimeException: hidden stack trace',
                'failed_at' => now()->subHours(2),
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['api_key' => 'value']),
                'exception' => 'RuntimeException: hidden stack trace',
                'failed_at' => now()->subDays(3),
            ],
        ]);

        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();
        $json = json_encode($context['failed_jobs'], JSON_UNESCAPED_UNICODE);

        $this->assertSame('warning', $context['failed_jobs']['status']);
        $this->assertStringContainsString('Son 24 saatte 1 başarısız iş var.', $context['failed_jobs']['description']);
        $this->assertStringContainsString('Son 7 gün: 2', implode(' | ', $context['failed_jobs']['details']));
        $this->assertStringNotContainsString('super-secret-token', $json);
        $this->assertStringNotContainsString('danger', $json);
        $this->assertStringNotContainsString('hidden stack trace', $json);
    }

    public function test_database_health_is_reported_as_healthy(): void
    {
        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();

        $this->assertSame('healthy', $context['database']['status']);
        $this->assertStringContainsString('Veritabanı bağlantısı', $context['database']['description']);
    }

    public function test_database_health_fallback_masks_sensitive_details(): void
    {
        $service = Mockery::mock(SuperAdminSystemHealthService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('buildDatabaseHealthItem')->once()->andThrow(new \RuntimeException('database password invalid at C:\\secret'));

        $context = $service->buildHealthContext();

        $this->assertSame('unknown', $context['database']['status']);
        $this->assertStringContainsString('Hassas detay gizlendi.', $context['database']['description']);
        $this->assertStringNotContainsString('password', strtolower($context['database']['description']));
        $this->assertStringNotContainsString('C:\\', $context['database']['description']);
    }

    public function test_disk_usage_and_storage_context_do_not_expose_absolute_paths(): void
    {
        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();
        $json = json_encode([
            'disk_usage' => $context['disk_usage'],
            'storage_link' => $context['storage_link'],
        ], JSON_UNESCAPED_UNICODE);

        $this->assertNotEmpty($context['disk_usage']['description']);
        $this->assertNotEmpty($context['storage_link']['details']);
        $this->assertStringNotContainsString(base_path(), $json);
        $this->assertStringNotContainsString(storage_path(), $json);
    }

    public function test_cache_context_stays_safe_without_leaking_redis_credentials(): void
    {
        config()->set('cache.default', 'file');

        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();
        $json = json_encode($context['cache'], JSON_UNESCAPED_UNICODE);

        $this->assertSame('healthy', $context['cache']['status']);
        $this->assertStringContainsString('Önbellek sürücüsü', $context['cache']['description']);
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('secret', strtolower($json));
    }

    public function test_scheduler_without_heartbeat_and_backup_without_source_do_not_fake_healthy(): void
    {
        $backupMock = Mockery::mock(BackupReadinessService::class);
        $backupMock->shouldReceive('buildBackupStatus')->andReturn([
            'status' => 'unknown',
            'status_label' => 'Bilinmiyor',
            'policy_summary' => 'Yedek kaynağı bulunamadı.',
            'warnings' => ['Test uyarısı'],
            'details' => ['Detay'],
            'is_placeholder' => true,
            'checked_at' => now()->format('d.m.Y H:i'),
        ]);
        $this->app->instance(BackupReadinessService::class, $backupMock);

        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();

        $this->assertContains($context['scheduler']['status'], ['unknown', 'warning']);
        $this->assertNotSame('healthy', $context['scheduler']['status']);
        $this->assertContains($context['backup']['status'], ['unknown', 'warning']);
        $this->assertNotSame('healthy', $context['backup']['status']);
    }

    public function test_log_summary_does_not_render_raw_error_lines(): void
    {
        $path = storage_path('logs/zz-super-admin-health-test.log');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "[2026-06-30 10:00:00] local.ERROR: token=abc smtp_password=demo\n[2026-06-30 10:01:00] local.INFO: okay");
        touch($path, time() + 30);

        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();
        $json = json_encode($context['log_errors'], JSON_UNESCAPED_UNICODE);

        $this->assertNotEmpty($context['log_errors']['description']);
        $this->assertStringNotContainsString('token=abc', $json);
        $this->assertStringNotContainsString('smtp_password', strtolower($json));
        $this->assertStringContainsString('hata kaydı', Str::lower($context['log_errors']['description']));
    }

    public function test_operation_dashboard_service_uses_system_health_service(): void
    {
        $mock = Mockery::mock(SuperAdminSystemHealthService::class);
        $mock->shouldReceive('buildHealthContext')->once()->andReturn([
            'queue_worker' => [
                'key' => 'queue_worker',
                'label' => 'Kuyruk Çalışanı',
                'status' => 'healthy',
                'status_label' => 'Sağlıklı',
                'description' => 'Test sinyali.',
                'checked_at' => now()->format('d.m.Y H:i'),
                'route' => null,
                'is_placeholder' => false,
                'details' => [],
            ],
        ]);
        $this->app->instance(SuperAdminSystemHealthService::class, $mock);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertSame('Test sinyali.', $context['system_health']['queue_worker']['description']);
    }

    public function test_dashboard_renders_system_health_cards(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Sistem Sağlığı');
        $response->assertSee('Kuyruk Çalışanı');
        $response->assertSee('Zamanlayıcı');
        $response->assertSee('Başarısız İşler');
        $response->assertSee('Son Yedekleme');
        $response->assertSee('Önbellek / Redis');
        $response->assertSee('Son kontrol:');
    }
}
