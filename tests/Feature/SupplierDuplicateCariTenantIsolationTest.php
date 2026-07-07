<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDuplicateCariTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_audit_and_repair_ignore_other_tenant_records(): void
    {
        $tenant = $this->makeTenant('dup-local-tenant', 'Local Tenant');
        $foreignTenant = $this->makeTenant('dup-foreign-tenant', 'Foreign Tenant');
        $supplier = Supplier::query()->create([
            'name' => 'Tenant İzolasyon Tedarikçi',
            'code' => 'TENANT-ISO-001',
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
            'tenant_account_id' => $foreignTenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Tenant İzolasyon Tedarikçi', 'Yerel Ana');
        $duplicate = $this->createSupplierCompany($tenant, 'Tenant İzolasyon Tedarikçi', 'Yerel Duplicate');
        $foreign = $this->createSupplierCompany($foreignTenant, 'Tenant İzolasyon Tedarikçi', 'Yabancı');

        app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));
        $foreignAccount = app(CurrentAccountSyncService::class)->ensureForCompany($foreign->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'local_test'],
        ]);
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'current_account_id' => $foreignAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'foreign_test'],
        ]);

        $audit = app(TenantSupplierCurrentAccountSyncService::class)->auditDuplicateSupplierCaris($tenant, $supplier);
        $this->assertContains($canonical->id, collect($audit['companies'])->pluck('company.id')->all());
        $this->assertContains($duplicate->id, collect($audit['companies'])->pluck('company.id')->all());
        $this->assertNotContains($foreign->id, collect($audit['companies'])->pluck('company.id')->all());

        app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $foreignTenant->id,
            'current_account_id' => $foreignAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    private function makeTenant(string $slug, string $name): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => $name,
            'legal_name' => $name . ' Ltd. Şti.',
            'slug' => $slug,
            'panel_subdomain' => $slug,
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName): Company
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

        return $company;
    }
}
