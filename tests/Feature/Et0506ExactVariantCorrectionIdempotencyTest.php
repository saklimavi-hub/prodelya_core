<?php

namespace Tests\Feature;

use App\Models\TenantLocalStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506ExactVariantCorrectionIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_second_apply_is_no_op_and_does_not_duplicate_rows(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();
        $payload = $this->correctionCommandPayload($product, $blue, $red, $legacy);

        $this->artisan('prodelya:repair-local-stock-variants', array_merge($payload, ['--apply' => true]))->assertSuccessful();

        $this->artisan('prodelya:repair-local-stock-variants', array_merge($payload, ['--apply' => true]))
            ->expectsOutputToContain('Status: already_corrected')
            ->expectsOutputToContain('Writes: 0')
            ->assertSuccessful();

        $this->assertSame(1, TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $blue->id)->count());
        $this->assertSame(1, TenantLocalStock::query()->where('tenant_catalog_product_variant_id', $red->id)->count());
    }
}
