<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteExactLocalStockBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_create_workspace_uses_exact_local_and_supplier_stock_fields_for_badge_resolution(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('function resolveLocalStockPresentation(item = {}, payload = {}) {', false);
        $response->assertSee('function resolveStockSourceLabel(localStockPresentation = {}, localStock = 0, supplierStock = 0) {', false);
        $response->assertSee('function resolveCompactStockSummary(stockTruth = {}, localStockPresentation = {}) {', false);
        $response->assertDontSee('resolveCompactStockMetric(stockTruth, localStockPresentation)', false);
        $response->assertDontSee('resolveStockSourceLabel(localStockPresentation, stockTruth.local, stockTruth.supplier)', false);
        $response->assertSee('local_stock_quantity', false);
        $response->assertSee('local_stock_source', false);
        $response->assertSee('local_stock_label', false);
        $response->assertSee('local_stock_note', false);
        $response->assertSee('supplier_stock_quantity', false);
        $response->assertDontSee('group_code', false);
    }
}
