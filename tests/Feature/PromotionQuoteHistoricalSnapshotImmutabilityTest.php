<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteHistoricalSnapshotImmutabilityTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_saved_price_snapshot_stays_immutable_after_catalog_price_changes(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('immutability', [
            'product_code' => 'EL-KOD-35',
            'variant_code' => 'EL-KOD-35-V1',
            'product_currency' => 'TRY',
            'variant_currency' => 'TRY',
            'product_display_price' => 134.0,
            'variant_display_price' => 134.0,
            'standard_product_price' => 134.0,
            'standard_variant_price' => 134.0,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($fixture));

        $response->assertRedirect();
        $item = OrderItem::query()->latest('id')->firstOrFail();
        $original = $item->price_snapshot;

        $fixture['variant']->forceFill(['display_price' => 199.0])->save();
        $fixture['product']->forceFill(['display_price' => 199.0])->save();
        $fixture['standardVariant']->forceFill(['min_purchase_price' => 199.0, 'max_purchase_price' => 199.0])->save();

        $item->refresh();

        $this->assertSame($original, $item->price_snapshot);
        $this->assertSame(134.0, (float) data_get($item->price_snapshot, 'quote_price_snapshot.source_price'));
    }
}
