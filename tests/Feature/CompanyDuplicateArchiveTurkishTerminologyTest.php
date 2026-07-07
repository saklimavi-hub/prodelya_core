<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveTurkishTerminologyTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_archive_tab_uses_turkish_user_facing_terminology(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-terminology-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $this->actingAs($owner)
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'))
            ->assertOk()
            ->assertSee('Ana Cari Kart')
            ->assertSee('Benzer Cari')
            ->assertSee('Arşivlemeye uygun')
            ->assertSee('Boş Benzer Cariyi Arşivle')
            ->assertDontSee('supplier_id')
            ->assertDontSee('current_account_id')
            ->assertDontSee('tenant_id');
    }
}
