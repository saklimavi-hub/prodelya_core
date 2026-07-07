<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class SubcontractorProductionCurrentAccountTenantIsolationTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_foreign_tenant_subcontractor_company_cannot_be_used_for_local_production_payable(): void
    {
        $partner = $this->createPartnerCompany('Yerel Fason');
        $production = $this->createProduction('SP-SUB-HARD-002', $partner, OrderItemPrintProduction::TYPE_OUTSOURCED);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Production Tenant',
            'legal_name' => 'Foreign Production Tenant Ltd.',
            'slug' => 'foreign-production-hardening',
            'panel_subdomain' => 'foreign-production-hardening',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Fason Company',
            'status' => 'active',
        ]);
        CompanyRole::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'role_key' => 'print_fason',
        ]);

        $production->forceFill([
            'production_company_id' => $foreignCompany->id,
            'subcontractor_cost' => 250,
            'subcontractor_cost_currency' => 'TL',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $result = app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertNull($result);
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $production->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
        ]);
    }
}
