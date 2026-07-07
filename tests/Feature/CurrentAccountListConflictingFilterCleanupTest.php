<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListConflictingFilterCleanupTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_conflicting_status_filter_does_not_push_archived_rows_into_aktif_tab(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-conflict@example.test');
        $this->enableCurrentAccounts($tenant);

        $active = $this->createUnlinkedAccount($tenant, 'Uyumlu Aktif Cari');
        $this->createTransaction($active);

        $archived = $this->createUnlinkedAccount($tenant, 'Arşivdeki Cari', status: CurrentAccount::STATUS_ARCHIVED);

        $response = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=aktif&status=archived'));

        $response->assertOk()
            ->assertSee('Uyumlu Aktif Cari')
            ->assertDontSee('Arşivdeki Cari')
            ->assertSee('Seçili sekmeyle uyumsuz filtre temizlendi.');
    }
}
