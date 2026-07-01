<?php

namespace Tests\Feature;

use App\Models\ProductHubAsset;
use App\Services\ProductDataHub\ProductHubAssetService;
use App\Services\ProductDataHub\ProductHubStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_visibility_rules_are_applied(): void
    {
        $service = app(ProductHubAssetService::class);

        $this->assertSame(ProductHubAsset::VISIBILITY_PRIVATE, $service->visibilityForAssetType('raw_feed_snapshot'));
        $this->assertSame(ProductHubAsset::VISIBILITY_PUBLIC, $service->visibilityForAssetType('product_image_thumb'));
        $this->assertSame(ProductHubAsset::VISIBILITY_SIGNED, $service->visibilityForAssetType('tenant_export'));
    }

    public function test_asset_labels_are_translated_for_ui(): void
    {
        $service = app(ProductHubAssetService::class);

        $this->assertSame('Ham Kaynak Dosyasi', $service->assetLabel('raw_feed_snapshot'));
        $this->assertSame('Urun Kucuk Gorseli', $service->assetLabel('product_image_thumb'));
    }

    public function test_register_pending_sanitizes_object_key_against_path_traversal(): void
    {
        $asset = app(ProductHubAssetService::class)->registerPending(
            'raw_feed_snapshot',
            'pdh/private/../secrets/../../run/source.json'
        );

        $this->assertStringNotContainsString('..', $asset->object_key);
        $this->assertStringContainsString('pdh/private', $asset->object_key);
    }

    public function test_mask_storage_path_for_ui_does_not_leak_physical_path(): void
    {
        $masked = app(ProductHubAssetService::class)->maskStoragePathForUi(
            'C:\laragon\www\prodelya_core\storage\app\pdh\private\suppliers\ETKIN\source.json'
        );

        $this->assertStringNotContainsString('C:\laragon\www\prodelya_core', (string) $masked);
        $this->assertStringContainsString('source.json', (string) $masked);
    }

    public function test_storage_service_generates_standardized_object_keys(): void
    {
        $key = app(ProductHubStorageService::class)->sanitizeObjectKey('pdh/public/products/images/../ETKIN/../AK/abc/original.webp');

        $this->assertStringNotContainsString('..', $key);
        $this->assertStringEndsWith('original.webp', $key);
    }
}
