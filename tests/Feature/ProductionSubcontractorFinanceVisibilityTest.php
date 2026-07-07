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

    public function test_external_finance_block_respects_permission_visibility(): void
    {
        $production = $this->createExternalProductionForShow(null, [
            'subcontractor_cost' => 1250,
            'subcontractor_cost_currency' => 'TRY',
        ]);

        $financeResponse = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $financeResponse->assertOk();
        $financeResponse->assertSee('Fason Maliyeti');
        $financeResponse->assertSee('1.250,00');

        $limitedResponse = $this->actingAs($this->limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $limitedResponse->assertOk();
        $limitedResponse->assertSee('Fason maliyeti ve cari işlemleri yalnız yetkili kullanıcıya gösterilir.');
        $limitedResponse->assertDontSee('1.250,00');
    }
}

