<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedRowNoDuplicateStockLabelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_selected_row_does_not_render_duplicate_stock_labels_or_blank_meta_chips(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('Katalog stok bilgisi', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertSee('class="pd-product-live-info__meta-line"', false);
    }
}
