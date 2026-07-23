<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteItemSubmissionContractTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_store_request_accepts_exact_variant_item_payload_and_creates_quote_item(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('submit-contract', [
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'product_display_price' => 164.12,
            'variant_display_price' => 164.12,
            'standard_product_price' => 164.12,
            'standard_variant_price' => 164.12,
            'source_price' => 3.5,
            'base_price' => 164.12,
            'applied_rate' => 46.8914,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($fixture, [
                'quantity' => '1',
                'line_total' => '164.12',
            ]));

        $response->assertSessionDoesntHaveErrors(['items']);

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = OrderItem::query()->where('order_id', $quote->id)->latest('id')->firstOrFail();

        $this->assertSame($fixture['product']->id, $item->tenant_catalog_product_id);
        $this->assertSame($fixture['variant']->id, $item->tenant_catalog_product_variant_id);
        $this->assertSame($fixture['product']->standard_product_id, $item->standard_product_id);
        $this->assertSame($fixture['variant']->standard_product_variant_id, $item->standard_product_variant_id);
        $this->assertSame('PZ-CH60SY', $item->product_code);
        $this->assertSame('USD', data_get($item->price_snapshot, 'quote_price_snapshot.source_currency'));
        $this->assertSame(3.5, (float) data_get($item->price_snapshot, 'quote_price_snapshot.source_price'));
    }
}
