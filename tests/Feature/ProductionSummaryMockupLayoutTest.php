<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionSummaryMockupLayoutTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_general_tab_renders_mockup_style_summary_sections(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow());

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=genel');

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Siparişi Aç');
        $response->assertSee('İş Formu');
        $response->assertSee('Süreç durumu');
        $response->assertSee('Kompakt üretim özeti');
        $response->assertSee('Kalite kontrol gerekli değil');
        $response->assertSee('Sıradaki İşlem');
    }
}
