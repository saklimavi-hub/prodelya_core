<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSupplierStockRemainsSeparateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_supplier_stock_remains_separate_from_local_stock_in_compact_builder(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('parts.push(`Tedarikçi stok: ${formatLiveInfoStock(supplierStock)}`);', false);
        $response->assertDontSee('Yerel: ${formatLiveInfoStock(localStock)} · Tedarikçi: ${formatLiveInfoStock(supplierStock)}', false);
    }
}
