<?php

namespace Tests\Feature;

use App\Models\OrderDeliveryLabelBatch;
use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryRollLabelSettingsTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_roll_label_settings_are_saved_with_package_count(): void
    {
        $order = $this->createConvertedOrderForShow(['quantity' => '2']);
        $orderItem = $order->items()->firstOrFail();
        $service = app(OrderDeliveryPlanningService::class);

        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Koli 1',
                'package_type' => 'box',
                'items' => [['order_item_id' => $orderItem->id, 'quantity' => 1]],
            ],
            [
                'package_label' => 'Koli 2',
                'package_type' => 'box',
                'items' => [['order_item_id' => $orderItem->id, 'quantity' => 1]],
            ],
        ], $this->orderShowAdminUser);

        $service->createLabelBatch($order->fresh('deliveryPackages'), [
            'template_type' => OrderDeliveryLabelBatch::TEMPLATE_ROLL,
            'roll_width_mm' => 100,
            'roll_height_mm' => 70,
            'roll_gap_mm' => 3,
        ], $this->orderShowAdminUser);

        $batch = OrderDeliveryLabelBatch::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

        $this->assertSame(OrderDeliveryLabelBatch::TEMPLATE_ROLL, $batch->template_type);
        $this->assertSame(2, $batch->label_count);
        $this->assertSame('100.00', $batch->roll_width_mm);
        $this->assertSame('70.00', $batch->roll_height_mm);
        $this->assertSame('3.00', $batch->roll_gap_mm);

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()
            ->assertSee('Rulo Etiket')
            ->assertSee('Etiket Eni (mm)')
            ->assertSee('Etiket Boyu (mm)')
            ->assertSee('Etiket Ara Mesafesi (mm)')
            ->assertSee('2 etiket')
            ->assertSee('100 mm x 70 mm')
            ->assertSee('Ara 3 mm');
    }
}
