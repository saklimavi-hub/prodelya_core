<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteNotRequiredPriceHydrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quote_workspace_contains_not_required_and_unavailable_hydration_guards(): void
    {
        $response = $this->actingAs(User::query()->where('email', 'admin@prodelya.local')->firstOrFail(), 'web')
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee("['converted', 'stale_rate', 'ready', 'not_required']", false);
        $response->assertSee('function quotePriceUnavailableMessage(payload = {}) {', false);
        $response->assertSee("payload.quote_price_reason_code === 'canonical_quote_price_unavailable'", false);
        $response->assertSee('const hasSafeFallbackPrice = Number.isFinite(operationalListPrice) && operationalListPrice > 0;', false);
        $response->assertDontSee("target.line_total = '0.00';", false);
    }
}
