<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowPreviewUsesFullFrameTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_uses_full_frame_preview_standard_for_product_and_selected_visual(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-FRAME-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://example.test/graphics/frame-product.png',
            ]),
        ])->save();

        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'frame-selected.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('gg-main-preview-frame', false);
        $response->assertSee('height: clamp(360px, 46vh, 560px);', false);
        $response->assertSee('width: 100% !important;', false);
        $response->assertSee('max-width: 100% !important;', false);
        $response->assertSee('object-fit: contain', false);
    }
}
