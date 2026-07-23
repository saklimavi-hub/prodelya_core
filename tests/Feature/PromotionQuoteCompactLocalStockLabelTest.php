<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCompactLocalStockLabelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_compact_metadata_uses_local_stock_label_in_shared_builder(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('function resolveCompactLocalStockDisplay(stockTruth = {}, localStockPresentation = {}) {', false);
        $response->assertSee('parts.push(`Local stok: ${formatLiveInfoStock(localDisplay)}`);', false);
        $response->assertDontSee('parts.push(`Yerel stok: ${formatLiveInfoStock(localValue)}`);', false);
        $response->assertDontSee('parts.push(`Katalog stok: ${formatLiveInfoStock(localValue || visibleStock)}`);', false);
    }
}
