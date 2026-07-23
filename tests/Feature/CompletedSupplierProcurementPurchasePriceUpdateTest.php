<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\SupplierProcurementRequest;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class CompletedSupplierProcurementPurchasePriceUpdateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_completed_request_renders_price_save_contract_and_locks_quantity_fields(): void
    {
        $requestRecord = $this->createCompletedRequest();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('Alış Fiyatlarını Kaydet');
        $response->assertSee('Tüm ürünler teslim alındı.');
        $response->assertDontSee('Geleni Kaydet');
        $response->assertDontSee('Tamamı Geldi');
        $response->assertSee('readonly', false);
        $response->assertSee('action="' . route('admin.procurements.supplier-requests.update', $requestRecord) . '"', false);
        $response->assertDontSee('<form id="supplier-request-update-form" method="POST" action="' . route('admin.procurements.supplier-requests.update', $requestRecord) . '"><form', false);
    }

    public function test_completed_request_price_save_persists_prices_note_and_updates_existing_current_account_transaction_without_duplicate(): void
    {
        $requestRecord = $this->createCompletedRequest();
        $item = $requestRecord->items()->firstOrFail();
        $existingTransaction = app(SupplierProcurementCurrentAccountSyncService::class)->findExistingTransactionForItem($item);

        $this->assertNotNull($existingTransaction);
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->count());

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'note' => 'Tamamlandi fiyat duzeltmesi',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => '60,00',
                        'purchase_list_price' => '18,00',
                        'discount_rate' => '10,00',
                        'purchase_unit_price' => '16,20',
                        'note' => 'Yeni alim notu',
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));
        $response->assertSessionHas('success', 'Alış fiyatları güncellendi.');

        $requestRecord = $requestRecord->fresh();
        $item = $item->fresh();

        $this->assertSame(SupplierProcurementRequest::STATUS_COMPLETED, $requestRecord->status);
        $this->assertSame(60.0, (float) $item->received_quantity);
        $this->assertSame(0.0, (float) $item->remaining_quantity);
        $this->assertSame(60.0, (float) $item->requested_quantity);
        $this->assertSame(18.0, (float) $item->purchase_list_price);
        $this->assertSame(10.0, (float) $item->discount_rate);
        $this->assertSame(16.2, (float) $item->purchase_unit_price);
        $this->assertSame(972.0, (float) $item->purchase_total);
        $this->assertSame('Yeni alim notu', $item->note);
        $this->assertSame('Tamamlandi fiyat duzeltmesi', $requestRecord->note);

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->count());

        $updatedTransaction = app(SupplierProcurementCurrentAccountSyncService::class)->findExistingTransactionForItem($item);
        $this->assertNotNull($updatedTransaction);
        $this->assertSame($existingTransaction->id, $updatedTransaction->id);
        $this->assertSame(972.0, (float) $updatedTransaction->amount);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_account_id' => $this->tenant->id,
            'action' => 'supplier_procurement_purchase_prices_updated',
            'entity_type' => 'supplier_procurement_request_item',
            'entity_id' => $item->id,
            'notes' => 'Alis Fiyatlari Guncellendi',
        ]);
    }

    public function test_unauthorized_user_cannot_update_completed_request_purchase_prices(): void
    {
        $requestRecord = $this->createCompletedRequest();
        $user = $this->createProductionUser();

        $show = $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $show->assertOk();
        $show->assertDontSee('Alış Fiyatlarını Kaydet');

        $item = $requestRecord->items()->firstOrFail();

        $update = $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'items' => [
                    [
                        'id' => $item->id,
                        'purchase_list_price' => '17,00',
                    ],
                ],
            ]);

        $update->assertForbidden();
    }

    public function test_completed_request_price_save_rejects_invalid_values(): void
    {
        $requestRecord = $this->createCompletedRequest();
        $item = $requestRecord->items()->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.supplier-requests.edit', $requestRecord))
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'items' => [
                    [
                        'id' => $item->id,
                        'purchase_list_price' => '-1',
                        'discount_rate' => '110',
                        'purchase_unit_price' => 'abc',
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));
        $response->assertSessionHasErrors([
            'items.0.purchase_list_price',
            'items.0.discount_rate',
            'items.0.purchase_unit_price',
        ]);
    }

    private function createCompletedRequest(): SupplierProcurementRequest
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-COMPLETE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-COMPLETE-001');
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

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord), [
                'received_items' => [
                    $requestRecord->items()->firstOrFail()->id => '60',
                ],
            ]);

        $requestRecord = $requestRecord->fresh(['items']);
        $item = $requestRecord->items->firstOrFail();
        $item->purchase_list_price = 20;
        $item->discount_rate = 0;
        $item->recalculatePurchaseTotals(null, (float) $item->received_quantity)->save();
        app(SupplierProcurementCurrentAccountSyncService::class)->syncRequest($requestRecord->fresh(['items.request.supplier', 'items.procurement', 'supplier']));

        return $requestRecord->fresh(['items']);
    }
}
