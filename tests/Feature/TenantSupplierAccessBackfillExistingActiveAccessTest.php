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

class TenantSupplierAccessBackfillExistingActiveAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_existing_active_access_repairs_misaligned_supplier_links_without_duplicate(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Backfill Tenant',
            'legal_name' => 'Backfill Tenant Ltd. Şti.',
            'slug' => 'backfill-tenant',
            'panel_subdomain' => 'backfill-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Etkin Benzeri Tedarikçi',
            'code' => 'ETKIN-HOTFIX-001',
            'status' => 'active',
        ]);

        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $orphanAccount = app(CurrentAccountSyncService::class)->ensureForSupplier($supplier, $tenant);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Etkin Benzeri Tedarikçi',
            'short_name' => 'Etkin Benzeri',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $companyAccount = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));
        $companyCountBefore = Company::query()->where('tenant_account_id', $tenant->id)->count();
        $accountCountBefore = \App\Models\CurrentAccount::query()->where('tenant_account_id', $tenant->id)->count();

        $this->assertNotSame($orphanAccount->id, $companyAccount->id);

        $results = app(TenantSupplierCurrentAccountSyncService::class)->repairActiveAccesses($tenant, true);

        $this->assertCount(1, $results);
        $this->assertSame($companyCountBefore, Company::query()->where('tenant_account_id', $tenant->id)->count());
        $this->assertSame($accountCountBefore, \App\Models\CurrentAccount::query()->where('tenant_account_id', $tenant->id)->count());
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $companyAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $companyAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
        ]);
    }
}
