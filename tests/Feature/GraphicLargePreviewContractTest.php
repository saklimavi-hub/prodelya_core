<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicLargePreviewContractTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_uses_dedicated_large_preview_contract(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-LIGHTBOX-001');
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'lightbox-contract.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('data-full-src=', false);
        $response->assertDontSee('data-lightbox-src=', false);
        $response->assertSee('pd-graphic-lightbox__viewport', false);
        $response->assertSee('pd-graphic-lightbox__image', false);
        $response->assertSee('data-lightbox-status', false);
        $response->assertSee("const fullSrc = trigger.getAttribute('data-full-src');", false);
        $response->assertSee('Grafik görseli yüklenemedi. Orijinal dosyayı açmayı deneyin.', false);
    }
}
