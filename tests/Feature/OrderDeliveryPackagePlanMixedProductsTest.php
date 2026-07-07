<?php

namespace Tests\Feature;

use App\Services\OrderDeliveryPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryPackagePlanMixedProductsTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_single_package_can_include_two_different_products(): void
    {
        $order = $this->createConvertedOrderForShow([
            'items' => [
                [
                    'product_name' => 'A Ürünü',
                    'product_code' => 'MIX-A',
                    'quantity' => '1000',
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
                    'product_name' => 'B Ürünü',
                    'product_code' => 'MIX-B',
                    'quantity' => '1000',
                    'unit' => 'Adet',
                    'list_price' => '6',
                    'discount_rate' => '0',
                    'unit_price' => '6',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                    'prints' => [],
                ],
            ],
        ]);

        $items = $order->items()->orderBy('id')->get();

        $service = app(OrderDeliveryPlanningService::class);
        $service->storePackages($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveries',
        ]), [
            [
                'package_label' => 'Karışık Koli',
                'package_type' => 'box',
                'items' => [
                    ['order_item_id' => $items[0]->id, 'quantity' => 1000],
                    ['order_item_id' => $items[1]->id, 'quantity' => 1000],
                ],
            ],
        ], $this->orderShowAdminUser);

        $context = $service->buildContext($order->fresh([
            'items.delivery',
            'items.workForm.printProductions',
            'items.workForm.procurement',
            'deliveryPackages.items.orderItem',
            'deliveryLabelBatches',
            'deliveries',
        ]));

        $this->assertSame(1, $context['package_count']);
        $this->assertSame(1, $context['label_count']);
        $this->assertCount(1, $context['package_rows']);
        $this->assertSame(2, $context['package_rows'][0]['item_count']);
        $this->assertSame('2.000', str_replace(',0', '', number_format((float) $context['package_rows'][0]['total_quantity'], 0, ',', '.')));
    }
}
