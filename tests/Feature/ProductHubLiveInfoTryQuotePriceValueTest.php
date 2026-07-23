<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class ProductHubLiveInfoTryQuotePriceValueTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_live_info_returns_non_null_quote_price_value_for_try_exact_variant(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('live-try-exact', [
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

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&currency=TRY'));

        $response->assertOk()
            ->assertJson([
                'quote_price_status' => 'not_required',
                'quote_currency' => 'TRY',
                'quote_price_reason_code' => null,
            ]);

        $this->assertSame(30.5, (float) data_get($response->json(), 'quote_price_value'));
        $this->assertSame(30.5, (float) data_get($response->json(), 'quote_price_snapshot.base_price'));
        $this->assertSame('identity', data_get($response->json(), 'quote_price_snapshot.rate_source'));
    }
}
