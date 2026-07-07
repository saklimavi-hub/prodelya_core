<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicVisualAcceptanceSmokeTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_html_contains_visual_acceptance_markers_without_technical_leak(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-SMOKE-001');
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'visual-smoke.png', 'internal');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('graphic-main-preview-frame', false);
        $response->assertSee('object-fit: contain', false);
        $response->assertSee('graphic-action-step-tabs', false);
        $response->assertSee('graphic-step-panel-summary', false);
        $response->assertDontSee('graphic-step-panel-upload', false);
        $response->assertDontSee('Display Path');
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('source_type', false);
        $response->assertDontSee('payload', false);
    }
}
