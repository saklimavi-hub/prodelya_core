<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionExternalLabelClarityTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_external_tab_uses_single_clear_dil_standard(): void
    {
        $production = $this->createExternalProductionForShow();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $response->assertOk();
        $response->assertSee('Dış Üretim / Fason');
        $response->assertDontSee('<h2 class="prd-section-title">Dış Üretim</h2>', false);
    }
}
