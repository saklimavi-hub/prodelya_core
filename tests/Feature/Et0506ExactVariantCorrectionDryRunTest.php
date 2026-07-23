<?php

namespace Tests\Feature;

use App\Models\TenantLocalStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionDryRunTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_dry_run_reports_plan_without_writes(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();

        $beforeCount = TenantLocalStock::query()->count();

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--dry-run' => true]
        ))
            ->expectsOutputToContain('Status: dry_run')
            ->expectsOutputToContain('Writes: 0')
            ->expectsOutputToContain('before operational = 2000.0000')
            ->expectsOutputToContain('after operational exact = 2000.0000')
            ->assertSuccessful();

        $this->assertSame($beforeCount, TenantLocalStock::query()->count());
        $legacy = $legacy->fresh();
        $this->assertSame(2000.0, (float) $legacy->quantity_on_hand);
        $this->assertSame(0, TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $blue->id)->count());
        $this->assertSame(0, TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $red->id)->count());
    }
}
