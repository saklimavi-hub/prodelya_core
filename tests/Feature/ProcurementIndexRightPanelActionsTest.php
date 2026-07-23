<?php

namespace Tests\Feature;

use App\Models\OrderItemProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementIndexRightPanelActionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_right_panel_prioritizes_status_based_actions(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-RIGHT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-RIGHT-001');
        $procurement->update(['procurement_status' => OrderItemProcurement::STATUS_REQUEST_CREATED]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Talep Hazırlanacak Tedarikçiler');
        $response->assertSee('Liste Özeti');
        $response->assertSee('Sıradaki İş');
    }
}
