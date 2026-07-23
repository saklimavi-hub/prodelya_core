<?php

namespace Tests\Feature;

use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderNoPrintLabelParityTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_no_print_order_uses_required_degil_labels_and_procurement_prepare_state(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-NOPRINT-LABEL-001', 'with_print' => false]))->fresh();

        $listRow = app(OrderListSummaryService::class)->buildRow($order->fresh(['customer', 'items.prints', 'workForms', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments']), true);
        $detail = app(OrderShowSummaryService::class)->build($order->fresh(['customer', 'items.prints.production', 'items.procurement', 'items.workForm', 'items.delivery', 'workForms.activityLogs.creator', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments']), true);

        $this->assertSame('Talep Hazırlanacak', $listRow['operation_status_label']);
        $this->assertSame('Tedarik talebini hazırla', $listRow['next_action_label']);
        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Talep Hazırlanacak', data_get($detail, 'overview.sticky_panel.module_statuses.procurement.label'));
        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.production.label'));

        $cards = collect(data_get($detail, 'module_cards', []))->keyBy('title');
        $this->assertSame('Gerekli Değil', data_get($cards, 'Grafik.status'));
        $this->assertSame('Talep Hazırlanacak', data_get($cards, 'Tedarik.status'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Üretim.status'));
    }
}
