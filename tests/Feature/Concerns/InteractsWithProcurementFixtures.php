<?php

namespace Tests\Feature\Concerns;

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

trait InteractsWithProcurementFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUpProcurementFixtures(): void
    {
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    protected function createSupplierWithAccess(string $code): array
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

    protected function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber, array $overrides = []): OrderItemProcurement
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

        $item = OrderItem::query()->create(array_merge([
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
        ], $overrides));

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order.customer', 'procurement.supplierRequestItems.request'])->procurement;
    }

    protected function createSupplierRequest(OrderItemProcurement $procurement): SupplierProcurementRequest
    {
        return app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $procurement->supplier_id,
            [$procurement->id],
            $this->adminUser
        );
    }

    protected function createProductionUser(): User
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
