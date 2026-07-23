<?php

namespace Tests\Feature;

use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderProcurementAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_creates_single_procurement_admission_without_auto_opening_supplier_request(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-PROC-001', 'with_print' => true]));

        $procurement = OrderItemProcurement::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($order->items()->firstOrFail()->id, $procurement->order_item_id);
        $this->assertNotNull($procurement->work_form_id);
        $this->assertSame(1, OrderItemProcurement::query()->where('order_item_id', $procurement->order_item_id)->count());
        $this->assertSame(0, SupplierProcurementRequest::query()->count());
        $this->assertTrue($procurement->requires_procurement);
        $this->assertSame('need_unrequested', $procurement->fresh(['supplierRequestItems.request'])->userFacingState());
        $this->assertSame('Talep Hazırlanacak', $procurement->userFacingStatusLabel());
        $this->assertSame('Tedarik talebini hazırla', $procurement->userFacingNextActionLabel());
    }
}
