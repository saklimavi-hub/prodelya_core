<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicListThumbFrameTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_index_uses_small_thumb_frames_without_technical_paths(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-LIST-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://example.test/graphics/list-thumb-product.png',
            ]),
        ])->save();

        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'list-thumb-preview.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.index'));

        $response->assertOk();
        $response->assertSee('pd-ui-v1-graphics__thumb', false);
        $response->assertSee('pd-allow-large', false);
        $response->assertDontSee('Display Path');
        $response->assertDontSee('file_path', false);
    }
}
