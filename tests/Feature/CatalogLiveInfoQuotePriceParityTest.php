<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class CatalogLiveInfoQuotePriceParityTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_catalog_search_and_live_info_share_same_quote_price_payload_for_try_exact_variant(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('parity-try', [
            'product_code' => 'AK-1020',
            'variant_code' => 'AK-1020-KIRMIZI',
            'product_currency' => 'TRY',
            'variant_currency' => 'TRY',
            'product_display_price' => 30.50,
            'variant_display_price' => 30.50,
            'standard_product_price' => 30.50,
            'standard_variant_price' => 30.50,
            'source_price' => 30.50,
            'base_price' => 30.50,
            'applied_rate' => 1.0,
        ]);

        $search = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/catalog/search?q=AK-1020-KIRMIZI&currency=TRY'));
        $search->assertOk();
        $row = collect($search->json())->firstWhere('product_code', 'AK-1020-KIRMIZI');

        $live = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&currency=TRY'));
        $live->assertOk();

        $this->assertSame(data_get($row, 'quote_price_status'), data_get($live->json(), 'quote_price_status'));
        $this->assertSame((float) data_get($row, 'quote_price_value'), (float) data_get($live->json(), 'quote_price_value'));
        $this->assertSame(data_get($row, 'quote_currency'), data_get($live->json(), 'quote_currency'));
        $this->assertSame(
            data_get($row, 'quote_price_snapshot.suggested_sales_unit_price_document'),
            data_get($live->json(), 'quote_price_snapshot.suggested_sales_unit_price_document')
        );
    }
}
