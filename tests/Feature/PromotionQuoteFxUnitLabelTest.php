<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteFxUnitLabelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_workspace_uses_compact_fx_label_contract_and_hides_try_identity_rate(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('buildCompactProductMetaBits', false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $needle = <<<'JS'
Kur: ${formatSalesMetricAmount(sales.rate, 'TRY', 4)}
JS;
        $response->assertDontSee($needle, false);
        $response->assertDontSee('Kur: 1 ${String(sales.sourceCurrency).toUpperCase()} =', false);
        $response->assertDontSee('IDENTITY', false);
    }
}
