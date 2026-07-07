<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyStatementTenantIsolationTest extends TestCase
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
            'name' => 'Statement Isolation Foreign',
            'legal_name' => 'Statement Isolation Foreign Ltd.',
            'slug' => 'statement-isolation-foreign',
            'panel_subdomain' => 'statement-isolation-foreign',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $this->financeUser = $this->createFinanceUser($this->tenant, 'statement-isolation-finance@example.test');
        $this->foreignFinanceUser = $this->createFinanceUser($this->foreignTenant, 'statement-isolation-foreign-finance@example.test');
        $this->enableCurrentAccounts($this->tenant);
        $this->enableCurrentAccounts($this->foreignTenant);
    }

    public function test_company_statement_and_account_statement_stay_tenant_scoped(): void
    {
        [$localAccount, $localCompany] = $this->createLinkedCompanyAccount($this->tenant, 'Local Statement Cari');
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $localAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 400,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Yerel statement hareketi',
        ]);

        [$foreignAccount, $foreignCompany] = $this->createLinkedCompanyAccount($this->foreignTenant, 'Foreign Statement Cari');
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'current_account_id' => $foreignAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 9000,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Yabanci statement hareketi',
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $localCompany->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Yerel statement hareketi')
            ->assertDontSee('Yabanci statement hareketi')
            ->assertDontSee($foreignCompany->legal_name)
            ->assertDontSee('9.000,00 TL');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $foreignAccount->id . '/transactions'))
            ->assertForbidden();

        $this->actingAs($this->foreignFinanceUser, 'web')
            ->get($this->foreignTenantUrl('/admin/current-accounts/' . $localAccount->id . '/transactions'))
            ->assertForbidden();
    }

    private function createFinanceUser(TenantAccount $tenant, string $email): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Statement Isolation Finance User',
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

    private function createLinkedCompanyAccount(TenantAccount $tenant, string $displayName): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function enableCurrentAccounts(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }

    private function foreignTenantUrl(string $path): string
    {
        return 'http://' . $this->foreignTenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
