<?php

namespace Tests\Feature;

use App\Models\OrderDeliveryLabelBatch;
use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryLabelA4PaginationTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_a4_quarter_template_calculates_page_count(): void
    {
        $order = $this->createConvertedOrderForShow([
            'quantity' => '10',
            'product_name' => 'Etiket Test Ürünü',
            'product_code' => 'ETK-001',
        ]);
        $orderItem = $order->items()->firstOrFail();

        $packages = [];
        for ($i = 1; $i <= 10; $i++) {
            $packages[] = [
                'package_label' => 'Koli ' . $i,
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 1],
                ],
            ];
        }

        $service = app(OrderDeliveryPlanningService::class);
        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), $packages, $this->orderShowAdminUser);
        $service->createLabelBatch($order->fresh('deliveryPackages'), [
            'template_type' => OrderDeliveryLabelBatch::TEMPLATE_A4_1_4,
        ], $this->orderShowAdminUser);

        $batch = OrderDeliveryLabelBatch::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(10, $batch->label_count);
        $this->assertSame(3, $batch->page_count);

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()->assertSee('10 etiket için 3 A4 sayfa hazırlanacak. 2 tam sayfa + 1 yarım sayfa.');
    }
}
