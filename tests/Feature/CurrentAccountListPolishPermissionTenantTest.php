<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListPolishPermissionTenantTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_polished_list_keeps_permission_and_tenant_scope_including_archive_tab(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $foreignTenant = $this->createOtherTenant('foreign-current-account-polish');
        $financeUser = $this->makeFinanceUser($tenant, 'current-account-polish-finance@example.test');
        $limitedUser = $this->makeLimitedUser($tenant, 'current-account-polish-limited@example.test');
        $this->enableCurrentAccounts($tenant);
        $this->enableCurrentAccounts($foreignTenant);

        $localArchived = $this->createUnlinkedAccount($tenant, 'Yerel Arşiv Cari', status: CurrentAccount::STATUS_ARCHIVED);
        $foreignArchived = $this->createUnlinkedAccount($foreignTenant, 'Yabancı Arşiv Cari', status: CurrentAccount::STATUS_ARCHIVED);
        $this->createTransaction($localArchived);
        $this->createTransaction($foreignArchived);

        $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'))
            ->assertOk()
            ->assertSee('Yerel Arşiv Cari')
            ->assertDontSee('Yabancı Arşiv Cari')
            ->assertSee('Güncel Bakiye');

        $this->actingAs($limitedUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'))
            ->assertOk()
            ->assertSee('Yerel Arşiv Cari')
            ->assertDontSee('Yabancı Arşiv Cari')
            ->assertDontSee('Güncel Bakiye');
    }
}
