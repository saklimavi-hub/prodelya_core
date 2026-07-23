<?php

namespace Tests\Feature;

use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderNoPrintDetailQueueExclusionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_no_print_order_detail_keeps_required_degil_labels_while_order_stays_out_of_graphic_and_production_queues(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-NOPRINT-DETAIL-001', 'with_print' => false]))->fresh(['customer', 'items.prints', 'workForms.activityLogs.creator', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments', 'items.procurement', 'items.workForm', 'items.delivery']);
        $detail = app(OrderShowSummaryService::class)->build($order, true);
        $cards = collect(data_get($detail, 'module_cards', []))->keyBy('title');

        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.production.label'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Grafik.status'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Üretim.status'));

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
