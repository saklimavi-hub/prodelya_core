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

    public function test_operator_screen_shows_operator_focused_action_surface(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_INTERNAL,
            'completed_quantity' => 20,
            'remaining_quantity' => 80,
        ]));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=ic-uretim')
            ->assertRedirect(route('admin.productions.operator', $production));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.operator', $production));

        $response->assertOk();
        $response->assertSee('Kısmi Kaydet');
        $response->assertSee('Tamamlandı');
        $response->assertSee('Sorun Bildir');
        $response->assertSee('Fotoğraf Ekle');
        $response->assertSee('pd-ui-v1-internal-operator', false);
    }
}
