<?php

namespace Tests\Feature;

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

class CompanyBalancePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $limitedUser;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->limitedUser = $this->createTenantUser('company-balance-owner@example.test', 'delivery');
        $this->financeUser = $this->createTenantUser('company-balance-finance@example.test', true);
    }

    public function test_finance_summary_is_hidden_for_non_finance_user_and_visible_for_finance_user(): void
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Permission Balance Cari',
            'legal_name' => 'Permission Balance Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Permission balance',
        ]);

        $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk()
            ->assertDontSee('Toplam Borç')
            ->assertDontSee('Güncel Bakiye')
            ->assertDontSee('Borç Bakiyesi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk()
            ->assertSee('Toplam Borç')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Borç Bakiyesi');
    }

    private function createTenantUser(string $email, bool|string $role): User
    {
        $tenantRole = Role::query()->where('key', is_string($role) ? $role : 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => $role === true ? 'Company Balance Finance User' : 'Company Balance Limited User',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantRole->id,
        ]);

        if ($role === true) {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => $financeRole->id,
            ]);
        }

        return $user;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
