<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDuplicateCariRepairDoesNotDeleteFinancialHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_repair_is_blocked_when_duplicate_has_financial_history(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Repair Risk Tenant',
            'legal_name' => 'Repair Risk Tenant Ltd. Şti.',
            'slug' => 'repair-risk-tenant',
            'panel_subdomain' => 'repair-risk-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Riskli Duplicate Tedarikçi',
            'code' => 'SUP-DUP-RISK-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Riskli Duplicate Tedarikçi', 'Ana');
        $duplicate = $this->createSupplierCompany($tenant, 'Riskli Duplicate Tedarikçi', 'Dolu Duplicate');

        app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);

        $transaction = CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => 'manual',
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 2500,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Risk testi',
        ]);

        $report = app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->assertFalse($report['performed']);
        $this->assertTrue($report['blocked_by_risk']);
        $this->assertDatabaseHas('current_account_transactions', [
            'id' => $transaction->id,
            'current_account_id' => $duplicateAccount->id,
        ]);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
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
