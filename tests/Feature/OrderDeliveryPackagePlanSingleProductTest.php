<?php

namespace Tests\Feature;

use App\Models\OrderDeliveryPackage;
use App\Models\OrderDeliveryPackageItem;
use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryPackagePlanSingleProductTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_single_product_can_be_planned_into_two_packages(): void
    {
        $order = $this->createConvertedOrderForShow([
            'quantity' => '4000',
            'product_name' => 'Koli Plan Test Ürünü',
            'product_code' => 'KOLI-001',
        ]);
        $orderItem = $order->items()->firstOrFail();

        app(OrderDeliveryPlanningService::class)->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Koli 1',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 2000],
                ],
            ],
            [
                'package_label' => 'Koli 2',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $orderItem->id, 'quantity' => 2000],
                ],
            ],
        ], $this->orderShowAdminUser);

        $this->assertSame(2, OrderDeliveryPackage::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, OrderDeliveryPackageItem::query()->where('order_id', $order->id)->count());

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()->assertSee('Koli Sayısı')->assertSee('2');
    }
}
