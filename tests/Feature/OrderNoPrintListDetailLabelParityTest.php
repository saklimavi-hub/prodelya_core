<?php

namespace Tests\Feature;

use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class OrderNoPrintListDetailLabelParityTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_no_print_order_shares_canonical_pre_request_labels_across_list_and_detail(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('NOPRINT-PRE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-NOPRINT-PRE-001')->fresh(['order.items.prints', 'order.workForms', 'order.procurements.supplierRequestItems.request', 'order.printProductions', 'order.deliveries', 'order.payments']);
        $order = $procurement->order->fresh(['customer', 'items.prints', 'workForms.activityLogs.creator', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments', 'items.procurement', 'items.workForm', 'items.delivery']);

        $listRow = app(OrderListSummaryService::class)->buildRow($order, true);
        $detail = app(OrderShowSummaryService::class)->build($order, true);
        $cards = collect(data_get($detail, 'module_cards', []))->keyBy('title');

        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Talep Hazırlanacak', data_get($listRow, 'sticky_panel.module_statuses.procurement.label'));
        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.production.label'));
        $this->assertSame('Tedarik talebini hazırla', data_get($listRow, 'next_action_label'));

        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Talep Hazırlanacak', data_get($detail, 'overview.sticky_panel.module_statuses.procurement.label'));
        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.production.label'));
        $this->assertSame('Talep Hazırlanacak', data_get($cards, 'Tedarik.status'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Grafik.status'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Üretim.status'));
        $this->assertSame('Talep Hazırlanacak', data_get($detail, 'overview.operation_status_label'));
        $this->assertSame('Tedarik talebini hazırla', data_get($detail, 'overview.next_action_label'));
        $this->assertSame('Talep Hazırlanacak', data_get($detail, 'overview.process_depth.focus.current_label'));
        $this->assertSame('Tedarik talebini hazırla', data_get($detail, 'overview.process_depth.focus.next_label'));
    }
}
