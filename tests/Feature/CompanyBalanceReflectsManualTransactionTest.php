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

class CompanyBalanceReflectsManualTransactionTest extends TestCase
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

    public function test_manual_transaction_updates_company_and_current_account_balance_surfaces(): void
    {
        [$account, $company] = $this->createLinkedAccount('Yansima Cari');

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 800,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'description' => 'Yansıma satış hareketi',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
                'amount' => 300,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => CurrentAccountTransaction::STATUS_CLOSED,
                'description' => 'Yansıma tahsilat hareketi',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk()
            ->assertSee($company->legal_name)
            ->assertSee('500,00 TL')
            ->assertDontSee('+500,00 TL')
            ->assertSee('Borç Bakiyesi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('500,00 TL')
            ->assertDontSee('+500,00 TL')
            ->assertSee('Yansıma satış hareketi')
            ->assertSee('Yansıma tahsilat hareketi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts'))
            ->assertOk()
            ->assertSee($account->safeDisplayName())
            ->assertSee('500,00 TL')
            ->assertDontSee('+500,00 TL')
            ->assertSee('Borç Bakiyesi');
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Reflect Finance User',
            'email' => 'reflect-finance@example.test',
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

    private function createLinkedAccount(string $displayName): array
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

        return [$account->fresh(['roles', 'primaryCompanyLink']), Company::query()->findOrFail($company->id)];
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
