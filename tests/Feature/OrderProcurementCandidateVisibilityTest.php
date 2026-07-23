<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class OrderProcurementCandidateVisibilityTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_unrequested_procurement_need_is_visible_in_candidate_list_while_open_requests_remain_visible(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-CAND');
        $linkedProcurement = $this->createProcurement($supplier, $source, 'SP-CAND-REQ-001');
        $candidateProcurement = $this->createProcurement($supplier, $source, 'SP-CAND-NEED-001');
        $requestRecord = $this->createSupplierRequest($linkedProcurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['supplier_id' => $supplier->id]));

        $response->assertOk();
        $response->assertSee('Talep Hazırlanacak İhtiyaçlar');
        $response->assertSee('data-testid="procurement-candidate-table"', false);
        $response->assertSee($candidateProcurement->order->document_number);
        $response->assertSee((string) data_get($candidateProcurement->snapshot, 'product_code'));
        $response->assertSee('Tedarik talebini hazırla');
        $response->assertSee($requestRecord->request_number);

        $candidateIds = app(SupplierProcurementRequestDataBuilder::class)
            ->getCandidateProcurementsForSupplier($this->tenant, $supplier->id)
            ->pluck('id')
            ->all();

        $this->assertContains($candidateProcurement->id, $candidateIds);
        $this->assertNotContains($linkedProcurement->id, $candidateIds);
    }
}
