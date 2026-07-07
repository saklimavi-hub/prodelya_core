<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowImagePreviewSizingTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_uses_full_preview_frame_for_product_and_uploaded_visuals(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-SIZE-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://example.test/graphics/full-preview-product.png',
            ]),
        ])->save();

        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'full-preview-graphic.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('gg-preview-frame--summary', false);
        $response->assertSee('gg-main-preview-frame', false);
        $response->assertSee('gg-preview-image', false);
        $response->assertSee('object-fit: contain', false);
        $response->assertSee('Büyük Önizleme');
        $response->assertDontSee('file_path', false);
    }
}
