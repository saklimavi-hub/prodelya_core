<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\TestCase;

class CompanyListBalanceDisplayTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_company_list_shows_balance_values_without_plus_sign_and_keeps_labels(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'company-list-balance-display@example.test');

        [$receivableAccount] = $this->createCompanyLinkedAccount($tenant, 'Liste Pozitif Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
        $this->createCompanyListTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 250,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);

        [$payableAccount] = $this->createCompanyLinkedAccount($tenant, 'Liste Negatif Cari', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($payableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 600,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$closedAccount] = $this->createCompanyLinkedAccount($tenant, 'Liste Kapali Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($closedAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
        $this->createCompanyListTransaction($closedAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'));

        $response->assertOk()
            ->assertSee('750,00 TL')
            ->assertDontSee('+750,00 TL')
            ->assertSee('-600,00 TL')
            ->assertSee('0,00 TL')
            ->assertSee('Borç Bakiyesi')
            ->assertSee('Alacak Bakiyesi')
            ->assertSee('Kapalı')
            ->assertSee('pd-money-negative', false);
    }
}
