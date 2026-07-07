<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListNoTechnicalTermsTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_current_account_list_uses_turkish_labels_and_hides_technical_terms(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-terms@example.test');
        $this->enableCurrentAccounts($tenant);

        [$account] = $this->createLinkedAccount($tenant, 'Terminoloji Cari');
        $this->createTransaction($account);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=tumu'));

        $response->assertOk()
            ->assertSee('Cari Bakiye')
            ->assertSee('Cari Kartı Aç')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Açık Hareket')
            ->assertDontSee('Current Account')
            ->assertDontSee('Company')
            ->assertDontSee('canonical')
            ->assertDontSee('duplicate')
            ->assertDontSee('source_type')
            ->assertDontSee('current_account_id')
            ->assertDontSee('company_id')
            ->assertDontSee('tenant_id')
            ->assertDontSee('payload')
            ->assertDontSee('meta_json');
    }
}
