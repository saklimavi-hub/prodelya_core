<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkFormActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowHistoryTurkishTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_renders_workflow_history_with_turkish_labels(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-HISTORY-001');

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'procurement_request_created',
            'visibility' => 'internal',
            'created_by' => $this->graphicAdminUser->id,
        ]);

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'production_operation_created',
            'visibility' => 'customer_visible',
            'created_by' => $this->graphicAdminUser->id,
        ]);

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('Tedarik ihtiyacı oluşturuldu');
        $response->assertSee('Tedarik kaydı oluşturuldu.');
        $response->assertSee('Üretim operasyonu oluşturuldu');
        $response->assertDontSee('Procurement needed');
        $response->assertDontSee('Production operation created');
        $response->assertDontSee('procurement_request_created');
        $response->assertDontSee('production_operation_created');
    }
}
