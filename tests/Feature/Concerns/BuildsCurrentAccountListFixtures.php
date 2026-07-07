<?php

namespace Tests\Feature\Concerns;

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

trait BuildsCurrentAccountListFixtures
{
    use BuildsCompanyDuplicateFixtures;

    private function makeFinanceUser(TenantAccount $tenant, string $email = 'current-account-finance@example.test'): User
    {
        return $this->makeUserWithRoles($tenant, $email, ['tenant_owner', 'finance']);
    }

    private function makeLimitedUser(TenantAccount $tenant, string $email = 'current-account-limited@example.test'): User
    {
        return $this->makeUserWithRoles($tenant, $email, ['delivery']);
    }

    private function makeUserWithRoles(TenantAccount $tenant, string $email, array $roles): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roles as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        return $user;
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

    private function createLinkedAccount(
        TenantAccount $tenant,
        string $displayName,
        array $roles = [CurrentAccountRole::ROLE_CUSTOMER],
        string $status = CurrentAccount::STATUS_ACTIVE
    ): array {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => $status,
            'tax_number' => '1234567890',
            'email' => strtolower(str_replace(' ', '-', $displayName)) . '@example.test',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);

        return [$account->fresh(['roles', 'links', 'primaryCompanyLink']), Company::query()->findOrFail($company->id)];
    }

    private function createUnlinkedAccount(
        TenantAccount $tenant,
        string $displayName,
        array $roles = [CurrentAccountRole::ROLE_SUPPLIER],
        string $status = CurrentAccount::STATUS_ACTIVE
    ): CurrentAccount {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => $status,
            'tax_number' => '1234567890',
            'email' => strtolower(str_replace(' ', '-', $displayName)) . '@example.test',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        return $account->fresh(['roles', 'links', 'primaryCompanyLink']);
    }

    private function createTransaction(
        CurrentAccount $account,
        string $status = CurrentAccountTransaction::STATUS_OPEN,
        ?string $dueDate = null,
        string $type = CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
        string $direction = CurrentAccountTransaction::DIRECTION_DEBIT,
        float $amount = 100
    ): CurrentAccountTransaction {
        return CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => $type,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'status' => $status,
            'description' => 'Cari bakiye liste testi',
        ]);
    }

    private function createOtherTenant(string $slug = 'other-current-account-tenant'): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Other Current Account Tenant',
            'legal_name' => 'Other Current Account Tenant Ltd.',
            'slug' => $slug,
            'panel_subdomain' => $slug,
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }
}
