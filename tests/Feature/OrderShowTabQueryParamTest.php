<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowTabQueryParamTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_tab_query_param_switches_to_delivery_and_finance_and_invalid_falls_back(): void
    {
        $order = $this->createConvertedOrderForShow();

        $delivery = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $delivery->assertOk()->assertSee('Teslimata Hazırla')->assertSee('Koli Planı');

        $finance = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $finance->assertOk()->assertSee('Müşteri Borcu')->assertSee('Kalan Bakiye');

        $fallback = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'gecersiz']));

        $fallback->assertOk()->assertSee('Genel Özet');
    }
}
