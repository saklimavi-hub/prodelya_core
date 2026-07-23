<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class PublicGraphicFileRouteTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_customer_visible_graphic_file_route_returns_image_response(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-PUBLIC-FILE-001');
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'public-graphic-file.png');
        $attachment = $graphic->fresh('latestAttachment')->latestAttachment;

        $response = $this->get(route('public.work-forms.attachments.show', [
            'token' => $workForm->fresh()->public_tracking_token,
            'attachment' => $attachment->id,
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'inline; filename="public-graphic-file.png"');
    }
}
