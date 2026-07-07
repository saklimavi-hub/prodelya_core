<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowRightPanelCleanTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_keeps_right_panel_clean_and_non_technical(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-SIDE-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('İş Özeti');
        $response->assertSee('Kısayollar');
        $response->assertSee('Çalışma Klasörü');
        $response->assertDontSee('Display Path');
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('source_type', false);
    }
}
