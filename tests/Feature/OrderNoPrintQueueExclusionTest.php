<?php

namespace Tests\Feature;

use App\Services\OrderListSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderNoPrintQueueExclusionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_no_print_order_stays_out_of_graphic_and_production_queues_and_shows_required_degil(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-NOPRINT-QUEUE-001', 'with_print' => false]))->fresh();

        $listRow = app(OrderListSummaryService::class)->buildRow($order->fresh(['customer', 'items.prints', 'workForms', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments']), true);
        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.production.label'));

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
