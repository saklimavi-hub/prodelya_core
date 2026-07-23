<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderNoPrintGraphicProductionExclusionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_no_print_order_does_not_enter_graphic_or_production_queues(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-NOPRINT-001', 'with_print' => false]));

        $this->assertSame(0, OrderItemPrintGraphic::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());

        $graphics = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.graphics.index'));
        $graphics->assertDontSee($order->document_number);

        $productions = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.productions.index'));
        $productions->assertDontSee($order->document_number);
    }
}
