<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListArchivedRowActionsTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_archived_row_shows_safe_actions_only(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-archive-actions@example.test');
        $this->enableCurrentAccounts($tenant);

        $fixture = $this->createSupplierDuplicateFixture($tenant, 'Etkin Promosyon');
        $fixture['duplicate_company']->forceFill(['status' => 'inactive'])->save();
        $fixture['duplicate_account']->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'));

        $response->assertOk()
            ->assertSee('Cari Kartı Aç')
            ->assertSee('Benzer Cari Kontrolü')
            ->assertDontSee('Finans Detayı')
            ->assertDontSee('>Ekstre<', false);
    }
}
