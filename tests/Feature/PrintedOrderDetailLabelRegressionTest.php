<?php

namespace Tests\Feature;

use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class PrintedOrderDetailLabelRegressionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_printed_order_detail_does_not_regress_to_no_print_labels(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-PRINTED-PARITY-001', 'with_print' => true]))->fresh(['customer', 'items.prints', 'workForms.activityLogs.creator', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments', 'items.procurement', 'items.workForm', 'items.delivery']);
        $listRow = app(OrderListSummaryService::class)->buildRow($order, true);
        $detail = app(OrderShowSummaryService::class)->build($order, true);
        $cards = collect(data_get($detail, 'module_cards', []))->keyBy('title');

        $this->assertNotSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.graphic.label'));
        $this->assertNotSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertNotSame('Gerekli Değil', data_get($cards, 'Grafik.status'));
        $this->assertSame(data_get($listRow, 'sticky_panel.module_statuses.graphic.label'), data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertSame(data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'), data_get($cards, 'Grafik.status'));
    }
}
