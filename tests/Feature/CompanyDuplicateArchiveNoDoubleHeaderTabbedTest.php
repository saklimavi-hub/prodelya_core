<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveNoDoubleHeaderTabbedTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_archive_tab_keeps_single_header_layout(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-header-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $response = $this->actingAs($owner)
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'));

        $response->assertOk()->assertSee('Benzer Cari Kontrolü');
        $this->assertSame(1, substr_count($response->getContent(), 'Cari Kart Detayı'));
    }
}
