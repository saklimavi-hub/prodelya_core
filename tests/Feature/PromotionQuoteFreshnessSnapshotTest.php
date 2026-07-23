<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteFreshnessSnapshotTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_store_allows_stale_stock_and_persists_freshness_summary(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('stale-stock-save', [
            'standard_variant_stock' => 6100,
            'variant_total_stock' => 6500,
            'variant_supplier_stock' => 6500,
            'standard_variant_price' => 164.12,
            'variant_display_price' => 164.12,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($fixture));

        $response->assertRedirect();
        $item = OrderItem::query()->latest('id')->firstOrFail();

        $this->assertSame('stale_stock', data_get($item->price_snapshot, 'freshness_summary.status'));
        $this->assertFalse((bool) data_get($item->price_snapshot, 'freshness_summary.stale_price'));
        $this->assertTrue((bool) data_get($item->price_snapshot, 'freshness_summary.stale_stock'));
        $this->assertSame('stale_stock', data_get($item->price_snapshot, 'quote_price_snapshot.freshness.status'));
    }
}
