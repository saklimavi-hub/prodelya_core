<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use App\Services\GraphicModuleDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicPreviewUsesOriginalSourceTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_binds_big_preview_to_original_source_field(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-ORIGINAL-001');
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'original-source.png');

        $payload = app(GraphicModuleDataBuilder::class)->buildShow($workForm->fresh()->load('printGraphics.latestAttachment'));
        $attachment = data_get($payload, 'selectedOperationCard.attachment');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $this->assertNotNull($attachment);
        $this->assertSame($attachment['original_url'], $attachment['open_url']);
        $response->assertSee('data-full-src="' . e($attachment['original_url']) . '"', false);
        $response->assertSee('src="' . e($attachment['preview_url']) . '"', false);
        $response->assertSee('class="gg-lightbox-image pd-graphic-lightbox__image"', false);
        $response->assertSee('data-lightbox-status', false);
    }
}
