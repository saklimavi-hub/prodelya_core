<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\OrderPaymentService;

trait BuildsOrderCustomerDebitFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected TenantAccount $tenant;
    protected User $adminUser;
    protected User $financeUser;
    protected User $limitedUser;

    protected function setUpOrderCustomerDebitFixtures(): void
    {
        $this->tenant = TenantAccount::query()
            ->where('panel_subdomain', 'demo')
            ->first()
            ?? TenantAccount::query()->orderBy('id')->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->financeUser = $this->createTenantUserWithRoles(
            'order-debit-finance@example.test',
            ['tenant_owner', 'finance']
        );
        $this->limitedUser = $this->createTenantUserWithRoles(
            'order-debit-limited@example.test',
            ['production']
        );
    }

    protected function createTenantUserWithRoles(string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => 'Order Debit ' . $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();

            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }

        return $user;
    }

    protected function createCustomerCompany(string $name = 'Order Debit Müşteri'): Company
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

        return $company->fresh('companyRoles');
    }

    protected function createOrder(
        Company $customer,
        string $documentNumber = 'SP-ORDER-DEBIT-001',
        float $grandTotal = 18000,
        array $overrides = []
    ): Order {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-03',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'subtotal' => round($grandTotal / 1.2, 2),
            'vat_total' => round($grandTotal - round($grandTotal / 1.2, 2), 2),
            'grand_total' => $grandTotal,
            'product_total' => round($grandTotal / 2, 2),
            'print_total' => round($grandTotal / 2, 2),
            'created_by' => $this->financeUser->id,
        ], $overrides));

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Order Debit Test Ürün',
            'product_code' => 'ODT-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
            'line_total' => round($grandTotal / 2, 2),
            'print_total' => round($grandTotal / 2, 2),
        ]);

        return $order->fresh(['customer.companyRoles', 'payments']);
    }

    protected function ensureCustomerCurrentAccount(Company $customer): CurrentAccount
    {
        $account = app(CurrentAccountSyncService::class)->ensureForCompany($customer);
        app(CurrentAccountSyncService::class)->ensureRole($account, CurrentAccountRole::ROLE_CUSTOMER);

        return $account->fresh(['roles', 'links']);
    }

    protected function syncOrderDebit(Order $order): ?\App\Models\CurrentAccountTransaction
    {
        return app(OrderCurrentAccountDebitSyncService::class)->syncOrder(
            $order->fresh(['customer.companyRoles', 'payments']),
            $this->financeUser
        );
    }

    protected function createCollectionPayment(Order $order, float $amount): void
    {
        app(OrderPaymentService::class)->createPayment($order, [
            'payment_type' => 'tahsilat',
            'amount' => $amount,
            'currency' => 'TL',
            'payment_method' => 'havale',
            'paid_at' => '2026-07-04 10:00:00',
        ], $this->financeUser);
    }

    protected function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
