<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementFastWorkflowUndoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_cancelled_and_not_required_procurements_can_be_reopened(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-UNDO-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-UNDO-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'cancel',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'reopen',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemProcurement::STATUS_PENDING, $procurement->procurement_status);
        $this->assertTrue($procurement->requires_procurement);
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_reopened'
        ));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'not_required',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'reopen',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemProcurement::STATUS_PENDING, $procurement->procurement_status);
        $this->assertTrue($procurement->requires_procurement);
        $this->assertSame(
            $procurement->procurement_status,
            data_get($procurement->workForm->procurement_snapshot, 'procurement_status')
        );
    }

    public function test_supplier_ordered_can_be_reopened_when_receipt_not_started_but_not_after_receipt(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-UNDO-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-UNDO-002');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $service = app(SupplierProcurementRequestService::class);
        $requestRecord = $service->markRequested($requestRecord, $this->adminUser);
        $requestRecord = $service->markSupplierOrdered($requestRecord, $this->adminUser);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'reopen',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $procurement = $procurement->fresh();
        $this->assertSame(OrderItemProcurement::STATUS_REQUEST_CREATED, $procurement->procurement_status);

        $service->markSupplierOrdered($requestRecord->fresh(), $this->adminUser);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => 10,
                'return_back' => 1,
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'reopen',
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'))
            ->assertSessionHasErrors('received_quantity');
    }

    public function test_supplier_can_be_changed_when_receipt_not_started_and_open_request_link_is_closed(): void
    {
        [$supplierA, $sourceA] = $this->createSupplierWithAccess('PROC-UNDO-C1');
        [$supplierB] = $this->createSupplierWithAccess('PROC-UNDO-C2');
        $procurement = $this->createProcurement($supplierA, $sourceA, 'SP-PROC-UNDO-003');
        $requestRecord = $this->createDraftRequest($supplierA->id, [$procurement->id]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'change_supplier',
                'supplier_id' => $supplierB->id,
                'note' => 'Alternatif tedarikçiye geçildi',
                'return_back' => 1,
            ])
            ->assertRedirect();

        $procurement = $procurement->fresh(['supplier', 'supplierRequestItems', 'workForm.activityLogs']);
        $requestRecord = $requestRecord->fresh();

        $this->assertSame($supplierB->id, (int) $procurement->supplier_id);
        $this->assertSame(OrderItemProcurement::STATUS_PENDING, $procurement->procurement_status);
        $this->assertSame(SupplierProcurementRequest::STATUS_CANCELLED, $requestRecord->status);
        $this->assertCount(0, $procurement->supplierRequestItems);
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_supplier_changed'
        ));
    }

    public function test_supplier_change_is_blocked_after_receipt_starts(): void
    {
        [$supplierA, $sourceA] = $this->createSupplierWithAccess('PROC-UNDO-D1');
        [$supplierB] = $this->createSupplierWithAccess('PROC-UNDO-D2');
        $procurement = $this->createProcurement($supplierA, $sourceA, 'SP-PROC-UNDO-004');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => 5,
                'return_back' => 1,
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'change_supplier',
                'supplier_id' => $supplierB->id,
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'))
            ->assertSessionHasErrors('received_quantity');
    }

    private function createDraftRequest(int $supplierId, array $procurementIds): SupplierProcurementRequest
    {
        return app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplierId,
            $procurementIds,
            $this->adminUser
        );
    }

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.supplierRequestItems.request'])->procurement;
    }
}
