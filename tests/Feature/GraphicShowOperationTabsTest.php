<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowOperationTabsTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_switches_selected_operation_with_tabs(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-TABS-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->values();

        $this->attachGraphicImage($graphics[1], 'operation-b-preview.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm) . '?operation=' . $graphics[1]->id);

        $response->assertOk();
        $response->assertSee('graphic-operation-tabs', false);
        $response->assertSee('?operation=' . $graphics[1]->id, false);
        $response->assertSee('gg-operation-tab is-active', false);
        $response->assertSee('operation-b-preview.png');
    }
}
