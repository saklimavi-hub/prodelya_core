<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchedulerQueueLiveReadinessTest extends TestCase
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

    public function test_heartbeat_commands_update_scheduler_and_queue_worker_keys(): void
    {
        $this->artisan('prodelya:heartbeat-scheduler')
            ->expectsOutputToContain('Zamanlayıcı heartbeat sinyali güncellendi.')
            ->assertSuccessful();

        $this->artisan('prodelya:heartbeat-queue-worker')
            ->expectsOutputToContain('Kuyruk çalışanı heartbeat sinyali güncellendi.')
            ->assertSuccessful();

        $this->assertDatabaseHas('system_heartbeats', ['key' => 'scheduler', 'status' => 'success']);
        $this->assertDatabaseHas('system_heartbeats', ['key' => 'queue_worker', 'status' => 'success']);
    }

    public function test_system_health_uses_heartbeat_signals_and_keeps_failed_jobs_safe(): void
    {
        config(['queue.default' => 'database']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['token' => 'hidden']),
            'exception' => 'RuntimeException: raw trace',
            'failed_at' => now()->subHour(),
        ]);

        SystemHeartbeat::query()->create([
            'key' => 'scheduler',
            'label' => 'Zamanlayıcı',
            'status' => 'success',
            'last_seen_at' => now()->subMinutes(4),
            'last_success_at' => now()->subMinutes(4),
        ]);

        SystemHeartbeat::query()->create([
            'key' => 'queue_worker',
            'label' => 'Kuyruk Çalışanı',
            'status' => 'success',
            'last_seen_at' => now()->subMinutes(3),
            'last_success_at' => now()->subMinutes(3),
        ]);

        $context = app(\App\Services\SuperAdmin\SuperAdminSystemHealthService::class)->buildHealthContext();
        $json = strtolower((string) json_encode($context, JSON_UNESCAPED_UNICODE));

        $this->assertSame('healthy', $context['scheduler']['status']);
        $this->assertSame('warning', $context['queue_worker']['status']);
        $this->assertStringContainsString('Son scheduler heartbeat', implode(' | ', $context['scheduler']['details']));
        $this->assertStringContainsString('Son heartbeat', implode(' | ', $context['queue_worker']['details']));
        $this->assertStringContainsString('Retry işlemi dashboard üzerinden yapılmaz', implode(' | ', $context['failed_jobs']['details']));
        $this->assertStringNotContainsString('hidden', $json);
        $this->assertStringNotContainsString('raw trace', $json);
    }

    public function test_stale_or_missing_heartbeat_does_not_fake_healthy_dashboard_state(): void
    {
        SystemHeartbeat::query()->create([
            'key' => 'scheduler',
            'label' => 'Zamanlayıcı',
            'status' => 'success',
            'last_seen_at' => now()->subMinutes(25),
            'last_success_at' => now()->subMinutes(25),
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Sistem Sağlığı');
        $response->assertSee('Zamanlayıcı');
        $response->assertSee('Kontrol Gerekir');
    }

    public function test_product_data_hub_scheduled_command_updates_heartbeat_without_secret_leakage(): void
    {
        $this->artisan('product-data-hub:sync-sources', ['--frequency' => 'daily', '--dry-run' => true])
            ->assertSuccessful();

        $heartbeat = SystemHeartbeat::query()->where('key', 'product_data_hub_daily')->firstOrFail();
        $json = strtolower((string) json_encode($heartbeat->meta_json, JSON_UNESCAPED_UNICODE));

        $this->assertContains($heartbeat->status, ['success', 'failure']);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('secret', $json);
    }

    public function test_production_checklist_contains_scheduler_and_queue_notes(): void
    {
        $content = file_get_contents(base_path('docs/production-go-live-checklist.md'));

        $this->assertIsString($content);
        $this->assertStringContainsString('schedule:run', $content);
        $this->assertStringContainsString('queue:work --sleep=3 --tries=3 --timeout=120', $content);
        $this->assertStringContainsString('prodelya:heartbeat-scheduler', $content);
        $this->assertStringContainsString('prodelya:heartbeat-queue-worker', $content);
    }
}
