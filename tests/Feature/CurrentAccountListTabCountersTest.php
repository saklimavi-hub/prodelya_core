<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListTabCountersTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tab_counters_are_visible_and_tenant_scoped(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-counters@example.test');
        $this->enableCurrentAccounts($tenant);

        $first = $this->createUnlinkedAccount($tenant, 'Sayaç Açık Cari');
        $second = $this->createUnlinkedAccount($tenant, 'Sayaç Vadesi Geçen Cari');
        $archived = $this->createUnlinkedAccount($tenant, 'Sayaç Arşiv Cari', status: CurrentAccount::STATUS_ARCHIVED);

        $this->createTransaction($first, CurrentAccountTransaction::STATUS_OPEN, now()->addDays(2)->toDateString());
        $this->createTransaction($second, CurrentAccountTransaction::STATUS_PARTIALLY_PAID, now()->subDays(3)->toDateString());

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts'));

        $response->assertOk()
            ->assertSee('Aktif Bakiyeler')
            ->assertSee('Açık Hareketler')
            ->assertSee('Vadesi Geçenler')
            ->assertSee('Tüm Cari Bakiyeler')
            ->assertSee('Pasif / Arşivlenenler')
            ->assertSee('>2</span>', false)
            ->assertSee('>1</span>', false)
            ->assertSee('>3</span>', false);
    }
}
