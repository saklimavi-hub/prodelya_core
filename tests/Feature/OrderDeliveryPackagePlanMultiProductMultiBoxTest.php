<?php

namespace Tests\Feature;

use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryPackagePlanMultiProductMultiBoxTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_multiple_products_can_be_distributed_across_multiple_packages(): void
    {
        $order = $this->createConvertedOrderForShow([
            'items' => [
                [
                    'product_name' => 'Ürün 1',
                    'product_code' => 'MB-1',
                    'quantity' => '3',
                    'unit' => 'Adet',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'unit_price' => '5',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                    'prints' => [],
                ],
                [
                    'product_name' => 'Ürün 2',
                    'product_code' => 'MB-2',
                    'quantity' => '2',
                    'unit' => 'Adet',
                    'list_price' => '6',
                    'discount_rate' => '0',
                    'unit_price' => '6',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                    'prints' => [],
                ],
                [
                    'product_name' => 'Ürün 3',
                    'product_code' => 'MB-3',
                    'quantity' => '5',
                    'unit' => 'Adet',
                    'list_price' => '7',
                    'discount_rate' => '0',
                    'unit_price' => '7',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                    'prints' => [],
                ],
            ],
        ]);

        $items = $order->items()->orderBy('id')->get()->values();
        $packages = [];

        foreach (range(1, 3) as $index) {
            $packages[] = [
                'package_label' => 'Ürün 1 - Koli ' . $index,
                'package_type' => 'box',
                'items' => [['order_item_id' => $items[0]->id, 'quantity' => 1]],
            ];
        }

        foreach (range(1, 2) as $index) {
            $packages[] = [
                'package_label' => 'Ürün 2 - Koli ' . $index,
                'package_type' => 'box',
                'items' => [['order_item_id' => $items[1]->id, 'quantity' => 1]],
            ];
        }

        foreach (range(1, 5) as $index) {
            $packages[] = [
                'package_label' => 'Ürün 3 - Koli ' . $index,
                'package_type' => 'box',
                'items' => [['order_item_id' => $items[2]->id, 'quantity' => 1]],
            ];
        }

        $service = app(OrderDeliveryPlanningService::class);
        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), $packages, $this->orderShowAdminUser);

        $context = $service->buildContext($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveryPackages.items.orderItem',
            'deliveryLabelBatches',
            'deliveries',
        ]));

        $this->assertSame(10, $context['package_count']);
        $this->assertSame(10, $context['label_count']);
        $this->assertCount(10, $context['package_rows']);
    }
}
