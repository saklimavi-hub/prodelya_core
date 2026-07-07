<?php

namespace Tests\Feature;

use App\Models\OrderDeliveryLabelBatch;
use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryLabelPrintViewTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_print_view_shows_package_content_without_sensitive_fields(): void
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
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 2],
                ],
            ],
        ], $this->orderShowAdminUser);
        $service->createLabelBatch($order->fresh('deliveryPackages'), [
            'template_type' => OrderDeliveryLabelBatch::TEMPLATE_A4_1_1,
        ], $this->orderShowAdminUser);

        $batch = OrderDeliveryLabelBatch::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.delivery-labels.print', ['order' => $order, 'batch' => $batch->id]));

        $response->assertOk()
            ->assertSee($order->document_number)
            ->assertSee((string) $order->customer?->legal_name)
            ->assertSee('Koli 1')
            ->assertDontSee('current_account_id', false)
            ->assertDontSee('source_type', false)
            ->assertDontSee('profit', false)
            ->assertDontSee('supplier_cost', false);
    }
}
