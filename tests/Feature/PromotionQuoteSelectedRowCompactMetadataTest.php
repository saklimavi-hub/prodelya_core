<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedRowCompactMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_selected_row_and_search_result_use_shared_compact_metadata_builder(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('const metaLine = buildCompactProductMetaLine(item, payload);', false);
        $response->assertSee('const metaLine = buildCompactProductMetaLine(entry, entry, { includePrice: true });', false);
        $response->assertDontSee("localStockPresentation.note || ''", false);
        $response->assertDontSee('Katalog stok bilgisi', false);
        $response->assertDontSee('Siparişe dönüşümde yerel stok yeniden kontrol edilir.', false);
    }
}
