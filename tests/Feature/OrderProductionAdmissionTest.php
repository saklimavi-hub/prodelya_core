<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderProductionAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_creates_production_records_with_initial_quantities_and_pending_status(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-PROD-001', 'with_print' => true, 'print_count' => 2]));

        $productions = OrderItemPrintProduction::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $productions);
        $this->assertSame(OrderItemPrintProduction::STATUS_PENDING, $productions[0]->production_status);
        $this->assertEquals(100.0, (float) $productions[0]->planned_quantity);
        $this->assertEquals(0.0, (float) $productions[0]->completed_quantity);
        $this->assertEquals(100.0, (float) $productions[0]->remaining_quantity);
    }
}
