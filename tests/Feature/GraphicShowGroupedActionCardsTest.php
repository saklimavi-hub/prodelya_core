<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowGroupedActionCardsTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_groups_actions_into_single_active_step_panel(): void
    {
        $this->enableGraphicCustomerApproval();
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-CARDS-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('1. Görsel Yükleme');
        $response->assertSee('2. Operasyon Özeti');
        $response->assertSee('3. Müşteri Onayı');
        $response->assertSee('4. Revize');
        $response->assertSee('5. Üretime Hazır');
        $response->assertSee('graphic-action-step-tabs', false);
        $response->assertSee('graphic-step-panel-upload', false);
        $response->assertDontSee('graphic-step-panel-summary', false);
        $response->assertDontSee('graphic-step-panel-approval', false);
        $response->assertDontSee('graphic-step-panel-revision', false);
        $response->assertDontSee('graphic-step-panel-ready', false);
    }
}
