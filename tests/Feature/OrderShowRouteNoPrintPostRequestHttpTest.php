<?php

namespace Tests\Feature;

use App\Models\SupplierProcurementRequest;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class OrderShowRouteNoPrintPostRequestHttpTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_order_show_route_renders_post_request_procurement_state_without_no_print_graphic_regression(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('NOPRINT-HTTP-POST');
        $procurement = $this->createProcurement($supplier, $source, 'SP-NOPRINT-HTTP-POST-001');
        $requestRecord = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $procurement->supplier_id,
            [$procurement->id],
            $this->adminUser
        );
        $requestRecord->forceFill(['status' => SupplierProcurementRequest::STATUS_REQUESTED])->save();

        $order = $procurement->order->fresh(['procurements.supplierRequestItems.request', 'workForms.attachments', 'workForms.activityLogs.creator', 'items.prints']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Gerekli Değil');
        $response->assertDontSee('Grafik hazır');
        $response->assertDontSee('Grafik Hazır');
        $response->assertSee('Tedarik Bekliyor');
        $response->assertSee('Tedarikçiden dönüş bekle');
    }
}
