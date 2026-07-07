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

class CompanyEditSupplierSourceIgnoresInactiveOrOtherTenantLinksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_inactive_same_tenant_and_other_tenant_links_do_not_block_save(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->ownerFor($tenant);
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Diğer Tenant',
            'legal_name' => 'Diğer Tenant Ltd. Şti.',
            'slug' => 'diger-tenant',
            'panel_subdomain' => 'diger-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Yoksayılan Kaynak',
            'code' => 'IGNORE-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $inactiveCompany = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Pasif Cari',
            'status' => 'inactive',
        ]);
        $inactiveCompany->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);
        $inactiveAccount = app(CurrentAccountSyncService::class)->ensureForCompany($inactiveCompany->fresh('companyRoles'));
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $inactiveAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'inactive_test'],
        ]);

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Diğer Tenant Cari',
            'status' => 'active',
        ]);
        $otherCompany->companyRoles()->create([
            'tenant_account_id' => $otherTenant->id,
            'role_key' => 'supplier',
        ]);
        $otherAccount = app(CurrentAccountSyncService::class)->ensureForCompany($otherCompany->fresh('companyRoles'));
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'current_account_id' => $otherAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'other_tenant_test'],
        ]);

        $targetCompany = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Aktif Hedef Cari',
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Ataşehir',
            'status' => 'active',
        ]);
        $targetCompany->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'customer',
        ]);
        app(CurrentAccountSyncService::class)->ensureForCompany($targetCompany->fresh('companyRoles'));

        $this->actingAs($owner, 'web')
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $targetCompany->id), [
                'identity_type' => 'company',
                'legal_name' => $targetCompany->legal_name,
                'phone' => $targetCompany->phone,
                'tax_number' => $targetCompany->tax_number,
                'tax_office' => $targetCompany->tax_office,
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $targetCompany->id));
    }

    private function ownerFor(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Ignored Conflict Owner',
            'email' => 'ignored-conflict-owner@example.test',
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
