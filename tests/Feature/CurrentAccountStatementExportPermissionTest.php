<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountStatementExportPermissionTest extends TestCase
{
    use BuildsStatementExportFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Statement Export Foreign',
            'legal_name' => 'Statement Export Foreign Ltd.',
            'slug' => 'statement-export-foreign',
            'panel_subdomain' => 'statement-export-foreign',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->enableCurrentAccounts($this->tenant);
        $this->enableCurrentAccounts($this->foreignTenant);
    }

    public function test_export_routes_require_finance_permission_and_tenant_scope(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'statement-export-permission-finance@example.test');
        $limitedUser = $this->makeLimitedUser($this->tenant, 'statement-export-permission-limited@example.test');
        $foreignFinanceUser = $this->makeFinanceUser($this->foreignTenant, 'statement-export-permission-foreign@example.test');

        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Permission Export Cari');
        [$foreignAccount] = $this->createLinkedCompanyAccount($this->foreignTenant, 'Foreign Permission Export Cari');

        $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/pdf'))
            ->assertOk();

        $this->actingAs($limitedUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/pdf'))
            ->assertForbidden();

        $this->actingAs($limitedUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/excel'))
            ->assertForbidden();

        $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $foreignAccount->id . '/transactions/export/pdf'))
            ->assertForbidden();

        $this->actingAs($foreignFinanceUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/excel'))
            ->assertForbidden();
    }
}
