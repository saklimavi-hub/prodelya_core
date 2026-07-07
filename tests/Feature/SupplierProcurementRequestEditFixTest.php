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

class SupplierProcurementRequestEditFixTest extends TestCase
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

    public function test_edit_screen_prefills_purchase_list_price_from_supplier_snapshot_when_available(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-EDIT-FIX-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-FIX-001', true);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('value="9.20"', false);
        $response->assertSee('data-spr-list-price', false);
    }

    public function test_edit_screen_shows_zero_and_warning_when_purchase_list_price_is_missing(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-EDIT-FIX-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-FIX-002', false);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('value="0.00"', false);
        $response->assertSee('Liste fiyatı bulunamadı');
    }

    public function test_talebi_kaydet_updates_items_and_turns_draft_into_requested(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-EDIT-FIX-C');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-FIX-003', true);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'request',
                'note' => 'Gönderim notu',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => 100,
                        'purchase_list_price' => 9.20,
                        'discount_rate' => 45,
                        'note' => 'Acil tedarik',
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh('items.procurement.workForm.activityLogs');
        $item = $requestRecord->items->first();
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertSame(SupplierProcurementRequest::STATUS_REQUESTED, $requestRecord->status);
        $this->assertSame(9.20, (float) $item->purchase_list_price);
        $this->assertSame(5.06, (float) $item->purchase_unit_price);
        $this->assertSame(506.0, (float) $item->purchase_total);
        $this->assertSame(OrderItemProcurement::STATUS_REQUEST_CREATED, $procurement->procurement_status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_request_created'));
    }

    public function test_taslak_kaydet_keeps_status_draft_and_main_button_replaces_old_mark_requested_cta(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-EDIT-FIX-D');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-FIX-004', true);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $show->assertOk();
        $show->assertSee('Kaydet');
        $show->assertSee('Taslak Olarak Kaydet');
        $show->assertDontSee('Talep Edildi İşaretle');
        $show->assertSee('Fiyatsız Talep Formunu Aç');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'draft',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => 100,
                        'purchase_list_price' => 9.20,
                        'discount_rate' => 10,
                        'note' => 'Taslak notu',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $requestRecord = $requestRecord->fresh();
        $this->assertSame(SupplierProcurementRequest::STATUS_DRAFT, $requestRecord->status);
    }

    public function test_unauthorized_user_does_not_see_purchase_columns(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-EDIT-FIX-E');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-FIX-005', true);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $limitedUser = $this->createProductionUser();

        $response = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertDontSee('Alış Liste Fiyatı');
        $response->assertDontSee('Alış Toplam');
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

    private function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber, bool $withPurchaseListPrice): OrderItemProcurement
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
            'email' => 'production-edit-fix-' . uniqid() . '@prodelya.local',
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
