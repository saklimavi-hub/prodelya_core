<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowNoTinyThumbnailRegressionTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_drops_old_small_thumbnail_markup_from_big_preview_area(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-THUMB-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertDontSee('max-width: 80px', false);
        $response->assertDontSee('graphic-product-image-box', false);
        $response->assertDontSee('graphic-product-image-fit', false);
        $response->assertDontSee('graphic-preview-stage', false);
        $response->assertSee('gg-main-preview-frame', false);
        $response->assertSee('pd-allow-large', false);
        $response->assertSee('width: 100% !important;', false);
    }
}
