<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class CatalogSearchTryQuotePriceValueTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_try_exact_variant_returns_non_null_not_required_quote_price_value(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('try-exact', [
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
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/catalog/search?q=AK-1020-KIRMIZI&currency=TRY'));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('product_code', 'AK-1020-KIRMIZI');

        $this->assertNotNull($row);
        $this->assertSame('not_required', data_get($row, 'quote_price_status'));
        $this->assertSame(30.5, (float) data_get($row, 'quote_price_value'));
        $this->assertSame(30.5, (float) data_get($row, 'quote_price_snapshot.base_price'));
        $this->assertSame(30.5, (float) data_get($row, 'quote_price_snapshot.source_price'));
        $this->assertSame('TRY', data_get($row, 'quote_price_snapshot.source_currency'));
        $this->assertSame('identity', data_get($row, 'quote_price_snapshot.rate_source'));
    }

    public function test_try_flat_product_returns_non_null_not_required_quote_price_value(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('try-flat', [
            'product_code' => 'EL-KOD-35',
            'variant_code' => 'EL-KOD-35-MV',
            'product_currency' => 'TRY',
            'variant_currency' => 'TRY',
            'product_display_price' => 134.00,
            'variant_display_price' => 134.00,
            'standard_product_price' => 134.00,
            'standard_variant_price' => 134.00,
            'source_price' => 134.00,
            'base_price' => 134.00,
            'applied_rate' => 1.0,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/catalog/search?q=EL-KOD-35&currency=TRY'));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('product_code', 'EL-KOD-35-MV');

        $this->assertNotNull($row);
        $this->assertSame('not_required', data_get($row, 'quote_price_status'));
        $this->assertSame(134.0, (float) data_get($row, 'quote_price_value'));
    }
}
