<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListHidesArchivedFromActiveTabTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_archived_duplicate_is_hidden_from_aktif_and_visible_in_arsiv_tab(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-archive-tabs@example.test');
        $this->enableCurrentAccounts($tenant);

        $fixture = $this->createSupplierDuplicateFixture($tenant, 'Etkin Promosyon');
        $this->createFinancialHistory($fixture['canonical_account'], 250);

        $fixture['duplicate_company']->forceFill(['status' => 'inactive'])->save();
        $fixture['duplicate_account']->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();

        $aktif = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=aktif'));

        $aktif->assertOk()
            ->assertSee($fixture['canonical_account']->display_name)
            ->assertDontSee($fixture['duplicate_account']->display_name);

        $arsiv = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'));

        $arsiv->assertOk()
            ->assertSee($fixture['duplicate_account']->display_name)
            ->assertSee('Arşivlendi')
            ->assertSee('Ana Cari Kart:')
            ->assertSee($fixture['canonical_company']->legal_name);
    }
}
