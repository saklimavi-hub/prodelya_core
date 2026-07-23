<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderItemWorkForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderWorkflowInitialStatusTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_printed_order_starts_with_canonical_waiting_states(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-WORKFLOW-PRINT', 'with_print' => true]))->fresh(['workForms', 'deliveries']);
        $workForm = OrderItemWorkForm::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame('bekliyor', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('uretim_bekliyor', data_get($workForm->production_snapshot, 'status'));
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PENDING, $order->deliveries->firstOrFail()->delivery_status);
    }

    public function test_no_print_order_marks_graphic_and_production_not_required(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-WORKFLOW-NOPRINT', 'with_print' => false]))->fresh(['workForms']);
        $workForm = OrderItemWorkForm::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame('gerekli_degil', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($workForm->production_snapshot, 'status'));
    }
}
