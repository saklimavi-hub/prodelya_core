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
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSupplierCariPermissionIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $owner;
    private User $foreignOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Supplier Cari Tenant',
            'legal_name' => 'Foreign Supplier Cari Tenant Ltd.',
            'slug' => 'foreign-supplier-cari-tenant',
            'panel_subdomain' => 'foreign-supplier-cari-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->owner = $this->createTenantOwner($this->tenant, 'local-supplier-cari-owner@example.test');
        $this->foreignOwner = $this->createTenantOwner($this->foreignTenant, 'foreign-supplier-cari-owner@example.test');

        foreach ([$this->tenant, $this->foreignTenant] as $tenant) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenant->id,
                    'module_key' => 'current_accounts',
                    'feature_key' => null,
                ],
                ['is_enabled' => true]
            );
        }
    }

    public function test_foreign_tenant_user_cannot_edit_other_tenant_cari(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Yerel Cari',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->foreignOwner, 'web')
            ->put($this->tenantUrl($this->tenant, '/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => 'Yabancı Deneme',
                'status' => 'active',
                'roles' => ['supplier'],
            ]);

        $response->assertForbidden();
    }

    public function test_supplier_sync_stays_in_tenant_scope_and_never_links_foreign_company(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Ortak Tedarikçi',
            'code' => 'SHARED-SUP-001',
            'status' => 'active',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'legal_name' => 'Ortak Tedarikçi',
            'status' => 'active',
        ]);
        $foreignCompany->companyRoles()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'role_key' => 'customer',
        ]);
        app(CurrentAccountSyncService::class)->ensureForCompany($foreignCompany->fresh('companyRoles'));

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $result = app(TenantSupplierCurrentAccountSyncService::class)->syncForTenantSupplierAccess($this->tenant, $supplier);

        $this->assertSame($this->tenant->id, $result['company']->tenant_account_id);
        $this->assertNotSame($foreignCompany->tenant_account_id, $result['company']->tenant_account_id);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $result['current_account']->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->foreignTenant->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    private function createTenantOwner(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        return $user;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
