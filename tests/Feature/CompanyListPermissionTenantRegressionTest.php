<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\TestCase;

class CompanyListPermissionTenantRegressionTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_company_list_keeps_finance_permission_and_tenant_isolation(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $foreignTenant = $this->createCompanyOtherTenant('foreign-company-list-regression');
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'company-list-finance-permission@example.test');
        $limitedUser = $this->makeCompanyLimitedUser($tenant, 'company-list-limited-permission@example.test');

        [$localAccount] = $this->createCompanyLinkedAccount($tenant, 'Yerel Finans Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($localAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$foreignAccount] = $this->createCompanyLinkedAccount($foreignTenant, 'Yabanci Finans Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($foreignAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 700,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'))
            ->assertOk()
            ->assertSee('Yerel Finans Cari')
            ->assertDontSee('Yabanci Finans Cari')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Borç Bakiyesi');

        $this->actingAs($limitedUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'))
            ->assertOk()
            ->assertSee('Yerel Finans Cari')
            ->assertDontSee('Yabanci Finans Cari')
            ->assertDontSee('Güncel Bakiye')
            ->assertDontSee('Bakiye Durumu')
            ->assertDontSee('Açık Hareket')
            ->assertDontSee('Son Hareket');
    }
}
