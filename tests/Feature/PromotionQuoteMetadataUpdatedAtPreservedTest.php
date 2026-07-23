<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteMetadataUpdatedAtPreservedTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_compact_metadata_keeps_updated_at_in_shared_builder(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee("if (includeUpdated && payload.last_synced_at) {", false);
        $response->assertSee('bits.push(`Güncellendi: ${formatLiveInfoTimestamp(payload.last_synced_at)}`);', false);
    }
}
