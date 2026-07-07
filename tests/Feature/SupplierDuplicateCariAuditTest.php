<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDuplicateCariAuditTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_supplier_cari_audit_identifies_candidates_and_prefers_financial_history(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Duplicate Audit Tenant',
            'legal_name' => 'Duplicate Audit Tenant Ltd. Şti.',
            'slug' => 'duplicate-audit-tenant',
            'panel_subdomain' => 'duplicate-audit-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Etkin Benzeri Tedarikçi',
            'code' => 'SUP-DUP-AUDIT-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Etkin Benzeri Tedarikçi', 'Etkin Benzeri');
        $duplicate = $this->createSupplierCompany($tenant, 'Etkin Benzeri Tedarikçi', 'Etkin Tekrar');

        $canonicalAccount = app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => 'manual',
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1500,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Audit önceliği testi',
        ]);

        $audit = app(TenantSupplierCurrentAccountSyncService::class)->auditDuplicateSupplierCaris($tenant, $supplier);

        $this->assertCount(2, $audit['companies']);
        $this->assertSame($canonical->id, data_get($audit, 'canonical_candidate.company.id'));
        $this->assertSame($duplicate->id, data_get($audit, 'duplicate_candidates.0.company.id'));
        $this->assertContains('Finans hareketi olan cari önceliklendirildi.', data_get($audit, 'canonical_candidate.selection_reasons'));
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
