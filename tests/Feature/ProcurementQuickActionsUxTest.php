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
use App\Models\UserRole;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementQuickActionsUxTest extends TestCase
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

    public function test_index_shows_simplified_quick_actions_with_selected_procurement_summary(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-QUICK-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-QUICK-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Sipariş Verildi');
        $response->assertSee('Kısmi Geldi');
        $response->assertSee('Geldi');
        $response->assertSee('Bu Tur Gelen Adet');
        $response->assertSee('Kısa Not');
        $response->assertSee('Talep Aç');
        $response->assertSee('Seçili Tedarik Özeti');
        $response->assertSee('Sıradaki Aksiyon');
        $response->assertSee('Tedarikçi Değiştir');
        $response->assertSee($procurement->order->document_number);
        $response->assertSee('⋯');
        $response->assertDontSee('Aktif Tenant');
        $response->assertDontSee('Sistem Notu');
        $response->assertDontSee('KDV');
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertSee('data-procurement-row', false);
        $response->assertSee('data-selected-field="product_name"', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_index_quick_actions_update_procurement_and_sync_linked_request_items(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-QUICK-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-QUICK-002');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $requestItem = $requestRecord->fresh('items')->items->first();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'supplier_ordered',
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'));

        $requestRecord = $requestRecord->fresh();
        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemProcurement::STATUS_SUPPLIER_ORDERED, $procurement->procurement_status);
        $this->assertSame(SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED, $requestRecord->status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => 30,
                'note' => 'İlk koli geldi',
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'));

        $requestItem = $requestItem->fresh();
        $requestRecord = $requestRecord->fresh();
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(30.0, (float) $requestItem->received_quantity);
        $this->assertSame(70.0, (float) $requestItem->remaining_quantity);
        $this->assertSame(SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED, $requestRecord->status);
        $this->assertSame(
            $procurement->procurement_status,
            data_get($procurement->workForm->procurement_snapshot, 'procurement_status')
        );
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_partially_received'
        ));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'));

        $requestItem = $requestItem->fresh();
        $requestRecord = $requestRecord->fresh();
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(100.0, (float) $requestItem->received_quantity);
        $this->assertSame(0.0, (float) $requestItem->remaining_quantity);
        $this->assertSame(SupplierProcurementRequest::STATUS_COMPLETED, $requestRecord->status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_fully_received'
        ));
    }

    public function test_partial_receive_cannot_exceed_remaining_and_linked_request_shows_talebi_ac(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-QUICK-C');
        $linkedProcurement = $this->createProcurement($supplier, $source, 'SP-PROC-QUICK-003');
        $requestRecord = $this->createDraftRequest($supplier->id, [$linkedProcurement->id]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.index'))
            ->patch(route('admin.procurements.update-status', $linkedProcurement), [
                'action' => 'partially_received',
                'received_quantity' => 101,
                'return_back' => 1,
            ])
            ->assertRedirect(route('admin.procurements.index'))
            ->assertSessionHasErrors('received_quantity');

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['supplier_id' => $supplier->id]));

        $index->assertOk();
        $index->assertSee('Talebi Aç');
        $index->assertSee($requestRecord->request_number);

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplier->id]));

        $create->assertOk();
        $create->assertDontSee($linkedProcurement->order->document_number);
    }

    public function test_unlinked_procurement_can_open_request_and_unauthorized_or_cross_tenant_actions_are_forbidden(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-QUICK-D');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-QUICK-004');
        $limitedUser = $this->createProductionUser();

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['supplier_id' => $supplier->id]));

        $index->assertOk();
        $index->assertSee('Talep Hazırla');
        $index->assertSee(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplier->id]), false);

        $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'supplier_ordered',
                'return_back' => 1,
            ])
            ->assertForbidden();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Foreign Tenant',
            'legal_name' => 'Foreign Tenant Ltd.',
            'slug' => 'foreign-tenant',
            'panel_subdomain' => 'foreign-tenant',
            'status' => 'active',
        ]);

        $foreignProcurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => Order::query()->create([
                'tenant_account_id' => $otherTenant->id,
                'order_family' => 'promotion',
                'order_mode' => 'product_sale_print',
                'document_type' => 'order',
                'document_number' => 'SP-FOREIGN-001',
                'status' => 'pending',
                'workflow_status' => 'order_created',
                'currency' => 'TL',
            ])->id,
            'order_item_id' => OrderItem::query()->create([
                'tenant_account_id' => $otherTenant->id,
                'order_id' => Order::query()->latest('id')->first()->id,
                'item_type' => 'product',
                'product_name' => 'Foreign Product',
                'product_code' => 'FR-001',
                'quantity' => 10,
                'unit' => 'Adet',
            ])->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 10,
            'remaining_quantity' => 10,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $foreignProcurement), [
                'action' => 'supplier_ordered',
                'return_back' => 1,
            ])
            ->assertForbidden();
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

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'production-quick-user-' . uniqid() . '@prodelya.local',
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
