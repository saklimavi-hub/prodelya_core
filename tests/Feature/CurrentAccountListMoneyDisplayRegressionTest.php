<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListMoneyDisplayRegressionTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_current_account_list_tabs_keep_money_display_standard(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeFinanceUser($tenant, 'current-account-money-display@example.test');
        $this->enableCurrentAccounts($tenant);

        [$positiveAccount] = $this->createLinkedAccount($tenant, 'Pozitif Bakiye Hesabi', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction(
            $positiveAccount,
            CurrentAccountTransaction::STATUS_OPEN,
            now()->subDays(1)->toDateString(),
            CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            CurrentAccountTransaction::DIRECTION_DEBIT,
            1500
        );

        [$negativeAccount] = $this->createLinkedAccount($tenant, 'Negatif Bakiye Hesabi', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction(
            $negativeAccount,
            CurrentAccountTransaction::STATUS_OPEN,
            now()->subDays(2)->toDateString(),
            CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            CurrentAccountTransaction::DIRECTION_DEBIT,
            450
        );

        $fixture = $this->createSupplierDuplicateFixture($tenant, 'Arsiv Para Cari');
        $fixture['duplicate_company']->forceFill(['status' => 'inactive'])->save();
        $fixture['duplicate_account']->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();

        $activeResponse = $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'));

        $activeResponse->assertOk()
            ->assertSee('1.500,00 TL')
            ->assertDontSee('+1.500,00 TL')
            ->assertSee('-450,00 TL')
            ->assertSee('pd-money-positive', false)
            ->assertSee('pd-money-negative', false);

        $archivedResponse = $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'));

        $archivedResponse->assertOk()
            ->assertDontSee('+1.500,00 TL')
            ->assertSee('pd-money-zero', false);
    }
}
