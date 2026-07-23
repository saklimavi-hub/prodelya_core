<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedProductCompactMetaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_workspace_selected_product_uses_compact_metadata_line_without_price_duplication(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('function buildCompactProductMetaBits(item = {}, payload = {}, options = {}) {', false);
        $response->assertSee('function buildCompactProductMetaLine(item = {}, payload = {}, options = {}) {', false);
        $response->assertSee('class="pd-product-live-info__meta-line"', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertDontSee('Satış liste:', false);
        $response->assertDontSee('TL karşılığı:', false);
        $response->assertDontSee('Kur:', false);
        $response->assertSee('Güncel fiyat:', false);
        $response->assertSee('Güncellendi:', false);
    }
}
