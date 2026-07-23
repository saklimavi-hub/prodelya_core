<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteNoUnresolvedStockTextTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_workspace_hides_unresolved_and_catalog_stock_technical_text(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('Yerel stok doğrulanamadı', false);
        $response->assertDontSee('Katalog stok bilgisi', false);
        $response->assertDontSee('Katalog stok:', false);
        $response->assertDontSee('Siparişe dönüşümde yerel stok yeniden kontrol edilir.', false);
    }
}
