<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteProductSearchCurrencyDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_create_workspace_contains_compact_dropdown_currency_renderer_contract(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('function buildCatalogResult(entry) {', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $response->assertSee('Güncel fiyat:', false);
        $response->assertDontSee('Güncel kur:', false);
        $response->assertSee('source_to_base_rate', false);
        $response->assertSee('source_currency', false);
        $response->assertDontSee('const price = entry.list_price ?? 0;', false);
    }
}
