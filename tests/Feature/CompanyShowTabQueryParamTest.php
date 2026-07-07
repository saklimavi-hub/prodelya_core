<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyShowTabQueryParamTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_company_show_uses_query_parameter_for_active_tab(): void
    {
        [, $company] = $this->createCompany('Tab Query Cari');

        $ekstre = route('admin.companies.show', ['company' => $company, 'tab' => 'ekstre']);
        $tedarikci = route('admin.companies.show', ['company' => $company, 'tab' => 'tedarikci']);
        $invalid = route('admin.companies.show', ['company' => $company, 'tab' => 'gecersiz']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($ekstre)
            ->assertOk()
            ->assertSeeInOrder([
                'href="' . $ekstre . '"',
                'class="pd-company-tab is-active"',
                'data-company-tab="ekstre"',
                'aria-current="page"',
            ], false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($tedarikci)
            ->assertOk()
            ->assertSeeInOrder([
                'href="' . $tedarikci . '"',
                'class="pd-company-tab is-active"',
                'data-company-tab="tedarikci"',
                'aria-current="page"',
            ], false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($invalid)
            ->assertOk()
            ->assertSeeInOrder([
                'data-company-tab="genel"',
                'aria-current="page"',
            ], false);
    }

    private function createCompany(string $name): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $name,
            'legal_name' => $name . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account, Company::query()->findOrFail($company->id)];
    }
}
