<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CariMoneyDisplayNoPlusSignTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_positive_money_values_do_not_render_plus_sign_and_non_money_plus_signs_stay_intact(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'money-display-no-plus@example.test');
        $this->enableCurrentAccounts($tenant);

        [$positiveAccount, $positiveCompany] = $this->createCompanyLinkedAccount(
            $tenant,
            'Pozitif Bakiye Cari',
            [CurrentAccountRole::ROLE_CUSTOMER],
            ['mobile' => '+905551112233'],
            ['mobile' => '+905551112233']
        );
        $this->createCompanyListTransaction($positiveAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1500,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$negativeAccount] = $this->createCompanyLinkedAccount(
            $tenant,
            'Negatif Bakiye Cari',
            [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]
        );
        $this->createCompanyListTransaction($negativeAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 600,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $unlinkedPhoneAccount = $this->createUnlinkedAccount($tenant, 'Telefon Artı Cari', [CurrentAccountRole::ROLE_SUPPLIER]);
        $unlinkedPhoneAccount->forceFill([
            'phone' => '+905551234567',
            'mobile' => '+905551234567',
        ])->save();

        $companyListResponse = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'));

        $companyListResponse->assertOk()
            ->assertSee('1.500,00 TL')
            ->assertDontSee('+1.500,00 TL')
            ->assertSee('-600,00 TL')
            ->assertSee('Yeni Cari Oluştur');

        $ledgerListResponse = $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'));

        $ledgerListResponse->assertOk()
            ->assertSee('1.500,00 TL')
            ->assertDontSee('+1.500,00 TL')
            ->assertSee('-600,00 TL');

        $statementResponse = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies/' . $positiveCompany->id . '?tab=ekstre'));

        $statementResponse->assertOk()
            ->assertSee('1.500,00 TL')
            ->assertDontSee('+1.500,00 TL');

        $accountShowResponse = $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts/' . $unlinkedPhoneAccount->id));

        $accountShowResponse->assertOk()
            ->assertSee('+905551234567');
    }
}
