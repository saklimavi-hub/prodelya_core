<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowNoDoubleHeaderSmokeTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_order_show_keeps_single_main_header(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Sipariş Detayı'));
    }
}
