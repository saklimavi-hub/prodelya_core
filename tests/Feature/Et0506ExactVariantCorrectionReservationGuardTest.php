<?php

namespace Tests\Feature;

use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionReservationGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_active_legacy_reservation_blocks_correction(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();
        $this->createActiveLegacyReservation($legacy);

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--apply' => true]
        ))
            ->expectsOutputToContain('Status: blocked')
            ->expectsOutputToContain('Legacy row aktif reservation icermemeli.')
            ->assertFailed();

        $this->assertSame(1, TenantStockReservation::query()->where('tenant_local_stock_id', $legacy->id)->where('status', TenantStockReservation::STATUS_ACTIVE)->count());
        $this->assertSame(2000.0, (float) TenantLocalStock::query()->findOrFail($legacy->id)->quantity_on_hand);
    }
}
