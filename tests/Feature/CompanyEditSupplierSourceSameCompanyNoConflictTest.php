<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEditSupplierSourceSameCompanyNoConflictTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_same_company_can_keep_its_existing_supplier_source_without_conflict(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->ownerFor($tenant);
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Aynı Cari Tedarikçisi',
            'code' => 'SAME-COMPANY-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Aynı Cari Tedarikçisi',
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Şişli',
            'status' => 'active',
        ]);
        $company->companyRoles()->createMany([
            ['tenant_account_id' => $tenant->id, 'role_key' => 'customer'],
            ['tenant_account_id' => $tenant->id, 'role_key' => 'supplier'],
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);

        $this->actingAs($owner, 'web')
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'phone' => $company->phone,
                'tax_number' => $company->tax_number,
                'tax_office' => $company->tax_office,
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $company->id));
    }

    private function ownerFor(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Same Company Conflict Owner',
            'email' => 'same-company-conflict-owner@example.test',
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

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
