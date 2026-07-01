<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use App\Services\System\SystemHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemHeartbeatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_touch_success_and_failure_update_heartbeat_state(): void
    {
        $service = app(SystemHeartbeatService::class);

        $service->touch('scheduler', ['label' => 'Zamanlayıcı', 'source' => 'test']);
        $service->success('scheduler', ['label' => 'Zamanlayıcı', 'source' => 'test']);
        $service->failure('scheduler', new \RuntimeException('temporary token password'), ['payload' => 'hidden']);

        $heartbeat = SystemHeartbeat::query()->where('key', 'scheduler')->firstOrFail();

        $this->assertSame(SystemHeartbeat::STATUS_FAILURE, $heartbeat->status);
        $this->assertSame('Zamanlayıcı', $heartbeat->label);
        $this->assertNotNull($heartbeat->last_seen_at);
        $this->assertNotNull($heartbeat->last_success_at);
        $this->assertNotNull($heartbeat->last_failure_at);
        $this->assertSame(1, $heartbeat->failure_count);
    }

    public function test_meta_is_sanitized_and_exception_trace_is_not_stored(): void
    {
        $service = app(SystemHeartbeatService::class);

        $service->failure('queue_worker', new \RuntimeException('token secret password stack trace'), [
            'label' => 'Kuyruk Çalışanı',
            'smtp_password' => 'hidden',
            'api_key' => 'hidden',
            'payload' => ['token' => 'abc'],
            'trace' => 'raw trace',
            'safe_note' => '<script>alert(1)</script> temiz',
        ]);

        $heartbeat = SystemHeartbeat::query()->where('key', 'queue_worker')->firstOrFail();
        $json = strtolower((string) json_encode($heartbeat->meta_json, JSON_UNESCAPED_UNICODE));

        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertStringNotContainsString('trace', $json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('<script>', $json);
        $this->assertStringContainsString('hassas detay gizlendi', $json);
    }

    public function test_status_for_returns_unknown_warning_and_healthy_states(): void
    {
        $service = app(SystemHeartbeatService::class);

        $unknown = $service->statusFor('scheduler', 10, 20);
        $this->assertSame('unknown', $unknown['status']);

        SystemHeartbeat::query()->create([
            'key' => 'scheduler',
            'label' => 'Zamanlayıcı',
            'status' => SystemHeartbeat::STATUS_SUCCESS,
            'last_seen_at' => now()->subMinutes(15),
            'last_success_at' => now()->subMinutes(15),
        ]);

        $warning = $service->statusFor('scheduler', 10, 20);
        $this->assertSame('warning', $warning['status']);

        SystemHeartbeat::query()->where('key', 'scheduler')->update([
            'last_seen_at' => now()->subMinutes(2),
            'last_success_at' => now()->subMinutes(2),
        ]);

        $healthy = $service->statusFor('scheduler', 10, 20);
        $this->assertSame('healthy', $healthy['status']);
    }

    public function test_missing_heartbeat_table_returns_unknown_without_throwing(): void
    {
        Schema::drop('system_heartbeats');

        $service = app(SystemHeartbeatService::class);

        $service->touch('scheduler', ['label' => 'Zamanlayıcı']);
        $service->success('scheduler', ['label' => 'Zamanlayıcı']);
        $service->failure('scheduler', new \RuntimeException('hidden token'));

        $status = $service->statusFor('scheduler', 10, 20);

        $this->assertSame('unknown', $status['status']);
        $this->assertNull($service->get('scheduler'));
    }
}
