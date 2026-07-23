<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedRowNoRepeatedRecheckNoteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_selected_row_does_not_repeat_long_local_stock_recheck_note_per_item(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('Siparişe dönüşümde yerel stok yeniden kontrol edilir.', false);
        $response->assertDontSee("localStockPresentation.note || ''", false);
    }
}
