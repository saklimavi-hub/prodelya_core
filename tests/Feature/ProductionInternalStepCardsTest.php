<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionInternalStepCardsTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_internal_tab_shows_operator_focused_step_cards(): void
    {
        $production = $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_INTERNAL,
            'completed_quantity' => 20,
            'remaining_quantity' => 80,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=ic-uretim');

        $response->assertOk();
        $response->assertSee('Üretim Akış Adımları');
        $response->assertSee('Üretime Başla');
        $response->assertSee('Kısmi Üretildi');
        $response->assertSee('Tamamlandı');
        $response->assertSee('Sorun Bildir');
        $response->assertSee('Fotoğraf Yükle');
        $response->assertSee('prd-step-grid', false);
    }
}

