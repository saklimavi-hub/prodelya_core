<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicPreviewNoTinyImageStyleRegressionTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_big_preview_markup_keeps_full_frame_sizing_and_drops_tiny_rules(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-TINY-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('gg-main-preview-frame', false);
        $response->assertSee('height: clamp(360px, 46vh, 560px);', false);
        $response->assertSee('width: 100% !important;', false);
        $response->assertSee('max-height: 100% !important;', false);
        $response->assertDontSee('max-width:80px', false);
        $response->assertDontSee('max-height:80px', false);
    }
}
