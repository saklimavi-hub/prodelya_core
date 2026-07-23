<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class ProductHubLiveInfoFreshnessPayloadTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_live_info_returns_freshness_payload_for_try_flat_and_exact_and_usd_exact(): void
    {
        $try = $this->createQuoteFreshnessFixture('live-try', [
            'standard_product_code' => 'QF-LIVE-TRY-001',
            'product_code' => 'EL-KOD-35',
            'variant_code' => 'EL-KOD-35-V1',
            'product_currency' => 'TRY',
            'variant_currency' => 'TRY',
            'product_display_price' => 134.0,
            'variant_display_price' => 134.0,
            'standard_product_price' => 134.0,
            'standard_variant_price' => 134.0,
            'product_total_stock' => 500,
            'variant_total_stock' => 275,
            'variant_supplier_stock' => 275,
            'standard_product_stock' => 500,
            'standard_variant_stock' => 275,
            'standard_updated_at' => now()->subMinute(),
            'projection_updated_at' => now()->addMinute(),
        ]);

        $flat = $this->actingAs($try['user'], 'web')
            ->getJson($this->tenantUrl($try['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_id=' . $try['product']->id));
        $flat->assertOk()->assertJson(['freshness' => ['status' => 'fresh', 'stale_price' => false, 'stale_stock' => false, 'blocking' => false]]);

        $exact = $this->actingAs($try['user'], 'web')
            ->getJson($this->tenantUrl($try['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $try['variant']->id));
        $exact->assertOk()->assertJson(['freshness' => ['status' => 'fresh', 'stale_price' => false, 'stale_stock' => false, 'blocking' => false]]);

        $usd = $this->createQuoteFreshnessFixture('live-usd', [
            'standard_product_code' => 'QF-LIVE-USD-001',
            'product_code' => 'PZ-CH60',
            'variant_code' => 'PZ-CH60SY',
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'product_display_price' => 164.12,
            'variant_display_price' => 164.12,
            'standard_product_price' => 164.12,
            'standard_variant_price' => 164.12,
            'source_price' => 3.5,
            'base_price' => 164.12,
            'applied_rate' => 46.8914,
            'standard_updated_at' => now()->subMinute(),
            'projection_updated_at' => now()->addMinute(),
        ]);

        $usdResponse = $this->actingAs($usd['user'], 'web')
            ->getJson($this->tenantUrl($usd['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $usd['variant']->id . '&currency=TRY'));

        $usdResponse->assertOk();
        $this->assertSame('USD', data_get($usdResponse->json(), 'quote_price_snapshot.source_currency'));
        $this->assertSame('fresh', data_get($usdResponse->json(), 'freshness.status'));
    }
}
