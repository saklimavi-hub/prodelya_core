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

class CompanyFinanceLinkSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_company_show_uses_company_edit_for_identity_and_current_account_transactions_for_statement(): void
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Finance Link Cari',
            'legal_name' => 'Finance Link Cari Ltd.',
            'account_code' => 'CRK-FIN-001',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)
            ->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        $company = Company::query()->findOrFail($company->id);
        $account = $account->fresh();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $company));

        $response->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee(route('admin.companies.edit', $company), false)
            ->assertSee('Ekstreye Git')
            ->assertSee('Tahsilat Gir')
            ->assertSee('Ödeme Yap')
            ->assertSee('Yeni Hareket')
            ->assertDontSee('Cari Listesini Aç');
    }
}
