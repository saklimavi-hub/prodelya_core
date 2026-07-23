<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteExactVariantSnapshotAttributionTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_store_persists_exact_usd_variant_snapshot_attribution(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('usd-save', [
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
            'source_price' => 3.5,
            'base_price' => 164.12,
            'applied_rate' => 46.8914,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($fixture));

        $response->assertRedirect();
        $item = OrderItem::query()->latest('id')->firstOrFail();

        $this->assertSame($fixture['product']->id, (int) $item->tenant_catalog_product_id);
        $this->assertSame($fixture['variant']->id, (int) $item->tenant_catalog_product_variant_id);
        $this->assertSame('USD', data_get($item->price_snapshot, 'quote_price_snapshot.source_currency'));
        $this->assertSame(3.5, (float) data_get($item->price_snapshot, 'quote_price_snapshot.source_price'));
        $this->assertSame(46.8914, round((float) data_get($item->price_snapshot, 'quote_price_snapshot.applied_rate'), 4));
    }
}
