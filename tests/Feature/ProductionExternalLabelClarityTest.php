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

    public function test_legacy_external_tab_redirects_to_single_canonical_fason_surface(): void
    {
        $production = $this->createExternalProductionForShow();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim')
            ->assertRedirect(route('admin.productions.subcontract-tracking', $production));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $production));

        $response->assertOk();
        $response->assertSee('Fason Takibi');
        $response->assertDontSee('<h2 class="prd-section-title">Dış Üretim</h2>', false);
    }
}
