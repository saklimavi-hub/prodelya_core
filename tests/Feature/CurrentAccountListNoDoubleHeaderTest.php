<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListNoDoubleHeaderTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_current_account_list_does_not_repeat_main_explanation_or_create_second_hero_header(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-no-double-header@example.test');
        $this->enableCurrentAccounts($tenant);

        $account = $this->createUnlinkedAccount($tenant, 'Başlık Kontrol Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts'));

        $response->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Finansal odaklı cari bakiyeleri izleyin'));
        $this->assertSame(1, substr_count($response->getContent(), 'Bu ekran cari kimlik düzenleme alanı değildir; finansal bakiye ve ekstre takibi için kullanılır.'));
    }
}
