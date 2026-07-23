<?php

namespace Tests\Feature;

use App\Models\SupplierProcurementRequest;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementReceiptModalContractTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_receipt_window_renders_real_contract_and_blocks_over_receipt(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-RECEIPT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-REC-001');
        $requestRecord = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-requested', $requestRecord));

        $requestRecord = $requestRecord->fresh();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-supplier-ordered', $requestRecord));

        $requestRecord = $requestRecord->fresh();

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('data-procurement-reference-family="receipt-window"', false);
        $edit->assertSee('Gelen Ürün Kaydı');
        $edit->assertSee('Geleni Kaydet');
        $edit->assertDontSee('<form id="supplier-request-partial-form" method="POST" action="' . route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord) . '"><form', false);

        $overflow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.supplier-requests.edit', $requestRecord))
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord), [
                'received_items' => [
                    $requestRecord->items()->firstOrFail()->id => '9999',
                ],
            ]);

        $overflow->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));
        $overflow->assertSessionHasErrors('received_items');

        $requestRecord->refresh();
        $this->assertSame(SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED, $requestRecord->status);
    }
}
