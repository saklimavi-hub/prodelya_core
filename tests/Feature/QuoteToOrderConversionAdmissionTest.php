<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class QuoteToOrderConversionAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_preserves_snapshots_and_marks_quote_as_converted(): void
    {
        $quote = $this->createQuoteViaHttp([
            'document_number' => 'TK-B1-ADMISSION-001',
            'with_print' => true,
            'print_count' => 2,
            'list_price' => '500',
            'unit_price' => '500',
            'quantity' => '1',
        ]);

        $order = $this->convertQuote($quote)->fresh(['items.prints', 'sourceQuote']);

        $this->assertSame('order', $order->document_type);
        $this->assertSame($quote->id, $order->source_quote_id);
        $this->assertSame($quote->document_number, $order->source_quote_number);
        $this->assertSame('order_created', $order->workflow_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame($quote->currency, $order->currency);
        $this->assertSame('quote_converted', $quote->fresh()->workflow_status);
        $this->assertTrue($quote->fresh()->convertedOrders()->where('document_type', 'order')->exists());
        $this->assertFalse($quote->fresh()->canBeEdited());

        $item = $order->items->firstOrFail();
        $sourceItem = $quote->fresh('items.prints')->items->firstOrFail();
        $this->assertSame($sourceItem->product_code, $item->product_code);
        $this->assertEquals((float) $sourceItem->list_price, (float) $item->list_price);
        $this->assertEquals((float) $sourceItem->unit_price, (float) $item->unit_price);
        $this->assertEquals((float) $sourceItem->line_total, (float) $item->line_total);
        $this->assertSame($sourceItem->price_snapshot, $item->price_snapshot);
        $this->assertSame($sourceItem->product_snapshot, $item->product_snapshot);
        $this->assertSame($sourceItem->stock_snapshot, $item->stock_snapshot);
        $this->assertCount(2, $item->prints);
    }
}
