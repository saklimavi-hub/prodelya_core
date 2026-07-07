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

class ProcurementDetailSimplificationTest extends TestCase
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

    public function test_procurement_detail_is_simplified_and_hides_old_section_labels_and_prices(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-DETAIL-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-DETAIL-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));

        $response->assertOk();
        $response->assertSee('Tedarik Sekmeleri');
        $response->assertSee('Genel Özet');
        $response->assertSee('Ürün ve Sipariş');
        $response->assertSee('Tedarikçi ve Cari');
        $response->assertSee('Talep / Form');
        $response->assertSee('İşlemler');
        $response->assertSee('Gelen / Miktar');
        $response->assertSee('Geçmiş');
        $response->assertDontSee('Tedarik Özeti');
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_procurement_detail_shows_link_to_existing_supplier_request_and_actions_work(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-DETAIL-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-DETAIL-002');
        $requestRecord = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'talep']));

        $show->assertOk();
        $show->assertSee($requestRecord->request_number);
        $show->assertSee(route('admin.procurements.supplier-requests.edit', $requestRecord), false);
        $show->assertSee('Talebi Düzenle');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => '15',
                'note' => 'Parça teslim',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $procurement = $procurement->fresh();
        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $procurement->procurement_status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
                'note' => 'Tamamı geldi',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->fresh()->procurement_status);
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
            'quantity' => 60,
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
                'line_total' => 1200,
                'vat_total' => 240,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 25,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.supplierRequestItems.request'])->procurement;
    }
}
