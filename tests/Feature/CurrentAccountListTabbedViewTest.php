<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListTabbedViewTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_current_account_index_renders_tabbed_single_header_layout(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-tabs@example.test');
        $this->enableCurrentAccounts($tenant);

        [$account, $company] = $this->createLinkedAccount($tenant, 'Sekmeli Bakiye Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts'));

        $response->assertOk()
            ->assertSee('Aktif Bakiyeler')
            ->assertSee('Açık Hareketler')
            ->assertSee('Vadesi Geçenler')
            ->assertSee('Tüm Cari Bakiyeler')
            ->assertSee('Pasif / Arşivlenenler')
            ->assertSee('Cari Kartlar')
            ->assertSee('Cari Kartı Aç')
            ->assertSee($this->tenantUrl($tenant, '/admin/companies/' . $company->id), false);

        $this->assertSame(1, substr_count($response->getContent(), 'Finansal odaklı cari bakiyeleri izleyin'));
    }
}
