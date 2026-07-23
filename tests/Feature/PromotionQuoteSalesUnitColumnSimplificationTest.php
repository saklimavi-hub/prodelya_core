<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSalesUnitColumnSimplificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_create_workspace_hides_visible_calculated_column_but_keeps_internal_snapshot_field(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('Satış Birim Fiyatı');
        $response->assertDontSee('Hesaplanan Birim');
        $response->assertDontSee('Nihai Satış Birim');
        $response->assertSee('data-calculated-unit-price', false);
        $response->assertSee('manual_unit_price', false);
    }
}
