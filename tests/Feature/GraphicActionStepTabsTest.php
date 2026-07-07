<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicActionStepTabsTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_switches_active_step_with_query_parameter(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-STEPS-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm) . '?step=summary');

        $response->assertOk();
        $response->assertSee('graphic-action-step-tabs', false);
        $response->assertSee('gg-step-tab is-active', false);
        $response->assertSee('graphic-step-panel-summary', false);
        $response->assertDontSee('graphic-step-panel-upload', false);
    }
}
