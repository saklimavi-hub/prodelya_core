<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListRightSummaryPolishTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_right_summary_panel_shows_selected_tab_summary_and_polished_links(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-right-summary@example.test');
        $this->enableCurrentAccounts($tenant);

        $account = $this->createUnlinkedAccount($tenant, 'Sağ Panel Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=acik'));

        $response->assertOk()
            ->assertSee('Seçili Sekme Özeti')
            ->assertSee('Bu Sekmedeki Kayıt')
            ->assertSee('Cari Kartlar')
            ->assertSee('Tüm Cari Bakiyeler')
            ->assertSee('Bu ekran cari kimlik düzenleme alanı değildir; finansal bakiye ve ekstre takibi için kullanılır.')
            ->assertDontSee('teknik');
    }
}
