<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintGraphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderGraphicAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_each_print_creates_independent_graphic_admission_with_initial_statuses(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-GRAPHIC-001', 'with_print' => true, 'print_count' => 2]));

        $graphics = OrderItemPrintGraphic::query()->where('order_id', $order->id)->orderBy('sequence_code')->get();

        $this->assertCount(2, $graphics);
        $this->assertSame('1a', $graphics[0]->sequence_code);
        $this->assertSame('1b', $graphics[1]->sequence_code);
        $this->assertSame(OrderItemPrintGraphic::STATUS_WAITING_VISUAL, $graphics[0]->status);
        $this->assertSame(OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED, $graphics[0]->customer_approval_status);
        $this->assertNull($graphics[0]->production_ready_at);
    }
}
