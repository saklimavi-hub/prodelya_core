<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteStalePriceSaveGuardTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_store_blocks_stale_price_with_exact_row_validation(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('stale-guard', [
            'standard_variant_price' => 182.45,
            'variant_display_price' => 164.12,
            'product_display_price' => 164.12,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($fixture));

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'items.0.tenant_catalog_product_id' => 'Ürün fiyatı güncel katalog yayınıyla eşleşmiyor. Ürünü yenileyin veya katalog güncellemesini tamamlayın.',
        ]);
        $this->assertNull(OrderItem::query()->first());
    }
}
