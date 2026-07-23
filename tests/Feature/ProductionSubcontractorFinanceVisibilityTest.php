<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionSubcontractorFinanceVisibilityTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_external_canonical_tracking_never_renders_finance_block(): void
    {
        $production = $this->createExternalProductionForShow(null, [
            'subcontractor_cost' => 1250,
            'subcontractor_cost_currency' => 'TRY',
        ]);

        $legacyResponse = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');
        $legacyResponse->assertRedirect(route('admin.productions.subcontract-tracking', $production));

        $financeResponse = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $production));

        $financeResponse->assertOk();
        $financeResponse->assertDontSee('Fason Maliyeti');
        $financeResponse->assertDontSee('1.250,00');


        $limitedResponse = $this->actingAs($this->limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $production));

        $limitedResponse->assertOk();
        $limitedResponse->assertDontSee('Fason Maliyeti');
        $limitedResponse->assertDontSee('1.250,00');

    }
}
