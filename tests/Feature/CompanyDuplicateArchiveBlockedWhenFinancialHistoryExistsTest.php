<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveBlockedWhenFinancialHistoryExistsTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_archive_is_blocked_when_financial_history_exists(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-finance-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $this->createFinancialHistory($fixture['canonical_account'], 200);
        $this->createFinancialHistory($fixture['canonical_account'], 150);
        $this->createFinancialHistory($fixture['duplicate_account'], 50);

        $showResponse = $this->actingAs($owner)
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'));

        $showResponse->assertOk()
            ->assertSee('Finans hareketi')
            ->assertSee('Cari hareket kaydı var.')
            ->assertDontSee('Boş Benzer Cariyi Arşivle');

        $postResponse = $this->actingAs($owner)
            ->post($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '/archive-duplicate'));

        $postResponse->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'));
        $postResponse->assertSessionHas('error', 'Bu cari otomatik arşivlenemez. Lütfen kontrol listesindeki bağlantıları inceleyin.');

        $this->assertDatabaseHas('companies', [
            'id' => $fixture['duplicate_company']->id,
            'status' => 'active',
        ]);
        $this->assertSame(3, CurrentAccountTransaction::query()->count());
    }
}
