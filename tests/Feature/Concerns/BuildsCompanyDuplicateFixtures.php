<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;

trait BuildsCompanyDuplicateFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    private function makeTenantOwner(TenantAccount $tenant, string $email = 'duplicate-owner@example.test'): User
    {
        $owner = User::query()->create([
            'name' => 'Duplicate Owner',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        return $owner;
    }

    private function createSupplierDuplicateFixture(TenantAccount $tenant, string $supplierName = 'Etkin Promosyon'): array
    {
        $supplier = Supplier::query()->create([
            'name' => $supplierName,
            'code' => 'SUP-' . strtoupper(substr(md5($supplierName . microtime(true)), 0, 8)),
            'status' => 'active',
        ]);

        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        [$canonicalAccount, $canonicalCompany] = $this->createSupplierCompany($tenant, $supplierName, 'Ana Cari');
        [$duplicateAccount, $duplicateCompany] = $this->createSupplierCompany($tenant, $supplierName, 'Benzer Cari');

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
        ]);

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => true,
        ]);

        return [
            'supplier' => $supplier,
            'access' => $access,
            'canonical_company' => $canonicalCompany,
            'canonical_account' => $canonicalAccount,
            'duplicate_company' => $duplicateCompany,
            'duplicate_account' => $duplicateAccount,
        ];
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName): array
    {
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'short_name' => $shortName,
            'status' => 'active',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        return [$account->fresh(), $company->fresh()];
    }

    private function createFinancialHistory(CurrentAccount $account, float $amount = 100): void
    {
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => 'manual',
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => $amount,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Duplicate archive test hareketi',
        ]);
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
