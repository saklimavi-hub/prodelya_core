<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEditCustomerToSupplierRoleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Customer To Supplier Owner',
            'email' => 'customer-to-supplier-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_customer_cari_can_gain_supplier_role_without_duplicate(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Rol Geçiş Tedarikçisi',
            'code' => 'ROLE-SUP-001',
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Rol Geçiş Kaynağı',
            'url' => 'https://example.test/role-sup',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Rol Geçiş Cari',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));
        $beforeCompanyCount = Company::query()->where('tenant_account_id', $this->tenant->id)->count();

        $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $company = $company->fresh('companyRoles');
        $account = $account->fresh('roles');

        $this->assertSame($beforeCompanyCount, Company::query()->where('tenant_account_id', $this->tenant->id)->count());
        $this->assertTrue($company->hasRole('customer'));
        $this->assertTrue($company->hasRole('supplier'));
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_CUSTOMER));
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
