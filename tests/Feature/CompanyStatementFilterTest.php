<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyStatementFilterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createFinanceUser();
    }

    public function test_company_statement_filters_work_and_filtered_summary_is_separate(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Filtre Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Sipariş SP-100 satış hareketi',
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 300,
            'currency' => 'TL',
            'transaction_date' => '2026-07-10',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Sipariş SP-100 tahsilat kaydı',
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 50,
            'currency' => 'TL',
            'transaction_date' => '2026-08-01',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Farklı dönem düzeltme',
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre&statement_from=2026-07-01&statement_to=2026-07-31&statement_type=customer_debit&statement_status=open&statement_search=SP-100'));

        $response->assertOk()
            ->assertSee('Sipariş SP-100 satış hareketi')
            ->assertDontSee('Sipariş SP-100 tahsilat kaydı')
            ->assertDontSee('Farklı dönem düzeltme')
            ->assertSee('Filtreli Hareket Toplamı')
            ->assertSee('1.000,00 TL');
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Company Statement Filter Finance',
            'email' => 'company-statement-filter@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $financeRole->id,
        ]);

        return $user;
    }

    private function createLinkedCompanyAccount(string $displayName): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
