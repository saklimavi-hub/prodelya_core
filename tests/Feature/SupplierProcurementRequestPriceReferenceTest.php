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

class SupplierProcurementRequestPriceReferenceTest extends TestCase
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

    public function test_edit_screen_prefills_supplier_purchase_price_and_shows_sales_reference_for_authorized_user(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-001', true, 20.00, 2000.00);
        $procurement->orderItem->update(['discount_rate' => 45]);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('value="9.20"', false);
        $response->assertSee('value="0.00"', false);
        $response->assertDontSee('value="45.00"', false);
        $response->assertSee('Satış Ref: 20,00 TL / adet');
        $response->assertSee('Satış Toplam: 2.000,00 TL');
        $response->assertSee('data-sales-unit-price="20.00"', false);
        $response->assertSee('data-sales-total="2000.00"', false);
    }

    public function test_edit_screen_can_use_product_detail_supplier_price_snapshot_as_purchase_list_price_source(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-SOURCE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-006', false, 20.00, 2000.00, [
            'list_price' => 11.40,
        ], [
            'list_price' => 11.40,
        ]);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('value="11.40"', false);
        $response->assertSee('data-spr-list-price', false);
    }

    public function test_missing_purchase_price_shows_zero_warning_and_missing_sales_reference_when_needed(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-002', false, null, null);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('value="0.00"', false);
        $response->assertSee('Liste fiyatı bulunamadı; özel alış fiyatı girin');
        $response->assertSee('Satış fiyatı referansı bulunamadı');
        $response->assertSee('data-spr-warning-list', false);
    }

    public function test_manual_purchase_prices_are_saved_preserved_and_warn_when_above_sales_reference(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-C');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-003', false, 6.50, 650.00);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'request',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => '100',
                        'purchase_list_price' => '0,00',
                        'discount_rate' => '',
                        'purchase_unit_price' => '7,10',
                        'note' => 'Özel alış fiyatı',
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement');
        $item = $requestRecord->items->first();

        $this->assertSame(SupplierProcurementRequest::STATUS_REQUESTED, $requestRecord->status);
        $this->assertSame(0.0, (float) $item->purchase_list_price);
        $this->assertSame(7.10, (float) $item->purchase_unit_price);
        $this->assertSame(710.0, (float) $item->purchase_total);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('value="7.10"', false);
        $edit->assertSee('Alış fiyatı satış fiyatını aşıyor');
        $edit->assertSee('Alış toplamı satış toplamını aşıyor');
        $edit->assertSee('data-manual-unit-price="1"', false);
        $edit->assertSee('data-spr-manual-badge', false);
    }

    public function test_saved_purchase_discount_is_preserved_and_discount_based_calculation_is_used_when_unit_price_is_blank(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-D');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-004', true, 20.00, 2000.00);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'draft',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => '100',
                        'purchase_list_price' => '10,00',
                        'discount_rate' => '20',
                        'purchase_unit_price' => '',
                        'note' => 'İskontolu fiyat',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $item = $item->fresh();
        $requestRecord = $requestRecord->fresh();
        $this->assertSame(8.0, (float) $item->purchase_unit_price);
        $this->assertSame(800.0, (float) $item->purchase_total);
        $this->assertSame(20.0, (float) $item->discount_rate);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('value="20.00"', false);
        $edit->assertSee('data-spr-row', false);
        $edit->assertSee('data-spr-list-price', false);
        $edit->assertSee('data-spr-discount', false);
        $edit->assertSee('data-spr-unit-price', false);
        $edit->assertSee('data-spr-total-output', false);
        $edit->assertSee('data-spr-warning-list', false);
    }

    public function test_near_sales_price_warning_is_shown_when_purchase_price_is_close_to_sales_reference(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-CLOSE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-007', false, 10.00, 1000.00);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'draft',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => '100',
                        'purchase_list_price' => '10,00',
                        'discount_rate' => '5',
                        'purchase_unit_price' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('Alış fiyatı satış fiyatına çok yakın');
    }

    public function test_unauthorized_user_does_not_see_purchase_price_or_sales_reference_and_print_stays_priceless(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-E');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-005', true, 20.00, 2000.00);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $limitedUser = $this->createProductionUser();

        $response = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('Satış Ref');
        $response->assertDontSee('Satış Toplam');

        $print = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $print->assertOk();
        $print->assertDontSee('Alış Liste Fiyatı');
        $print->assertDontSee('Satış Ref');
        $print->assertDontSee('Satış Toplam');
        $print->assertDontSee('KDV');
        $print->assertDontSee('group_code', false);
        $print->assertDontSee('raw_mapping', false);
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

    private function createProcurement(
        Supplier $supplier,
        SupplierSource $source,
        string $orderNumber,
        bool $withPurchaseListPrice,
        ?float $salesUnitPrice,
        ?float $salesTotal,
        array $productSnapshotOverrides = [],
        array $itemPriceSnapshotOverrides = []
    ): OrderItemProcurement {
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

        $productSnapshot = [
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_name' => $supplier->name,
            'warning_badges' => [],
            'group_code' => 'HIDDEN-GROUP',
            'raw_mapping' => ['secret' => 'hidden'],
        ];

        if ($withPurchaseListPrice) {
            $productSnapshot['price_snapshot'] = ['list_price' => 9.20];
        }

        $productSnapshot = array_merge($productSnapshot, $productSnapshotOverrides);

        $priceSnapshot = [];

        if ($salesUnitPrice !== null) {
            $priceSnapshot['unit_price'] = $salesUnitPrice;
        }

        if ($salesTotal !== null) {
            $priceSnapshot['line_total'] = $salesTotal;
        }

        $priceSnapshot = array_merge($priceSnapshot, $itemPriceSnapshotOverrides);

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
            'product_snapshot' => $productSnapshot,
            'price_snapshot' => $priceSnapshot,
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => $salesUnitPrice,
            'line_total' => $salesTotal,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.orderItem'])->procurement;
    }

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'production-price-user-' . uniqid() . '@prodelya.local',
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
