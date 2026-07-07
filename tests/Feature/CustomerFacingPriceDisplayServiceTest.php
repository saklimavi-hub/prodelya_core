<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Services\CustomerFacingPriceDisplayService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CustomerFacingPriceDisplayServiceTest extends TestCase
{
    public function test_service_combines_product_and_print_totals_into_customer_facing_unit_price(): void
    {
        $service = app(CustomerFacingPriceDisplayService::class);
        $order = new Order([
            'currency' => 'TL',
            'show_print_price_details_to_customer' => true,
        ]);

        $item = new OrderItem([
            'quantity' => 100,
            'unit_price' => 5,
            'line_total' => 500,
        ]);
        $item->setRelation('order', $order);
        $item->setRelation('prints', new Collection([
            new OrderItemPrint([
                'print_type' => 'UV Baskı',
                'print_option' => 'Çift Taraf',
                'print_quantity' => 100,
                'print_unit_price' => 10,
                'print_total' => 1000,
            ]),
        ]));

        $result = $service->buildItem($item, 'TL', true);

        $this->assertSame(15.0, $result['customer_unit_price']);
        $this->assertSame(1500.0, $result['customer_line_total']);
        $this->assertSame('15,00 TL', $result['customer_unit_price_label']);
        $this->assertSame('1.500,00 TL', $result['customer_line_total_label']);
    }

    public function test_service_sums_multiple_print_rows_and_hides_breakdown_when_disabled(): void
    {
        $service = app(CustomerFacingPriceDisplayService::class);
        $order = new Order([
            'currency' => 'TL',
            'show_print_price_details_to_customer' => false,
        ]);

        $item = new OrderItem([
            'quantity' => 100,
            'unit_price' => 5,
            'line_total' => 500,
        ]);
        $item->setRelation('order', $order);
        $item->setRelation('prints', new Collection([
            new OrderItemPrint([
                'print_type' => 'UV Baskı',
                'print_option' => 'Ön',
                'print_quantity' => 100,
                'print_unit_price' => 4,
                'print_total' => 400,
            ]),
            new OrderItemPrint([
                'print_type' => 'Lazer',
                'print_option' => 'Arka',
                'print_quantity' => 100,
                'print_unit_price' => 6,
                'print_total' => 600,
            ]),
        ]));

        $result = $service->buildItem($item, 'TL', false);

        $this->assertSame(15.0, $result['customer_unit_price']);
        $this->assertSame(1500.0, $result['customer_line_total']);
        $this->assertCount(2, $result['prints']);
        $this->assertFalse($result['prints'][0]['show_price_details']);
        $this->assertSame('400,00 TL', $result['prints'][0]['total_label']);
        $this->assertSame('600,00 TL', $result['prints'][1]['total_label']);
    }

    public function test_service_avoids_division_by_zero_and_falls_back_to_item_unit_price(): void
    {
        $service = app(CustomerFacingPriceDisplayService::class);

        $item = new OrderItem([
            'quantity' => 0,
            'unit_price' => 9,
            'line_total' => 0,
        ]);
        $item->setRelation('prints', new Collection([
            new OrderItemPrint([
                'print_unit_price' => 5,
                'print_total' => 100,
            ]),
        ]));

        $result = $service->buildItem($item, 'TL', true);

        $this->assertSame(9.0, $result['customer_unit_price']);
        $this->assertSame(100.0, $result['customer_line_total']);
    }
}
