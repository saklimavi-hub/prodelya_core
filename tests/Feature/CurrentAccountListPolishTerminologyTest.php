<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListPolishTerminologyTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_polished_current_account_list_keeps_turkish_labels_and_hides_technical_terms(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-polish-terms@example.test');
        $this->enableCurrentAccounts($tenant);

        [$account] = $this->createLinkedAccount($tenant, 'Terminoloji Polish Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'));

        $response->assertOk()
            ->assertSee('Cari Bakiye')
            ->assertSee('Güncel Bakiye')
            ->assertDontSee('Current Account')
            ->assertDontSee('Company')
            ->assertDontSee('canonical')
            ->assertDontSee('duplicate')
            ->assertDontSee('current_account_id')
            ->assertDontSee('company_id')
            ->assertDontSee('tenant_id');
    }
}
