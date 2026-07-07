<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListArchiveCanonicalColumnTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_archive_tab_shows_main_company_column_without_technical_terms(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-archive-column@example.test');
        $this->enableCurrentAccounts($tenant);

        $fixture = $this->createSupplierDuplicateFixture($tenant, 'Etkin Promosyon');
        $fixture['duplicate_company']->forceFill(['status' => 'inactive'])->save();
        $fixture['duplicate_account']->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'));

        $response->assertOk()
            ->assertSee('Ana Cari Kart')
            ->assertSee($fixture['canonical_company']->short_name)
            ->assertDontSee('canonical')
            ->assertDontSee('duplicate');
    }
}
