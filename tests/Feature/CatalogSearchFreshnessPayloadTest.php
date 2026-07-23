<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class CatalogSearchFreshnessPayloadTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_catalog_search_and_live_info_share_same_usd_exact_freshness_dto(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('usd-exact', [
            'product_code' => 'PZ-CH60',
            'variant_code' => 'PZ-CH60SY',
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'product_display_price' => 164.12,
            'variant_display_price' => 164.12,
            'standard_product_price' => 164.12,
            'standard_variant_price' => 164.12,
            'product_total_stock' => 6500,
            'variant_total_stock' => 6500,
            'variant_supplier_stock' => 6500,
            'standard_product_stock' => 6500,
            'standard_variant_stock' => 6500,
            'standard_updated_at' => now()->addSeconds(8),
            'projection_updated_at' => now(),
            'source_price' => 3.5,
            'base_price' => 164.12,
            'applied_rate' => 46.8914,
        ]);

        $search = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/catalog/search?q=PZ-CH60SY&currency=TRY'));

        $search->assertOk();
        $row = collect($search->json())->firstWhere('product_code', 'PZ-CH60SY');
        $this->assertNotNull($row);

        $live = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&currency=TRY'));

        $live->assertOk();
        $this->assertSame(data_get($row, 'freshness.status'), data_get($live->json(), 'freshness.status'));
        $this->assertSame(data_get($row, 'freshness.stale_price'), data_get($live->json(), 'freshness.stale_price'));
        $this->assertSame(data_get($row, 'freshness.stale_stock'), data_get($live->json(), 'freshness.stale_stock'));
        $this->assertSame(data_get($row, 'freshness.projection_outdated'), data_get($live->json(), 'freshness.projection_outdated'));
        $this->assertSame('USD', data_get($row, 'quote_price_snapshot.source_currency'));
        $this->assertSame('USD', data_get($live->json(), 'quote_price_snapshot.source_currency'));
    }
}
