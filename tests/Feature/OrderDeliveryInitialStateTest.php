<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderDeliveryInitialStateTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_creates_delivery_snapshot_but_not_ready_or_delivered_records(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-DELIVERY-001', 'with_print' => true]))->fresh(['deliveries', 'deliveryLabelBatches']);

        $delivery = $order->deliveries->firstOrFail();
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PENDING, $delivery->delivery_status);
        $this->assertSame('Teslimat Bekliyor', $delivery->safeStatusLabel());
        $this->assertCount(0, $order->deliveryLabelBatches);
    }
}
