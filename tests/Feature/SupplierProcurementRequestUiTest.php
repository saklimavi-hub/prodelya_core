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
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProcurementRequestUiTest extends TestCase
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

    public function test_procurement_index_renders_dynamic_supplier_groups_and_hides_suppliers_without_open_procurements(): void
    {
        [$supplierA, $sourceA] = $this->createSupplierWithAccess('SPR-UI-A');
        [$supplierB, $sourceB] = $this->createSupplierWithAccess('SPR-UI-B');
        [$supplierNoOpen] = $this->createSupplierWithAccess('SPR-UI-NOOPEN');

        $this->createProcurement($supplierA, $sourceA, 'SP-SPR-UI-001');
        $this->createProcurement($supplierB, $sourceB, 'SP-SPR-UI-002');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Tedarik Yönetimi');
        $response->assertSee('Tedarikçi kartları, açık tedarik ihtiyacı olan siparişlerden otomatik oluşur.');
        $response->assertSee($supplierA->name);
        $response->assertSee($supplierB->name);
        $response->assertSee(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplierA->id]), false);
        $response->assertSee(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplierB->id]), false);
        $response->assertDontSee(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplierNoOpen->id]), false);
        $response->assertSee('Talep Hazırla');
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_create_screen_lists_candidate_procurements_and_store_redirects_to_edit(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-UI-CREATE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-CREATE-001');

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplier->id]));

        $create->assertOk();
        $create->assertSee('Tedarikçi Talebi Hazırla');
        $create->assertSee('Dahil');
        $create->assertSee($procurement->order->document_number);
        $create->assertSee($procurement->workForm->work_form_number);
        $create->assertSee((string) data_get($procurement->snapshot, 'product_code'));
        $create->assertSee((string) data_get($procurement->snapshot, 'product_name'));

        $store = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.store'), [
                'supplier_id' => $supplier->id,
                'procurement_ids' => [$procurement->id],
            ]);

        $requestRecord = SupplierProcurementRequest::query()->latest('id')->firstOrFail();
        $store->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));
    }

    public function test_edit_screen_shows_purchase_columns_for_authorized_user_and_updates_items(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-UI-EDIT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-EDIT-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.store'), [
                'supplier_id' => $supplier->id,
                'procurement_ids' => [$procurement->id],
            ]);

        $requestRecord = SupplierProcurementRequest::query()->latest('id')->firstOrFail()->fresh('items.order', 'items.workForm');

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('Tedarikçi Talebi Düzenle');
        $edit->assertSee('Alış Liste Fiyatı');
        $edit->assertSee('İskonto %');
        $edit->assertSee('Alış Birim Fiyatı');
        $edit->assertSee('Alış Toplam');
        $edit->assertSee('Fiyatsız Talep Formunu Aç');

        $item = $requestRecord->items->first();

        $update = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'note' => 'UI update note',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => 100,
                        'received_quantity' => 0,
                        'purchase_list_price' => 8.50,
                        'discount_rate' => 10,
                        'note' => 'Acil',
                    ],
                ],
            ]);

        $update->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $item = $item->fresh();
        $this->assertSame(7.65, (float) $item->purchase_unit_price);
        $this->assertSame(765.0, (float) $item->purchase_total);
    }

    public function test_edit_screen_hides_purchase_columns_for_unauthorized_user_and_tenant_scope_is_enforced(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-UI-LIMITED');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-LIMITED-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.store'), [
                'supplier_id' => $supplier->id,
                'procurement_ids' => [$procurement->id],
            ]);

        $requestRecord = SupplierProcurementRequest::query()->latest('id')->firstOrFail();
        $limitedUser = $this->createProductionUser();

        $response = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('Alış Birim Fiyatı');
        $response->assertDontSee('Alış Toplam');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant',
            'panel_subdomain' => 'other-tenant',
            'status' => 'active',
        ]);

        $foreignRequest = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'request_number' => 'TS-2026-9999',
            'request_date' => '2026-06-13',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $foreignRequest))
            ->assertForbidden();
    }

    public function test_linked_procurement_does_not_return_to_candidate_list_and_print_view_stays_priceless(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-UI-LINKED');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-LINKED-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.store'), [
                'supplier_id' => $supplier->id,
                'procurement_ids' => [$procurement->id],
            ]);

        $requestRecord = SupplierProcurementRequest::query()->latest('id')->firstOrFail();

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', ['supplier_id' => $supplier->id]));

        $create->assertOk();
        $create->assertDontSee($procurement->order->document_number);

        $print = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $print->assertOk();
        $print->assertSee('TEDARİKÇİ TALEP FORMU');
        $print->assertSee($procurement->order->document_number);
        $print->assertSee((string) data_get($procurement->snapshot, 'product_code'));
        $print->assertSee((string) data_get($procurement->snapshot, 'product_name'));
        $print->assertDontSee('Alış Liste Fiyatı');
        $print->assertDontSee('İskonto');
        $print->assertDontSee('group_code', false);
        $print->assertDontSee('raw_mapping', false);
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

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'production-user-' . uniqid() . '@prodelya.local',
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
