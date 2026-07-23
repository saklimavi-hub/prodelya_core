<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderProcurementAdmissionIdempotencyTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_repeated_conversion_does_not_create_duplicate_order_or_procurement_need(): void
    {
        $quote = $this->createQuoteViaHttp(['document_number' => 'TK-B1-IDEMP-001', 'with_print' => false]);

        $firstConversion = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $quote));
        $firstConversion->assertRedirect();

        $secondConversion = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $quote));
        $secondConversion->assertRedirect();

        $orders = Order::query()->orders()->where('source_quote_id', $quote->id)->get();

        $this->assertCount(1, $orders);
        $this->assertSame(1, OrderItemProcurement::query()->where('order_id', $orders->first()->id)->count());
        $this->assertSame(1, OrderItemProcurement::query()->where('order_item_id', $orders->first()->items()->firstOrFail()->id)->count());
    }
}
