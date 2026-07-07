<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountManualTransactionRoleOptionsTest extends TestCase
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

    public function test_role_based_manual_transaction_options_are_prioritized_safely(): void
    {
        $customerAccount = $this->createAccount('Rol Müşteri', [CurrentAccountRole::ROLE_CUSTOMER]);
        $supplierAccount = $this->createAccount('Rol Tedarikçi', [CurrentAccountRole::ROLE_SUPPLIER]);
        $subcontractorAccount = $this->createAccount('Rol Fason', [CurrentAccountRole::ROLE_SUBCONTRACTOR]);
        $multiRoleAccount = $this->createAccount('Rol Karma', [CurrentAccountRole::ROLE_CUSTOMER, CurrentAccountRole::ROLE_SUPPLIER]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $customerAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('Müşteri Borç Fişi')
            ->assertSee('Tahsilat')
            ->assertDontSee('Tedarikçi Borç Fişi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $supplierAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('Tedarikçi Borç Fişi')
            ->assertSee('Tedarikçi Ödemesi')
            ->assertDontSee('Müşteri Borç Fişi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $subcontractorAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('Fason Borç Fişi')
            ->assertSee('Fason Ödemesi')
            ->assertDontSee('Tedarikçi Borç Fişi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $multiRoleAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('Müşteri Borç Fişi')
            ->assertSee('Tahsilat')
            ->assertSee('Tedarikçi Borç Fişi')
            ->assertSee('Tedarikçi Ödemesi');
    }

    private function createAccount(string $displayName, array $roles): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        return $account->fresh(['roles']);
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Role Options Finance',
            'email' => 'role-options-finance@example.test',
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
