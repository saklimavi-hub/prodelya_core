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

class SupplierDuplicateCariRepairMovesLinksOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_repair_moves_supplier_links_to_canonical_account_only(): void
    {
        $tenant = $this->makeTenant('repair-links-tenant');
        $supplier = Supplier::query()->create([
            'name' => 'Link Taşıma Tedarikçisi',
            'code' => 'SUP-DUP-REPAIR-001',
            'status' => 'active',
        ]);
        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Link Taşıma Tedarikçisi', 'Ana Cari');
        $duplicate = $this->createSupplierCompany($tenant, 'Link Taşıma Tedarikçisi', 'Yanlış Cari');

        $canonicalAccount = app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => false,
            'meta_json' => ['supplier_id' => $supplier->id],
        ]);

        $report = app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->assertTrue($report['performed']);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
        ]);
        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    private function makeTenant(string $slug): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Repair Links Tenant',
            'legal_name' => 'Repair Links Tenant Ltd. Şti.',
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
