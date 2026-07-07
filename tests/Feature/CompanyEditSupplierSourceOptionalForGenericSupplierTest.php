<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEditSupplierSourceOptionalForGenericSupplierTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_supplier_role_can_be_saved_without_supplier_source_for_generic_supplier(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Generic Supplier Role Owner',
            'email' => 'generic-supplier-role-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Genel Tedarikçi Cari',
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Kadıköy',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'customer',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        $this->actingAs($owner, 'web')
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'phone' => $company->phone,
                'tax_number' => $company->tax_number,
                'tax_office' => $company->tax_office,
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
            ])
            ->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $company->id));

        $this->assertTrue($company->fresh('companyRoles')->hasRole('supplier'));
        $this->assertTrue($account->fresh('roles')->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
