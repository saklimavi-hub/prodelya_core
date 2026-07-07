<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionActionsPanelLayoutTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_actions_tab_groups_status_assignment_and_quick_actions(): void
    {
        $production = $this->createInternalProductionForShow();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=islemler');

        $response->assertOk();
        $response->assertSee('Durum Güncelleme');
        $response->assertSee('Atama / Sorumluluk');
        $response->assertSee('Diğer İşlemler');
        $response->assertSee('Not Ekle');
        $response->assertSee('Fotoğraf Yükle');
    }
}

