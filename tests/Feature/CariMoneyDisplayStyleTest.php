<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\TestCase;

class CariMoneyDisplayStyleTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_money_display_uses_positive_negative_and_zero_classes(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'money-display-style@example.test');

        [$positiveAccount] = $this->createCompanyLinkedAccount($tenant, 'Stil Pozitif Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($positiveAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 800,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$negativeAccount] = $this->createCompanyLinkedAccount($tenant, 'Stil Negatif Cari', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($negativeAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 350,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$zeroAccount] = $this->createCompanyLinkedAccount($tenant, 'Stil Sifir Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($zeroAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 250,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
        $this->createCompanyListTransaction($zeroAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 250,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'));

        $response->assertOk()
            ->assertSee('pd-money-positive', false)
            ->assertSee('pd-money-negative', false)
            ->assertSee('pd-money-zero', false);
    }
}
