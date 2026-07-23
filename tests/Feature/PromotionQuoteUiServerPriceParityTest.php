<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteUiServerPriceParityTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_store_keeps_try_and_usd_snapshot_truth_without_ui_server_drift(): void
    {
        $tryFixture = $this->createQuoteFreshnessFixture('parity-try-save', [
            'standard_product_code' => 'QF-PAR-TRY',
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

        $tryResponse = $this->actingAs($tryFixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($tryFixture));

        $tryResponse->assertRedirect();
        $tryItem = OrderItem::query()->where('tenant_account_id', $tryFixture['tenant']->id)->latest('id')->firstOrFail();
        $this->assertSame(134.0, (float) data_get($tryItem->price_snapshot, 'quote_price_value'));
        $this->assertSame('not_required', data_get($tryItem->price_snapshot, 'quote_price_status'));

        $usdFixture = $this->createQuoteFreshnessFixture('parity-usd-save', [
            'standard_product_code' => 'QF-PAR-USD',
            'product_code' => 'PZ-CH60',
            'variant_code' => 'PZ-CH60SY',
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

        $usdResponse = $this->actingAs($usdFixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($usdFixture));

        $usdResponse->assertRedirect();
        $usdItem = OrderItem::query()->where('tenant_account_id', $usdFixture['tenant']->id)->latest('id')->firstOrFail();
        $this->assertSame(164.12, (float) data_get($usdItem->price_snapshot, 'quote_price_value'));
        $this->assertSame('ready', data_get($usdItem->price_snapshot, 'quote_price_status'));
        $this->assertSame('USD', data_get($usdItem->price_snapshot, 'quote_price_snapshot.source_currency'));
    }
}
