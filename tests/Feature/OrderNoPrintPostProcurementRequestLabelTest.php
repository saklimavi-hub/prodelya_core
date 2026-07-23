<?php

namespace Tests\Feature;

use App\Models\SupplierProcurementRequest;
use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class OrderNoPrintPostProcurementRequestLabelTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_no_print_order_keeps_tedarik_bekliyor_after_supplier_request_is_requested(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('NOPRINT-POST');
        $procurement = $this->createProcurement($supplier, $source, 'SP-NOPRINT-POST-001');
        $request = $this->createSupplierRequest($procurement);
        $request->forceFill(['status' => SupplierProcurementRequest::STATUS_REQUESTED])->save();

        $order = $procurement->order->fresh(['customer', 'items.prints', 'workForms.activityLogs.creator', 'procurements.supplierRequestItems.request', 'printProductions', 'deliveries', 'payments', 'items.procurement', 'items.workForm', 'items.delivery']);
        $listRow = app(OrderListSummaryService::class)->buildRow($order, true);
        $detail = app(OrderShowSummaryService::class)->build($order, true);
        $cards = collect(data_get($detail, 'module_cards', []))->keyBy('title');

        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Tedarik Bekliyor', data_get($listRow, 'sticky_panel.module_statuses.procurement.label'));
        $this->assertSame('Gerekli Değil', data_get($listRow, 'sticky_panel.module_statuses.production.label'));
        $this->assertSame('Tedarikçiden dönüş bekle', data_get($listRow, 'next_action_label'));

        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.graphic.label'));
        $this->assertSame('Tedarik Bekliyor', data_get($detail, 'overview.sticky_panel.module_statuses.procurement.label'));
        $this->assertSame('Gerekli Değil', data_get($detail, 'overview.sticky_panel.module_statuses.production.label'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Grafik.status'));
        $this->assertSame('Tedarik Bekliyor', data_get($cards, 'Tedarik.status'));
        $this->assertSame('Gerekli Değil', data_get($cards, 'Üretim.status'));
        $this->assertSame('Tedarik Bekliyor', data_get($detail, 'overview.operation_status_label'));
        $this->assertSame('Tedarikçiden dönüş bekle', data_get($detail, 'overview.next_action_label'));
        $this->assertSame('Tedarik Bekliyor', data_get($detail, 'overview.process_depth.focus.current_label'));
        $this->assertSame('Tedarikçiden dönüş bekle', data_get($detail, 'overview.process_depth.focus.next_label'));
    }
}
