<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListTabFilterTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tab_query_param_filters_rows_and_invalid_tab_falls_back_to_aktif(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-tab-filter@example.test');
        $this->enableCurrentAccounts($tenant);

        $active = $this->createUnlinkedAccount($tenant, 'Aktif Bakiye Kaydı');
        $this->createTransaction($active);

        $archived = $this->createUnlinkedAccount($tenant, 'Arşiv Kaydı', status: CurrentAccount::STATUS_ARCHIVED);

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=aktif'))
            ->assertOk()
            ->assertSee('Aktif Bakiye Kaydı')
            ->assertDontSee('Arşiv Kaydı')
            ->assertSee('Aktif ve finansal anlamı olan cari bakiyeleri izleyin.');

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'))
            ->assertOk()
            ->assertSee('Arşiv Kaydı')
            ->assertDontSee('Aktif Bakiye Kaydı')
            ->assertSee('Pasif veya arşivlenen cari kartlar burada görünür. Bu kayıtlar silinmez; geçmiş kontrolü için saklanır.');

        $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=gecersiz'))
            ->assertOk()
            ->assertSee('Aktif Bakiye Kaydı')
            ->assertDontSee('Arşiv Kaydı')
            ->assertSee('Aktif ve finansal anlamı olan cari bakiyeleri izleyin.');
    }
}
