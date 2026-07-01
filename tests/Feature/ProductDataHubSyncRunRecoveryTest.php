<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\Supplier;
use App\Models\SupplierSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSyncRunRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_running_run_is_reported_in_dry_run_without_db_changes(): void
    {
        $source = $this->makeSource();
        $run = $this->makeRun($source, ProductDataHubSyncRun::STATUS_RUNNING, now()->subMinutes(145), now()->subMinutes(145));

        $this->artisan('product-data-hub:recover-runs', ['--dry-run' => true, '--minutes' => 60])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('1 stuck run bulundu')
            ->expectsOutputToContain('Run ID: ' . $run->id)
            ->expectsOutputToContain('Önerilen işlem: stuck olarak işaretle')
            ->assertExitCode(0);

        $this->assertSame(ProductDataHubSyncRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_old_running_run_is_marked_stuck_when_not_dry_run(): void
    {
        $source = $this->makeSource();
        $run = $this->makeRun($source, ProductDataHubSyncRun::STATUS_RUNNING, now()->subMinutes(145), now()->subMinutes(145), 'https://example.com/feed?token=SECRET');

        $this->artisan('product-data-hub:recover-runs', ['--minutes' => 60])
            ->expectsOutputToContain('1 run stuck olarak işaretlendi.')
            ->assertExitCode(0);

        $run = $run->fresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_STUCK, $run->status);
        $this->assertStringNotContainsString('SECRET', (string) $run->error_message);
        $this->assertStringContainsString('Recovery standardı uygulandı', (string) $run->error_message);
    }

    public function test_recent_running_run_is_not_touched(): void
    {
        $source = $this->makeSource();
        $run = $this->makeRun($source, ProductDataHubSyncRun::STATUS_RUNNING, now()->subMinutes(10), now()->subMinutes(10));

        $this->artisan('product-data-hub:recover-runs', ['--minutes' => 60])
            ->expectsOutputToContain('0 stuck run bulundu')
            ->assertExitCode(0);

        $this->assertSame(ProductDataHubSyncRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_terminal_runs_are_not_touched(): void
    {
        $source = $this->makeSource();
        $completed = $this->makeRun($source, ProductDataHubSyncRun::STATUS_COMPLETED, now()->subMinutes(145), now()->subMinutes(145));
        $failed = $this->makeRun($source, ProductDataHubSyncRun::STATUS_FAILED, now()->subMinutes(145), now()->subMinutes(145));

        $this->artisan('product-data-hub:recover-runs', ['--minutes' => 60])
            ->assertExitCode(0);

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(ProductDataHubSyncRun::STATUS_FAILED, $failed->fresh()->status);
    }

    public function test_recovery_command_does_not_trigger_sync_import_or_projection(): void
    {
        $source = $this->makeSource();
        $this->makeRun($source, ProductDataHubSyncRun::STATUS_RUNNING, now()->subMinutes(145), now()->subMinutes(145));

        $beforeRunCount = ProductDataHubSyncRun::query()->count();

        $this->artisan('product-data-hub:recover-runs', ['--minutes' => 60])
            ->assertExitCode(0);

        $this->assertSame($beforeRunCount, ProductDataHubSyncRun::query()->count());
        $this->assertDatabaseCount('supplier_products_raw', 0);
        $this->assertDatabaseCount('standard_products', 0);
        $this->assertDatabaseCount('tenant_catalog_products', 0);
    }

    private function makeSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Recovery Supplier',
            'code' => 'REC-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Recovery Source',
            'url' => 'https://example.com/recovery.xml',
            'config' => ['format' => 'xml'],
            'status' => 'active',
        ]);
    }

    private function makeRun(SupplierSource $source, string $status, $startedAt, $updatedAt, ?string $errorMessage = null): ProductDataHubSyncRun
    {
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'run_type' => 'manual',
            'started_at' => $startedAt,
            'finished_at' => $status === ProductDataHubSyncRun::STATUS_RUNNING ? null : $updatedAt,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        $run->forceFill(['updated_at' => $updatedAt])->saveQuietly();

        return $run->fresh();
    }
}
