<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListArchivedEmptyActionsTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_empty_archived_duplicate_shows_only_safe_actions(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-archive-empty-actions@example.test');
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
