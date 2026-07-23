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

class SupplierProcurementRequestPrintFormTest extends TestCase
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

    public function test_authorized_user_can_open_priceless_a4_print_form_and_edit_link_points_to_real_route(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRINT-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRINT-001');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id])->fresh('items');
        $requestDate = optional($requestRecord->request_date)->format('d.m.Y');

        $requestRecord->items->first()->update([
            'purchase_list_price' => 12.50,
            'discount_rate' => 25,
            'purchase_unit_price' => 9.38,
            'purchase_total' => 938.00,
            'note' => 'Acil',
        ]);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee(route('admin.procurements.supplier-requests.print', $requestRecord), false);
        $edit->assertSee('target="_blank"', false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $response->assertOk();
        $response->assertSee('TEDARİKÇİ TALEP FORMU');
        $response->assertSee('Yazdır');
        $response->assertSee('Talebi Düzenle');
        $response->assertSee('Listeye Dön');
        $response->assertSee('print-toolbar', false);
        $response->assertSee('no-print', false);
        $response->assertSee($supplier->name);
        $response->assertSee($requestRecord->request_number);
        $response->assertSee($requestDate);
        $response->assertSee($procurement->order->document_number);
        $response->assertSee((string) data_get($procurement->snapshot, 'product_code'));
        $response->assertSee((string) data_get($procurement->snapshot, 'product_name'));
        $response->assertSee('Acil');
        $response->assertSee('100,00');
        $response->assertSee('Adet');
        $response->assertSee('Toplam: 100,00 Adet');
        $response->assertSee('Tedarikçi Yetkilisi');
        $response->assertSee('Firma Yetkilisi');
        $response->assertSee('Hazırlayan:');
        $response->assertSee($this->adminUser->name);

        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('İskonto');
        $response->assertDontSee('Alış Birim Fiyatı');
        $response->assertDontSee('Alış Toplam');
        $response->assertDontSee('Satış Ref');
        $response->assertDontSee('Satış Toplam');
        $response->assertDontSee('purchase_list_price', false);
        $response->assertDontSee('purchase_unit_price', false);
        $response->assertDontSee('purchase_total', false);
        $response->assertDontSee('discount_rate', false);
        $response->assertDontSee('sales_unit_price', false);
        $response->assertDontSee('sales_total', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
    }

    public function test_print_form_enforces_tenant_scope_and_permission(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRINT-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRINT-002');
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $limitedUser = $this->createProductionUser();

        $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord))
            ->assertForbidden();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant Print',
            'legal_name' => 'Other Tenant Print Ltd.',
            'slug' => 'other-tenant-print',
            'panel_subdomain' => 'other-tenant-print',
            'status' => 'active',
        ]);

        $foreignRequest = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'request_number' => 'TS-2026-8888',
            'request_date' => '2026-06-13',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $foreignRequest))
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
            'contact_email' => 'tedarik+' . strtolower($code) . '@example.test',
            'contact_phone' => '0212 555 00 00',
            'status' => 'active',
            'config' => [
                'contact_name' => 'Satın Alma Yetkilisi',
            ],
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
                'group_code' => 'SECRET-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
                'price_snapshot' => ['list_price' => 9.20],
                'file_path' => 'secret/file/path',
                'physical_path' => 'secret/physical/path',
            ],
            'price_snapshot' => [
                'unit_price' => 15,
                'line_total' => 1500,
                'vat_total' => 300,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 18,
            'discount_rate' => 5,
            'unit_price' => 15,
            'line_total' => 1500,
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
            'name' => 'Production Print User',
            'email' => 'production-print-user-' . uniqid() . '@prodelya.local',
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
