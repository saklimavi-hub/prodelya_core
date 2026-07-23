<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCatalogProjectionDisplaysAsLocalStockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_catalog_projection_falls_back_to_local_stock_display_without_technical_label(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('const projectionValue = finiteNumber(localStockPresentation.projectionValue, visibleStock);', false);
        $response->assertSee('if (projectionValue > 0) {', false);
        $response->assertSee('return projectionValue;', false);
        $response->assertDontSee("localStockPresentation?.label === 'Yerel stok doğrulanamadı'", false);
    }
}
