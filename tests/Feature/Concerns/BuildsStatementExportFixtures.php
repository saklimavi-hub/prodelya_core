<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;

trait BuildsStatementExportFixtures
{
    private function makeFinanceUser(TenantAccount $tenant, string $email): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Statement Export Finance User',
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

    private function makeLimitedUser(TenantAccount $tenant, string $email, string $roleKey = 'delivery'): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => 'Statement Export Limited User',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function createLinkedCompanyAccount(TenantAccount $tenant, string $displayName, array $roles = [CurrentAccountRole::ROLE_CUSTOMER]): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'account_code' => 'CR-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
            'email' => 'finance@example.test',
            'phone' => '02120000000',
            'mobile' => '05320000000',
            'tax_number' => '1234567890',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);
        $companyModel = Company::query()->findOrFail($company->id);
        $companyModel->update([
            'email' => 'cari@example.test',
            'phone' => '02120000000',
            'mobile' => '05320000000',
            'tax_number' => '1234567890',
        ]);

        $companyModel->addresses()->create([
            'tenant_account_id' => $tenant->id,
            'address_type' => 'billing',
            'title' => 'Fatura Adresi',
            'country' => 'Türkiye',
            'city' => 'İstanbul',
            'district' => 'Ümraniye',
            'address' => 'Test Mahallesi No:10',
            'postal_code' => '34760',
            'is_default' => true,
        ]);

        return [$account->fresh(['roles', 'primaryCompanyLink']), $companyModel->fresh()];
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

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.prodelya_core.test' . $path;
    }
}
