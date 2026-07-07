<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountManualTransactionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $financeUser;
    private User $foreignFinanceUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Manual Transaction Foreign',
            'legal_name' => 'Manual Transaction Foreign Ltd.',
            'slug' => 'manual-transaction-foreign',
            'panel_subdomain' => 'manual-transaction-foreign',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->financeUser = $this->createFinanceUser($this->tenant, 'manual-tenant-finance@example.test');
        $this->foreignFinanceUser = $this->createFinanceUser($this->foreignTenant, 'manual-tenant-foreign-finance@example.test');
    }

    public function test_manual_transaction_store_stays_tenant_scoped_for_accounts_and_orders(): void
    {
        $localAccount = $this->createAccount($this->tenant, 'Tenant Scoped Local');
        $foreignAccount = $this->createAccount($this->foreignTenant, 'Tenant Scoped Foreign');
        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FOREIGN-1001',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->foreignFinanceUser->id,
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $foreignAccount->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 100,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->from($this->tenantUrl('/admin/current-accounts/' . $localAccount->id . '/transactions'))
            ->post($this->tenantUrl('/admin/current-accounts/' . $localAccount->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 100,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'order_id' => $foreignOrder->id,
            ])
            ->assertSessionHasErrors(['order_id']);
    }

    private function createFinanceUser(TenantAccount $tenant, string $email): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Manual Tenant Finance',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $financeRole->id,
        ]);

        return $user;
    }

    private function createAccount(TenantAccount $tenant, string $displayName): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
