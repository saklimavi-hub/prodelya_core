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

    public function test_legacy_internal_tab_redirects_to_touch_friendly_operator_controls(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow([
            'production_status' => \App\Models\OrderItemPrintProduction::STATUS_INTERNAL,
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
