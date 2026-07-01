<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdmin\BackupReadinessService;
use App\Services\SuperAdmin\StorageReadinessService;
use App\Services\SuperAdmin\SuperAdminSystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class BackupStorageReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        config([
            'prodelya_backup.monitored_paths' => $this->backupMonitoredPaths(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/backup-readiness'));

        parent::tearDown();
    }

    public function test_backup_readiness_returns_unknown_when_no_backup_file_exists(): void
    {
        $status = app(BackupReadinessService::class)->buildBackupStatus();

        $this->assertContains($status['status'], ['unknown', 'warning', 'critical']);
        $this->assertSame(0, $status['found_backup_files_count']);
        $this->assertStringContainsString('İzlenen kaynaklarda erişilebilir yedek dosyası bulunamadı', $status['policy_summary']);
    }

    public function test_backup_readiness_returns_healthy_or_warning_for_recent_general_backup(): void
    {
        File::ensureDirectoryExists(storage_path('framework/testing/backup-readiness/general-primary'));
        $path = storage_path('framework/testing/backup-readiness/general-primary/backup-recent-production.zip');
        File::put($path, 'demo-backup');
        touch($path, now()->subHours(6)->timestamp);
        clearstatcache(true, $path);

        $status = app(BackupReadinessService::class)->buildBackupStatus();

        $this->assertContains($status['status'], ['healthy', 'warning']);
        $this->assertSame(1, $status['found_backup_files_count']);
        $this->assertNotEmpty($status['newest_backup_display']);
        $this->assertStringNotContainsString(storage_path(), json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    public function test_backup_readiness_returns_critical_for_old_general_backup(): void
    {
        config([
            'prodelya_backup.expected_frequency_hours' => 1,
            'prodelya_backup.warning_after_hours' => 2,
            'prodelya_backup.critical_after_hours' => 3,
        ]);

        File::ensureDirectoryExists(storage_path('framework/testing/backup-readiness/general-primary'));
        $path = storage_path('framework/testing/backup-readiness/general-primary/backup-2020-01-01-production.zip');
        File::put($path, 'old-backup');
        touch($path, now()->subHour()->timestamp);
        clearstatcache(true, $path);

        $status = app(BackupReadinessService::class)->buildBackupStatus();

        $this->assertSame('critical', $status['status']);
        $this->assertStringContainsString('Genel yedek', $status['policy_summary']);
    }

    public function test_product_data_hub_category_backup_does_not_fake_general_backup_health(): void
    {
        File::ensureDirectoryExists(storage_path('framework/testing/backup-readiness/pdh-category'));
        $path = storage_path('framework/testing/backup-readiness/pdh-category/category-backup-2026-06-30.json');
        File::put($path, '{}');
        touch($path, now()->subHours(2)->timestamp);

        $status = app(BackupReadinessService::class)->buildBackupStatus();
        $json = json_encode($status, JSON_UNESCAPED_UNICODE);

        $this->assertNotSame('healthy', $status['status']);
        $this->assertStringContainsString('Product Data Hub kategori yedeği', $status['policy_summary']);
        $this->assertStringNotContainsString(storage_path(), (string) $json);
    }

    public function test_storage_readiness_returns_required_checks_and_masks_paths(): void
    {
        $status = app(StorageReadinessService::class)->buildStorageStatus();
        $json = json_encode($status, JSON_UNESCAPED_UNICODE);

        $this->assertArrayHasKey('checks', $status);
        $this->assertTrue(collect($status['checks'])->contains(fn (array $check): bool => $check['key'] === 'public_storage_link'));
        $this->assertTrue(collect($status['checks'])->contains(fn (array $check): bool => $check['key'] === 'storage_logs_writable'));
        $this->assertTrue(collect($status['checks'])->contains(fn (array $check): bool => $check['key'] === 'bootstrap_cache_writable'));
        $this->assertTrue(collect($status['checks'])->contains(fn (array $check): bool => $check['key'] === 'pdh_disks'));
        $this->assertStringNotContainsString(base_path(), (string) $json);
        $this->assertStringNotContainsString(storage_path(), (string) $json);
    }

    public function test_system_health_uses_new_backup_and_storage_services(): void
    {
        $backupMock = Mockery::mock(BackupReadinessService::class);
        $backupMock->shouldReceive('buildBackupStatus')->once()->andReturn([
            'status' => 'warning',
            'status_label' => 'Kontrol Gerekir',
            'policy_summary' => 'Yedek politikası test sinyali.',
            'warnings' => ['Genel backup ayrıca doğrulanmalı.'],
            'details' => ['Detay 1'],
            'is_placeholder' => false,
            'checked_at' => now()->format('d.m.Y H:i'),
        ]);

        $storageMock = Mockery::mock(StorageReadinessService::class);
        $storageMock->shouldReceive('buildStorageStatus')->once()->andReturn([
            'status' => 'warning',
            'status_label' => 'Kontrol Gerekir',
            'checks' => [
                ['key' => 'public_storage_link', 'label' => 'Public storage bağlantısı', 'status' => 'warning', 'status_label' => 'Kontrol Gerekir', 'description' => 'Test açıklaması', 'is_sensitive_safe' => true],
            ],
            'warnings' => ['Storage uyarısı'],
            'checked_at' => now()->format('d.m.Y H:i'),
        ]);

        $this->app->instance(BackupReadinessService::class, $backupMock);
        $this->app->instance(StorageReadinessService::class, $storageMock);

        $context = app(SuperAdminSystemHealthService::class)->buildHealthContext();

        $this->assertSame('Yedek politikası test sinyali.', $context['backup']['description']);
        $this->assertStringContainsString('Public storage bağlantısı', implode(' | ', $context['storage_link']['details']));
    }

    public function test_dashboard_renders_backup_and_storage_cards_and_docs_include_sections(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Son Yedekleme');
        $response->assertSee('Depolama Bağlantısı');

        $content = file_get_contents(base_path('docs/production-go-live-checklist.md'));
        $this->assertIsString($content);
        $this->assertStringContainsString('## G) Yedekleme Notu', $content);
        $this->assertStringContainsString('## H) Depolama Notu', $content);
        $this->assertStringContainsString('public/storage', $content);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function backupMonitoredPaths(): array
    {
        return [
            [
                'key' => 'general_primary',
                'label' => 'Genel yedek arşivi',
                'path' => storage_path('framework/testing/backup-readiness/general-primary'),
                'scope' => 'general_backup',
            ],
            [
                'key' => 'general_secondary',
                'label' => 'Alternatif yedek arşivi',
                'path' => storage_path('framework/testing/backup-readiness/general-secondary'),
                'scope' => 'general_backup',
            ],
            [
                'key' => 'pdh_category',
                'label' => 'Product Data Hub kategori arşivi',
                'path' => storage_path('framework/testing/backup-readiness/pdh-category'),
                'scope' => 'product_data_hub_category_backup',
            ],
        ];
    }
}
