<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowMockupLayoutTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_renders_mockup_oriented_layout_sections(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-MOCKUP-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('Genel Durum');
        $response->assertSee('İş Özeti');
        $response->assertSee('Büyük Önizleme');
        $response->assertSee('Hızlı İşlemler');
        $response->assertSee('Çalışma Klasörü');
        $response->assertSee('gg-workspace', false);
        $response->assertSee('graphic-action-step-tabs', false);
        $response->assertSee('graphic-step-panel-upload', false);
    }
}
