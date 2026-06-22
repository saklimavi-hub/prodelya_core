<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SupplierProcurementRequestDataBuilder;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProcurementRequestStatusWorkflowTest extends TestCase
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

    public function test_mark_requested_and_mark_supplier_ordered_actions_sync_procurement_workflow(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-STATUS-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-STATUS-001');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-requested', $requestRecord))
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement.workForm.activityLogs');
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(SupplierProcurementRequest::STATUS_REQUESTED, $requestRecord->status);
        $this->assertSame(OrderItemProcurement::STATUS_REQUEST_CREATED, $procurement->procurement_status);
        $this->assertSame(
            $procurement->procurement_status,
            data_get($procurement->workForm->procurement_snapshot, 'procurement_status')
        );
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_request_created'
        ));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-supplier-ordered', $requestRecord))
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement.workForm.activityLogs');
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED, $requestRecord->status);
        $this->assertSame(OrderItemProcurement::STATUS_SUPPLIER_ORDERED, $procurement->procurement_status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'supplier_ordered'
        ));
    }

    public function test_partial_and_complete_actions_sync_items_header_and_procurements(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-STATUS-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-STATUS-002');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $service = app(SupplierProcurementRequestService::class);
        $requestRecord = $service->markRequested($requestRecord, $this->adminUser);
        $requestRecord = $service->markSupplierOrdered($requestRecord, $this->adminUser);
        $requestItem = $requestRecord->fresh('items')->items->first();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.supplier-requests.edit', $requestRecord))
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord), [
                'received_items' => [
                    $requestItem->id => 101,
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord))
            ->assertSessionHasErrors('received_items');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord), [
                'received_items' => [
                    $requestItem->id => 30,
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement.workForm.activityLogs');
        $requestItem = $requestRecord->items->first();
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED, $requestRecord->status);
        $this->assertSame(30.0, (float) $requestItem->received_quantity);
        $this->assertSame(70.0, (float) $requestItem->remaining_quantity);
        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(
            $procurement->procurement_status,
            data_get($procurement->workForm->procurement_snapshot, 'procurement_status')
        );
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_partially_received'
        ));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-completed', $requestRecord))
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement.workForm.activityLogs');
        $requestItem = $requestRecord->items->first();
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(SupplierProcurementRequest::STATUS_COMPLETED, $requestRecord->status);
        $this->assertSame(100.0, (float) $requestItem->received_quantity);
        $this->assertSame(0.0, (float) $requestItem->remaining_quantity);
        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_fully_received'
        ));
    }

    public function test_cancel_is_blocked_after_receipt_and_reopens_candidates_when_receipt_not_started(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-STATUS-C');
        $candidateProcurement = $this->createProcurement($supplier, $source, 'SP-SPR-STATUS-003');
        $candidateRequest = $this->createDraftRequest($supplier->id, [$candidateProcurement->id]);

        $service = app(SupplierProcurementRequestService::class);
        $candidateRequest = $service->markRequested($candidateRequest, $this->adminUser);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.cancel', $candidateRequest))
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $candidateRequest));

        $candidateRequest = $candidateRequest->fresh();
        $candidateProcurement = $candidateProcurement->fresh();

        $this->assertSame(SupplierProcurementRequest::STATUS_CANCELLED, $candidateRequest->status);
        $this->assertSame(OrderItemProcurement::STATUS_REQUEST_CREATED, $candidateProcurement->procurement_status);

        $candidates = app(SupplierProcurementRequestDataBuilder::class)
            ->getCandidateProcurementsForSupplier($this->tenant, $supplier->id);

        $this->assertTrue($candidates->contains('id', $candidateProcurement->id));

        [$blockingSupplier, $blockingSource] = $this->createSupplierWithAccess('SPR-STATUS-D');
        $blockingProcurement = $this->createProcurement($blockingSupplier, $blockingSource, 'SP-SPR-STATUS-004');
        $blockingRequest = $this->createDraftRequest($blockingSupplier->id, [$blockingProcurement->id]);
        $blockingRequest = $service->markRequested($blockingRequest, $this->adminUser);
        $blockingRequest = $service->markSupplierOrdered($blockingRequest, $this->adminUser);

        $blockingItem = $blockingRequest->fresh('items')->items->first();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $blockingRequest), [
                'received_items' => [
                    $blockingItem->id => 10,
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $blockingRequest));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.supplier-requests.edit', $blockingRequest))
            ->post(route('admin.procurements.supplier-requests.cancel', $blockingRequest))
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $blockingRequest))
            ->assertSessionHasErrors('request');

        $this->assertNotSame(
            SupplierProcurementRequest::STATUS_CANCELLED,
            $blockingRequest->fresh()->status
        );
    }

    public function test_unauthorized_and_cross_tenant_status_routes_are_forbidden_and_no_stock_movement_is_created(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-STATUS-E');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-STATUS-005');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $limitedUser = $this->createProductionUser();

        $response = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('Alış Birim Fiyatı');
        $response->assertDontSee('Alış Toplam');

        $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-requested', $requestRecord))
            ->assertForbidden();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Tenant Cross',
            'legal_name' => 'Tenant Cross Ltd.',
            'slug' => 'tenant-cross',
            'panel_subdomain' => 'tenant-cross',
            'status' => 'active',
        ]);

        $foreignRequest = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'request_number' => 'TS-2026-9988',
            'request_date' => '2026-06-14',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-requested', $foreignRequest))
            ->assertForbidden();

        $this->assertSame(0, StockMovement::query()->count());
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

        return $item->fresh(['procurement.workForm', 'procurement.order'])->procurement;
    }

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'production-status-user-' . uniqid() . '@prodelya.local',
            'password' => 'password',
        ]);

        $roleId = \App\Models\Role::query()->where('key', 'production')->value('id');

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
