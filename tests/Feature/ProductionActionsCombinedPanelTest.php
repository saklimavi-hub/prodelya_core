<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionActionsCombinedPanelTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_actions_tab_groups_status_and_assignment_under_operation_panel(): void
    {
        $production = $this->createExternalProductionForShow();

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=islemler');

        $response->assertOk();
        $response->assertSee('Operasyon Yönetimi');
        $response->assertSee('Durum Güncelleme');
        $response->assertSee('Atama / Sorumluluk');
        $response->assertSee('Fason Maliyeti');
    }
}
