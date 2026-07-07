<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListNoDoubleHeaderRegressionTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_current_account_list_still_has_single_header_after_polish(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-no-double-header-regression@example.test');
        $this->enableCurrentAccounts($tenant);

        $account = $this->createUnlinkedAccount($tenant, 'Regresyon Başlık Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts'));

        $response->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Finansal odaklı cari bakiyeleri izleyin'));
        $this->assertSame(1, substr_count($response->getContent(), 'Bu ekran cari kimlik düzenleme alanı değildir; finansal bakiye ve ekstre takibi için kullanılır.'));
    }
}
