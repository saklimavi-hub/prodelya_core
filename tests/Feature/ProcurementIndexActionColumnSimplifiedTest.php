<?php

namespace Tests\Feature;

use App\Models\OrderItemProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementIndexActionColumnSimplifiedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_index_keeps_row_action_column_compact_and_moves_workflow_to_right_panel(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LIST');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LIST-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Sıradaki İş');
        $response->assertSee('Seçili Tedarik Özeti');
        $response->assertSee('Birincil Aksiyonlar');
        $response->assertSee('Detay');
        $response->assertDontSee('Formu Aç</a></td>', false);
        $response->assertDontSee('⋯', false);
        $response->assertSee('Talep Aç');

        $procurement->update(['procurement_status' => OrderItemProcurement::STATUS_SUPPLIER_ORDERED]);

        $orderedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $orderedResponse->assertOk();
        $orderedResponse->assertSee('Kısmi Geldi');
        $orderedResponse->assertSee('Tamamı Geldi');
    }
}
