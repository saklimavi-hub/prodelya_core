<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowActionOrderTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_keeps_action_buttons_in_requested_order(): void
    {
        $this->enableGraphicCustomerApproval();
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-ACTION-001');

        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachCustomerVisibleGraphicRecord($graphic, 'action-order.png');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()) . '?step=approval');

        $response->assertOk();
        $response->assertSee('3. Müşteri Onayı');

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/1\. Görsel Yükleme.*2\. Operasyon Özeti.*3\. Müşteri Onayı.*4\. Revize.*5\. Üretime Hazır/s',
            $html
        );
    }

    public function test_graphic_show_marks_customer_approval_not_required_for_clear_operation_summary(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-ACTION-002');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('Onay Gerekmiyor');
        $response->assertSee('Operasyon Özeti');
    }
}
