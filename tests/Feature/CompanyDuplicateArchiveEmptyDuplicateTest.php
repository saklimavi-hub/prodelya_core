<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveEmptyDuplicateTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_empty_duplicate_can_be_archived_without_deleting_records(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-archive-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $response = $this->actingAs($owner)
            ->post($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '/archive-duplicate'));

        $response->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'));
        $response->assertSessionHas('success', 'Benzer cari arşivlendi. Ana cari kart kullanılmaya devam edecek.');

        $this->assertSame('inactive', Company::query()->findOrFail($fixture['duplicate_company']->id)->status);
        $this->assertSame(CurrentAccount::STATUS_ARCHIVED, CurrentAccount::query()->findOrFail($fixture['duplicate_account']->id)->status);
        $this->assertSame('active', Company::query()->findOrFail($fixture['canonical_company']->id)->status);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $fixture['canonical_account']->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $fixture['supplier']->id,
        ]);
    }
}
