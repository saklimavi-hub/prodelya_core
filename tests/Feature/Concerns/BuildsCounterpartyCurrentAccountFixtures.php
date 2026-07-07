<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\WorkFormCreationService;

trait BuildsCounterpartyCurrentAccountFixtures
{
    protected TenantAccount $tenant;
    protected User $adminUser;
    protected User $financeUser;
    protected User $limitedUser;
    protected Company $customer;

    protected function setUpCounterpartyFixtures(): void
    {
        $this->tenant = TenantAccount::query()
            ->where('panel_subdomain', 'demo')
            ->first()
            ?? TenantAccount::query()->orderBy('id')->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->financeUser = $this->createUserWithRole('finance', 'counterparty-finance');
        $this->limitedUser = $this->createUserWithRole('production', 'counterparty-production');
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->first()
            ?? $this->createCustomerCompany('ABC İnşaat A.Ş.');
    }

    protected function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Counterparty User',
            'email' => $emailPrefix . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        if ($roleKey === 'finance') {
            $tenantOwner = Role::query()->where('key', 'tenant_owner')->firstOrFail();
            $user->userRoles()->create([
                'role_id' => $tenantOwner->id,
                'tenant_account_id' => $this->tenant->id,
            ]);
        }

        return $user;
    }

    protected function createCustomerCompany(string $name): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $name,
            'status' => 'active',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        return $company;
    }

    protected function createOrder(string $documentNumber, ?Company $customer = null): Order
    {
        $customer ??= $this->customer;

        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
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

    protected function createSupplierProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber, ?Order $order = null): OrderItemProcurement
    {
        $order ??= $this->createOrder($orderNumber);

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

    protected function createPartnerCompany(string $name = 'Subcontractor Partner'): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $name,
            'short_name' => $name,
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company->fresh('companyRoles');
    }

    protected function createProduction(string $orderNumber, Company $partner, string $productionType, ?Order $order = null): OrderItemPrintProduction
    {
        $order ??= $this->createOrder($orderNumber);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Subcontractor Test Product',
            'product_code' => 'SUB-TX-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'production_type' => $productionType === OrderItemPrintProduction::TYPE_INTERNAL ? 'İç üretim' : 'Dış üretim / Fason',
            'subcontractor_company_id' => $partner->id,
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'cliche_status' => 'bekleniyor',
            'status' => 'draft',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $freshPrint = $print->fresh(['production', 'orderItem.workForm']);

        if (!$freshPrint->production) {
            app(\App\Services\ProductionCreationService::class)->createForOrderItemPrint(
                $freshPrint,
                $freshPrint->orderItem?->workForm,
                $this->adminUser
            );
            $freshPrint = $print->fresh(['production.workForm', 'production.orderItemPrint', 'production.orderItem']);
        }

        return $freshPrint->production->fresh([
            'workForm',
            'order',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]);
    }

    protected function createCurrentAccountWithRole(string $displayName, string $role): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName,
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [$role]);

        return $account->fresh(['roles']);
    }
}
