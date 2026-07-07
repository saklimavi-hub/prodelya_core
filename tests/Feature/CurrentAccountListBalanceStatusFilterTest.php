<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListBalanceStatusFilterTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_balance_status_filter_shows_receivable_payable_and_closed_accounts(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-balance-filter@example.test');
        $this->enableCurrentAccounts($tenant);

        $receivable = $this->createUnlinkedAccount($tenant, 'Borç Bakiyesi Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $payable = $this->createUnlinkedAccount($tenant, 'Alacak Bakiyesi Cari', [CurrentAccountRole::ROLE_SUPPLIER]);
        $closed = $this->createUnlinkedAccount($tenant, 'Kapalı Bakiye Cari', [CurrentAccountRole::ROLE_CUSTOMER]);

        $this->createTransaction($receivable, CurrentAccountTransaction::STATUS_OPEN, now()->addDay()->toDateString(), CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT, CurrentAccountTransaction::DIRECTION_DEBIT, 300);
        $this->createTransaction($payable, CurrentAccountTransaction::STATUS_OPEN, now()->addDay()->toDateString(), CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT, CurrentAccountTransaction::DIRECTION_DEBIT, 175);

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu&balance_status=receivable'))
            ->assertOk()
            ->assertSee('Borç Bakiyesi Cari')
            ->assertDontSee('Alacak Bakiyesi Cari')
            ->assertDontSee('Kapalı Bakiye Cari');

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu&balance_status=payable'))
            ->assertOk()
            ->assertSee('Alacak Bakiyesi Cari')
            ->assertDontSee('Borç Bakiyesi Cari')
            ->assertDontSee('Kapalı Bakiye Cari');

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu&balance_status=closed'))
            ->assertOk()
            ->assertSee('Kapalı Bakiye Cari')
            ->assertDontSee('Borç Bakiyesi Cari')
            ->assertDontSee('Alacak Bakiyesi Cari');
    }
}
