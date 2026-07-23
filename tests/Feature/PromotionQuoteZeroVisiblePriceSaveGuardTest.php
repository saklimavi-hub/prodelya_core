<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteZeroVisiblePriceSaveGuardTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_store_rejects_zero_visible_price_when_canonical_catalog_price_is_positive(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('zero-visible-guard', [
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

        $payload = $this->buildQuoteStorePayload($fixture, [
            'list_price' => '0.00',
            'unit_price' => '0.00',
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'items.0.price_snapshot' => 'Ürün fiyatı ekranda doğrulanamadı. Ürünü yenileyip tekrar deneyin.',
        ]);
        $this->assertNull(OrderItem::query()->first());
    }
}
