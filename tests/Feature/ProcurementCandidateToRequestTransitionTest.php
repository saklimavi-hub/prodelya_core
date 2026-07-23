<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementCandidateToRequestTransitionTest extends TestCase
{
    use InteractsWithProcurementFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_candidate_moves_from_prepare_list_to_open_request_lifecycle_without_duplication(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-TRANS');
        $procurement = $this->createProcurement($supplier, $source, 'SP-TRANS-001');

        $createPage = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplier->id]));

        $createPage->assertOk();
        $createPage->assertSee($procurement->order->document_number);

        $store = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.store'), [
                'supplier_id' => $supplier->id,
                'procurement_ids' => [$procurement->id],
            ]);

        $requestRecord = $procurement->fresh(['supplierRequestItems.request'])->openSupplierRequest();

        $this->assertNotNull($requestRecord);
        $store->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));
        $this->assertSame('request_draft', $procurement->fresh(['supplierRequestItems.request'])->userFacingState());
        $this->assertSame('Talep Taslağı', $procurement->fresh(['supplierRequestItems.request'])->userFacingStatusLabel());

        $candidateIds = app(SupplierProcurementRequestDataBuilder::class)
            ->getCandidateProcurementsForSupplier($this->tenant, $supplier->id)
            ->pluck('id')
            ->all();

        $this->assertNotContains($procurement->id, $candidateIds);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['supplier_id' => $supplier->id]));

        $index->assertOk();
        $index->assertSee($requestRecord->request_number);
    }
}
