<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListOpenAndOverdueTabsTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_open_and_overdue_tabs_only_show_matching_accounts(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-open-overdue@example.test');
        $this->enableCurrentAccounts($tenant);

        $openAccount = $this->createUnlinkedAccount($tenant, 'Açık Hareket Cari');
        $overdueAccount = $this->createUnlinkedAccount($tenant, 'Vadesi Geçen Cari');
        $closedAccount = $this->createUnlinkedAccount($tenant, 'Kapalı Hareket Cari');

        $this->createTransaction($openAccount, CurrentAccountTransaction::STATUS_OPEN, now()->addDays(5)->toDateString());
        $this->createTransaction($overdueAccount, CurrentAccountTransaction::STATUS_PARTIALLY_PAID, now()->subDays(4)->toDateString());
        $this->createTransaction($closedAccount, CurrentAccountTransaction::STATUS_CLOSED, now()->subDays(10)->toDateString());

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=acik'))
            ->assertOk()
            ->assertSee('Açık Hareket Cari')
            ->assertSee('Vadesi Geçen Cari')
            ->assertDontSee('Kapalı Hareket Cari');

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=vadesi-gecen'))
            ->assertOk()
            ->assertSee('Vadesi Geçen Cari')
            ->assertDontSee('Açık Hareket Cari')
            ->assertDontSee('Kapalı Hareket Cari');
    }
}
