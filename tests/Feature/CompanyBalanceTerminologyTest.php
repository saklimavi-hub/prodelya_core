<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CompanyBalanceTerminologyTest extends TestCase
{
    use BuildsStatementExportFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->enableCurrentAccounts($this->tenant);
    }

    public function test_company_dashboard_uses_professional_balance_labels(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'company-balance-terminology@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Terminoloji Dashboard Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1200,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Dashboard satış',
        ]);

        $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/companies'))
            ->assertOk()
            ->assertSee('Toplam Borç')
            ->assertSee('Toplam Alacak')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Bakiye Durumu')
            ->assertSee('Borç Bakiyesi')
            ->assertSee('1.200,00 TL')
            ->assertDontSee('+1.200,00 TL')
            ->assertDontSee('Toplam Bize Borçlu')
            ->assertDontSee('Toplam Biz Borçluyuz')
            ->assertDontSee('Bize Borçlu')
            ->assertDontSee('Biz Borçluyuz');
    }
}
