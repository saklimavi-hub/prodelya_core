<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListPermissionTenantTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_permissions_and_tenant_scope_are_preserved_on_list(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $foreignTenant = $this->createOtherTenant('foreign-current-account-list');
        $financeUser = $this->makeFinanceUser($tenant, 'current-account-perm-finance@example.test');
        $limitedUser = $this->makeLimitedUser($tenant, 'current-account-perm-limited@example.test');
        $this->enableCurrentAccounts($tenant);
        $this->enableCurrentAccounts($foreignTenant);

        $local = $this->createUnlinkedAccount($tenant, 'Yerel Cari');
        $this->createTransaction($local);

        $foreign = $this->createUnlinkedAccount($foreignTenant, 'Yabancı Cari');
        $this->createTransaction($foreign);

        $this->actingAs($financeUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'))
            ->assertOk()
            ->assertSee('Yerel Cari')
            ->assertDontSee('Yabancı Cari')
            ->assertSee('Güncel Bakiye');

        $this->actingAs($limitedUser)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'))
            ->assertOk()
            ->assertSee('Yerel Cari')
            ->assertDontSee('Yabancı Cari')
            ->assertDontSee('Güncel Bakiye')
            ->assertDontSee('Bakiye Durumu')
            ->assertDontSee('/transactions', false);
    }
}
