<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Priority-1 binding: CompanyController and CurrentAccountController write
 * actions must respect view_customers/create_customers/edit_customers/
 * delete_customers, which were previously defined but never enforced.
 */
class CustomerPermissionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $tenantOwner;
    private User $salesUser;
    private User $productionUser;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill(['package_key' => 'enterprise'])->save();

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'current_accounts', 'feature_key' => 'current_account_cards'],
            ['is_enabled' => true]
        );

        $this->tenantOwner = $this->makeUser('tenant_owner', 'owner-customer-perm@example.test');
        $this->salesUser = $this->makeUser('sales', 'sales-customer-perm@example.test');
        $this->productionUser = $this->makeUser('production', 'production-customer-perm@example.test');
        $this->adminUser = $this->makeUser('admin', 'admin-customer-perm@example.test');
    }

    public function test_companies_index_requires_view_customers_permission(): void
    {
        $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertForbidden();

        $this->actingAs($this->salesUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk();

        $this->actingAs($this->tenantOwner, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk();
    }

    public function test_companies_create_and_store_require_create_customers_permission(): void
    {
        $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertForbidden();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/companies'), $this->companyPayload('Production Blocked Co'))
            ->assertForbidden();
        $this->assertDatabaseMissing('companies', ['legal_name' => 'Production Blocked Co']);

        $this->actingAs($this->salesUser, 'web')
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertOk();

        $this->actingAs($this->salesUser, 'web')
            ->post($this->tenantUrl('/admin/companies'), $this->companyPayload('Sales Allowed Co'))
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['legal_name' => 'Sales Allowed Co', 'tenant_account_id' => $this->tenant->id]);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/companies'), $this->companyPayload('Owner Allowed Co'))
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['legal_name' => 'Owner Allowed Co']);
    }

    public function test_companies_edit_and_update_require_edit_customers_permission(): void
    {
        $company = $this->createCompany('Edit Target Co');

        $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'))
            ->assertForbidden();

        $this->actingAs($this->productionUser, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), $this->companyPayload('Should Not Apply'))
            ->assertForbidden();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'legal_name' => 'Edit Target Co']);

        $this->actingAs($this->salesUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'))
            ->assertOk();

        $this->actingAs($this->salesUser, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), $this->companyPayload('Sales Updated Co'))
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'legal_name' => 'Sales Updated Co']);

        $this->actingAs($this->tenantOwner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), $this->companyPayload('Owner Updated Co'))
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'legal_name' => 'Owner Updated Co']);
    }

    /**
     * NOTE: destroy() soft-deactivates by setting status='passive', but the
     * companies table currently has a pre-existing (unrelated) CHECK constraint
     * that does not allow 'passive', so the controller's own try/catch always
     * redirects back with an error today - even for tenant_owner. That is a
     * separate, already-broken bug outside this task's scope (permission
     * binding only). This test therefore only asserts on the permission gate
     * itself (403 vs. not-403), not on the destroy side effect.
     */
    public function test_companies_destroy_requires_delete_customers_permission(): void
    {
        $companyForSales = $this->createCompany('Sales Cannot Delete Co');

        $this->actingAs($this->salesUser, 'web')
            ->delete($this->tenantUrl('/admin/companies/' . $companyForSales->id))
            ->assertForbidden();

        $companyForAdmin = $this->createCompany('Admin Can Delete Co');
        $this->actingAs($this->adminUser, 'web')
            ->delete($this->tenantUrl('/admin/companies/' . $companyForAdmin->id))
            ->assertStatus(302);

        $companyForOwner = $this->createCompany('Owner Can Delete Co');
        $this->actingAs($this->tenantOwner, 'web')
            ->delete($this->tenantUrl('/admin/companies/' . $companyForOwner->id))
            ->assertStatus(302);
    }

    public function test_current_account_store_requires_create_customers_and_update_status_requires_edit_customers(): void
    {
        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts'), ['display_name' => 'Prod Blocked Account', 'status' => 'active'])
            ->assertForbidden();
        $this->assertDatabaseMissing('current_accounts', ['display_name' => 'Prod Blocked Account']);

        $this->actingAs($this->salesUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts'), ['display_name' => 'Sales Allowed Account', 'status' => 'active'])
            ->assertRedirect();
        $account = CurrentAccount::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('display_name', 'Sales Allowed Account')
            ->firstOrFail();

        $this->actingAs($this->productionUser, 'web')
            ->patch($this->tenantUrl('/admin/current-accounts/' . $account->id . '/status'), ['status' => 'passive'])
            ->assertForbidden();
        $this->assertDatabaseHas('current_accounts', ['id' => $account->id, 'status' => 'active']);

        $this->actingAs($this->salesUser, 'web')
            ->patch($this->tenantUrl('/admin/current-accounts/' . $account->id . '/status'), ['status' => 'passive'])
            ->assertRedirect();
        $this->assertDatabaseHas('current_accounts', ['id' => $account->id, 'status' => 'passive']);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/current-accounts'), ['display_name' => 'Owner Allowed Account', 'status' => 'active'])
            ->assertRedirect();
        $this->assertDatabaseHas('current_accounts', ['display_name' => 'Owner Allowed Account']);
    }

    private function makeUser(string $roleKey, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }

    private function createCompany(string $legalName): Company
    {
        return Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $legalName,
            'status' => 'active',
        ]);
    }

    private function companyPayload(string $legalName): array
    {
        return [
            'identity_type' => 'company',
            'legal_name' => $legalName,
            'tax_number' => '1234567890',
            'status' => 'active',
            'roles' => ['customer'],
        ];
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
