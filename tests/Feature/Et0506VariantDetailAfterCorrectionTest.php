<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEt0506CorrectionFixtures;
use Tests\TestCase;

class Et0506VariantDetailAfterCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEt0506CorrectionFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_exact_variant_detail_uses_corrected_local_stock_truth(): void
    {
        ['product' => $product, 'blue' => $blue, 'red' => $red, 'legacy' => $legacy] = $this->createEt0506LegacyFixture();

        $this->artisan('prodelya:repair-local-stock-variants', array_merge(
            $this->correctionCommandPayload($product, $blue, $red, $legacy),
            ['--apply' => true]
        ))->assertSuccessful();

        $blueResponse = $this->getOnCentralHost(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $blue->id]));
        $redResponse = $this->getOnCentralHost(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $red->id]));

        $blueResponse->assertOk();
        $blueResponse->assertSeeText('ET-0506-MV Plastik Kalem Mavi');
        $blueResponse->assertSeeText('1.000');
        $blueResponse->assertDontSeeText('2.000');

        $redResponse->assertOk();
        $redResponse->assertSeeText('ET-0506-K Plastik Kalem Kırmızı');
        $redResponse->assertSeeText('1.000');
        $redResponse->assertDontSeeText('2.000');
    }
}
