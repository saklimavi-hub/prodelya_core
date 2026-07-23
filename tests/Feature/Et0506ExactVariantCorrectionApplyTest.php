<?php

namespace Tests\Feature;

use App\Models\TenantLocalStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionApplyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_apply_creates_two_exact_rows_and_neutralizes_legacy_row(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--apply' => true]
        ))
            ->expectsOutputToContain('Status: applied')
            ->assertSuccessful();

        $legacy = $legacy->fresh();
        $blueRow = TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $blue->id)->sole();
        $redRow = TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $red->id)->sole();

        $this->assertSame(0.0, (float) $legacy->quantity_on_hand);
        $this->assertSame(0.0, (float) $legacy->quantity_reserved);
        $this->assertSame(0.0, (float) $legacy->quantity_available);
        $this->assertSame('resolved_exact_variant', $legacy->legacy_assignment_status);
        $this->assertSame(1000.0, (float) $blueRow->quantity_on_hand);
        $this->assertSame(1000.0, (float) $redRow->quantity_on_hand);
        $this->assertSame(2000.0, (float) TenantLocalStock::query()->whereIn('id', [$blueRow->id, $redRow->id])->sum('quantity_on_hand'));
    }
}
