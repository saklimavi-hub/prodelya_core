<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCanonicalPriceFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_workspace_prefers_safe_canonical_fallback_over_silent_zeroing(): void
    {
        $response = $this->actingAs(User::query()->where('email', 'admin@prodelya.local')->firstOrFail(), 'web')
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('const operationalListPrice = Number(resolveOperationalListPriceValue(payload, target.price_snapshot) || 0);', false);
        $response->assertSee('const needsBlocking = (!isReadyQuotePriceStatus(quoteStatus) && !hasSafeFallbackPrice)', false);
        $response->assertSee("quote_price_reason_code === 'canonical_quote_price_unavailable'", false);
    }
}
