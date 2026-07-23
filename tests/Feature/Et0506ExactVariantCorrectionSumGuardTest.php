<?php

namespace Tests\Feature;

use App\Models\TenantLocalStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionSumGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_sum_mismatch_blocks_correction_without_writes(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();
        $beforeCount = TenantLocalStock::query()->count();

        $this->artisan('prodelya:repair-local-stock-variants', [
            '--tenant' => $product->tenant_account_id,
            '--product' => $product->id,
            '--legacy-stock' => $legacy->id,
            '--map' => [$blue->id . ':900', $red->id . ':1000'],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Status: blocked')
            ->expectsOutputToContain('Map toplam quantity legacy on_hand ile ayni olmali.')
            ->assertFailed();

        $this->assertSame($beforeCount, TenantLocalStock::query()->count());
        $this->assertSame(2000.0, (float) $legacy->fresh()->quantity_on_hand);
    }
}
