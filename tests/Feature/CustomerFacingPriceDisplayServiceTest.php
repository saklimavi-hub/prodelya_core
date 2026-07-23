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
    public function test_service_shows_product_price_as_main_price_when_print_breakdown_is_visible(): void
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

        $this->assertSame('product_only', $result['price_mode']);
        $this->assertSame(5.0, $result['customer_main_unit_price']);
        $this->assertSame(500.0, $result['customer_main_total']);
        $this->assertSame(15.0, $result['combined_unit_price']);
        $this->assertSame(1500.0, $result['commercial_line_total']);
        $this->assertSame('Ürün Birim Fiyatı', $result['main_unit_label']);
        $this->assertSame('Ürün Toplamı', $result['main_total_label']);
        $this->assertTrue($result['show_commercial_total']);
        $this->assertSame('5,00 TL', $result['customer_main_unit_price_label']);
        $this->assertSame('500,00 TL', $result['customer_main_total_label']);
        $this->assertTrue($result['prints'][0]['show_price_details']);
        $this->assertSame('10,00 TL', $result['prints'][0]['unit_price_label']);
        $this->assertSame('1.000,00 TL', $result['prints'][0]['total_label']);
    }

    public function test_service_hides_print_breakdown_by_switching_main_price_to_combined_value(): void
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

        $this->assertSame('combined', $result['price_mode']);
        $this->assertSame(15.0, $result['customer_main_unit_price']);
        $this->assertSame(1500.0, $result['customer_main_total']);
        $this->assertSame('Baskı Dahil Birim Fiyat', $result['main_unit_label']);
        $this->assertSame('Baskı Dahil Satır Toplamı', $result['main_total_label']);
        $this->assertFalse($result['show_commercial_total']);
        $this->assertCount(2, $result['prints']);
        $this->assertFalse($result['prints'][0]['show_price_details']);
        $this->assertSame('400,00 TL', $result['prints'][0]['total_label']);
        $this->assertSame('600,00 TL', $result['prints'][1]['total_label']);
    }

    public function test_service_uses_combined_total_divided_by_product_quantity_when_print_quantity_differs(): void
    {
        $service = app(CustomerFacingPriceDisplayService::class);
        $order = new Order([
            'currency' => 'TL',
            'show_print_price_details_to_customer' => false,
        ]);

        $item = new OrderItem([
            'quantity' => 100,
            'unit_price' => 10,
            'line_total' => 1000,
        ]);
        $item->setRelation('order', $order);
        $item->setRelation('prints', new Collection([
            new OrderItemPrint([
                'print_type' => 'UV Baskı',
                'print_quantity' => 50,
                'print_unit_price' => 2,
                'print_total' => 100,
            ]),
        ]));

        $result = $service->buildItem($item, 'TL', false);

        $this->assertSame(11.0, $result['customer_main_unit_price']);
        $this->assertSame(1100.0, $result['customer_main_total']);
        $this->assertSame(1100.0, $result['combined_line_total']);
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

        $this->assertSame(9.0, $result['customer_main_unit_price']);
        $this->assertSame(0.0, $result['customer_main_total']);
        $this->assertSame(100.0, $result['commercial_line_total']);
    }
}
