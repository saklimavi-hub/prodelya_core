<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;

trait BuildsCompanyFinanceListFixtures
{
    private function makeCompanyFinanceUser(TenantAccount $tenant, string $email = 'company-list-finance@example.test'): User
    {
        return $this->makeCompanyUserWithRoles($tenant, $email, ['tenant_owner', 'finance']);
    }

    private function makeCompanyLimitedUser(TenantAccount $tenant, string $email = 'company-list-limited@example.test'): User
    {
        return $this->makeCompanyUserWithRoles($tenant, $email, ['delivery']);
    }

    private function makeCompanyUserWithRoles(TenantAccount $tenant, string $email, array $roles): User
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

    private function createCompanyLinkedAccount(
        TenantAccount $tenant,
        string $displayName,
        array $roles = [CurrentAccountRole::ROLE_CUSTOMER],
        array $accountAttributes = [],
        array $companyAttributes = []
    ): array {
        $account = CurrentAccount::query()->create(array_merge([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
            'email' => strtolower(str_replace(' ', '-', $displayName)) . '@example.test',
        ], $accountAttributes));

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);

        $company = Company::query()->findOrFail($company->id);
        $company->forceFill(array_merge([
            'phone' => $company->phone,
            'mobile' => $company->mobile,
            'email' => $company->email,
        ], $companyAttributes))->save();

        return [$account->fresh(['roles', 'links']), $company->fresh(['companyRoles', 'contacts', 'addresses'])];
    }

    private function createCompanyListTransaction(CurrentAccount $account, array $attributes): CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()->create(array_merge([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_OTHER,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 0,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Cari liste para testi',
        ], $attributes));
    }

    private function createStandaloneCompany(TenantAccount $tenant, string $name, array $attributes = []): Company
    {
        return Company::query()->create(array_merge([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $name,
            'status' => 'active',
        ], $attributes));
    }

    private function createCompanyOtherTenant(string $slug = 'other-company-list-tenant'): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Other Company List Tenant',
            'legal_name' => 'Other Company List Tenant Ltd.',
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

    private function companyTenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.prodelya_core.test' . $path;
    }
}
