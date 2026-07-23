<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionShowMockupPolishTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_show_layout_keeps_mockup_like_summary_and_sidebar_tracking_blocks(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow());

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=genel');

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Süreç durumu');
        $response->assertSee('Kompakt üretim özeti');
        $response->assertSee('Sıradaki İşlem');
        $response->assertSee('Operatör Ekranını Aç');
        $response->assertDontSee('prd-show-sidebar');
        $response->assertDontSee('Görevlerim');
        $response->assertDontSee('Bildirimler');
        $response->assertDontSee('Raporlar');
    }
}
