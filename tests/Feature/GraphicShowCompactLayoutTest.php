<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowCompactLayoutTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_renders_compact_operation_layout_without_extra_folder_card(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-COMPACT-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('İş Özeti');
        $response->assertSee('Grafik Operasyonları');
        $response->assertSee('Çalışma Klasörü');
        $response->assertSee('Kısayollar');
        $response->assertSee('Büyük Önizleme');
        $response->assertSee('graphic-quick-links', false);
        $response->assertSee('gg-summary-layout', false);
        $response->assertSee('gg-workspace', false);
        $response->assertSee('İşlem Adımları');
        $response->assertDontSee('Kısayol Özeti');
        $response->assertDontSee('Display Path');
    }
}
