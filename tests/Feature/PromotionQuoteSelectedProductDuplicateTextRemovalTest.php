<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedProductDuplicateTextRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_workspace_removes_duplicate_selected_product_title_and_sku_markup_but_keeps_form_fields(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('pd-quote-line-title', false);
        $response->assertDontSee('pd-quote-subtle-bit', false);
        $response->assertDontSee('alt="Ürün görseli"', false);
        $response->assertSee('catalog-search-input', false);
        $response->assertSee('name="items[${item._index}][product_name]"', false);
        $response->assertSee('name="items[${item._index}][product_code]"', false);
        $response->assertSee('data-live-product-info-box', false);
    }
}
