<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class CurrentAccountListPerformanceGuardTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_active_tab_avoids_extra_duplicate_audit_queries_for_non_archived_rows(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = $this->makeFinanceUser($tenant, 'current-account-performance@example.test');
        $this->enableCurrentAccounts($tenant);

        $active = $this->createUnlinkedAccount($tenant, 'Performans Aktif Cari');
        $this->createTransaction($active);

        $fixture = $this->createSupplierDuplicateFixture($tenant, 'Etkin Promosyon');
        $fixture['duplicate_company']->forceFill(['status' => 'inactive'])->save();
        $fixture['duplicate_account']->forceFill(['status' => CurrentAccount::STATUS_ARCHIVED])->save();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $activeResponse = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=aktif'));
        $activeQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();

        $archivedResponse = $this->actingAs($user)
            ->get($this->tenantUrl($tenant, '/admin/current-accounts?tab=arsiv'));
        $archivedQueryCount = count(DB::getQueryLog());

        $activeResponse->assertOk()
            ->assertDontSee('Ana Cari Kart');

        $archivedResponse->assertOk()
            ->assertSee('Ana Cari Kart');

        $this->assertLessThan(
            $archivedQueryCount,
            $activeQueryCount,
            'Aktif sekme, arşiv sekmesinden daha fazla duplicate denetim sorgusu üretmemelidir.'
        );
    }
}
