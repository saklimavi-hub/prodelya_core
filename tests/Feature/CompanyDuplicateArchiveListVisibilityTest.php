<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveListVisibilityTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_archived_duplicate_is_not_shown_like_active_record_in_company_list(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-list-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $this->actingAs($owner)
            ->post($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '/archive-duplicate'))
            ->assertRedirect();

        $response = $this->actingAs($owner)
            ->get($this->tenantUrl($tenant, '/admin/companies'));

        $response->assertOk()
            ->assertSee($fixture['canonical_company']->legal_name)
            ->assertSee($fixture['duplicate_company']->legal_name)
            ->assertSee('Pasif');
    }
}
