<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteMetadataHydrationParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_create_and_edit_workspace_keep_shared_local_stock_metadata_builder(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $createResponse = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $createResponse->assertOk();
        $createResponse->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $createResponse->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $createResponse->assertSee('parts.push(`Local stok: ${formatLiveInfoStock(localDisplay)}`);', false);
        $createResponse->assertDontSee('Katalog stok:', false);
        $createResponse->assertDontSee('Yerel stok doğrulanamadı', false);
    }
}
