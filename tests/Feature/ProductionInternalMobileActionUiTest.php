<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionInternalMobileActionUiTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_internal_tab_uses_large_touch_friendly_action_controls(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow([
            'production_status' => \App\Models\OrderItemPrintProduction::STATUS_INTERNAL,
        ]));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=ic-uretim');

        $response->assertOk();
        $response->assertSee('Üretime Başla');
        $response->assertSee('Kısmi Üretildi');
        $response->assertSee('Tamamlandı');
        $response->assertSee('Sorun Bildir');
        $response->assertSee('Fotoğraf Yükle');
        $response->assertSee('prd-touch-button', false);
        $response->assertSee('prd-touch-input', false);
    }
}
