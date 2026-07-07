<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CompanyStatementExportLinksTest extends TestCase
{
    use BuildsStatementExportFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_company_show_renders_export_links_with_statement_filters(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'company-statement-export-links@example.test');
        [$account, $company] = $this->createLinkedCompanyAccount($this->tenant, 'Link Export Cari');

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/companies/' . $company->id . '?tab=ekstre&statement_from=2026-07-01&statement_to=2026-07-31&statement_status=open&statement_search=ABC'));

        $response->assertOk()
            ->assertSee('Ekstre PDF')
            ->assertSee('Detaylı PDF')
            ->assertSee('Ekstre Excel')
            ->assertSee('Detaylı Excel')
            ->assertSee('/admin/current-accounts/' . $account->id . '/transactions/export/pdf?mode=summary', false)
            ->assertSee('from=2026-07-01', false)
            ->assertSee('to=2026-07-31', false)
            ->assertSee('status=open', false)
            ->assertSee('search=ABC', false);
    }

    public function test_company_without_current_account_does_not_render_export_links(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'company-statement-export-links-empty@example.test');
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Linksiz Cari',
            'status' => 'active',
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/companies/' . $company->id));

        $response->assertOk()
            ->assertDontSee('Ekstre PDF')
            ->assertDontSee('Detaylı PDF')
            ->assertDontSee('Ekstre Excel')
            ->assertDontSee('Detaylı Excel');
    }
}
