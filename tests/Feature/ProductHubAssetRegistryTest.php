<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\ProductHubAsset;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Services\ProductDataHub\ProductHubAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubAssetRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pending_asset_can_be_registered(): void
    {
        [$tenant, $source, $run] = $this->makeContext();

        $asset = app(ProductHubAssetService::class)->registerPending('raw_feed_snapshot', 'pdh/private/source.json', [
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $source->supplier_id,
            'source_id' => $source->id,
            'sync_run_id' => $run->id,
        ]);

        $this->assertSame(ProductHubAsset::STATUS_PENDING, $asset->status);
        $this->assertSame($source->id, $asset->source_id);
        $this->assertSame($run->id, $asset->sync_run_id);
    }

    public function test_stored_asset_can_be_marked_and_urls_are_safe(): void
    {
        [$tenant, $source, $run] = $this->makeContext();

        $asset = app(ProductHubAssetService::class)->registerPending('product_image_thumb', 'pdh/public/thumb.webp', [
            'tenant_account_id' => $tenant->id,
            'source_id' => $source->id,
            'sync_run_id' => $run->id,
        ]);

        $stored = app(ProductHubAssetService::class)->markStored($asset, [
            'checksum_sha256' => 'abc123',
            'size_bytes' => 2048,
            'public_url' => 'https://cdn.example.test/thumb.webp?token=SECRET',
        ]);

        $this->assertSame(ProductHubAsset::STATUS_STORED, $stored->status);
        $this->assertSame('abc123', $stored->checksum_sha256);
        $this->assertStringContainsString('token=%2A%2A%2A', (string) $stored->public_url);
    }

    public function test_failed_asset_can_be_marked_with_safe_reason(): void
    {
        [$tenant, $source, $run] = $this->makeContext();

        $asset = app(ProductHubAssetService::class)->registerPending('tenant_export', 'pdh/private/export.xml', [
            'tenant_account_id' => $tenant->id,
            'source_id' => $source->id,
            'sync_run_id' => $run->id,
        ]);

        $failed = app(ProductHubAssetService::class)->markFailed($asset, 'token=SECRET export failed');

        $this->assertSame(ProductHubAsset::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('***', (string) $failed->failed_reason);
        $this->assertStringNotContainsString('SECRET', (string) $failed->failed_reason);
    }

    public function test_checksum_lookup_and_tenant_binding_work(): void
    {
        [$tenant, $source, $run] = $this->makeContext();

        $asset = app(ProductHubAssetService::class)->registerPending('tenant_export', 'pdh/private/export.xml', [
            'tenant_account_id' => $tenant->id,
            'source_id' => $source->id,
            'sync_run_id' => $run->id,
            'checksum_sha256' => 'dup-checksum',
        ]);

        $found = app(ProductHubAssetService::class)->findByChecksum('dup-checksum');

        $this->assertNotNull($found);
        $this->assertSame($tenant->id, $found->tenant_account_id);
        $this->assertSame($asset->id, $found->id);
    }

    public function test_customer_facing_payload_does_not_need_physical_path(): void
    {
        $asset = app(ProductHubAssetService::class)->registerPending('product_image_catalog', 'pdh/public/catalog.webp');

        $payload = $asset->toArray();

        $this->assertArrayNotHasKey('physical_path', $payload);
    }

    private function makeContext(): array
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $supplier = Supplier::query()->create([
            'name' => 'Asset Supplier',
            'code' => 'AST-' . uniqid(),
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Asset Source',
            'url' => 'https://example.com/feed.xml',
            'config' => ['format' => 'xml'],
            'status' => 'active',
        ]);
        $run = ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $source->id,
            'supplier_id' => $supplier->id,
            'run_type' => 'manual',
            'started_at' => now(),
            'status' => ProductDataHubSyncRun::STATUS_RUNNING,
        ]);

        return [$tenant, $source, $run];
    }
}
