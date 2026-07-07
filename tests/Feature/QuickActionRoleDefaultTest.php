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

class QuickActionRoleDefaultTest extends TestCase
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

    public function test_role_based_quick_action_defaults_are_used_on_statement_screen(): void
    {
        $customerAccount = $this->createAccount('Varsayılan Müşteri', [CurrentAccountRole::ROLE_CUSTOMER]);
        $supplierAccount = $this->createAccount('Varsayılan Tedarikçi', [CurrentAccountRole::ROLE_SUPPLIER]);
        $subcontractorAccount = $this->createAccount('Varsayılan Fason', [CurrentAccountRole::ROLE_SUBCONTRACTOR]);
        $multiRoleAccount = $this->createAccount('Varsayılan Karma', [CurrentAccountRole::ROLE_CUSTOMER, CurrentAccountRole::ROLE_SUBCONTRACTOR]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $customerAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('data-quick-panel-open="customer_payment"', false)
            ->assertSee('Tahsilat');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $supplierAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('data-quick-panel-open="supplier_payment"', false)
            ->assertSee('Tedarikçi Borç Fişi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $subcontractorAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('data-quick-panel-open="subcontractor_payment"', false)
            ->assertSee('Fason Borç Fişi')
            ->assertSee('Ödeme');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $multiRoleAccount->id . '/transactions'))
            ->assertOk()
            ->assertSee('Tahsilat')
            ->assertSee('Fason Borç Fişi')
            ->assertSee('data-quick-panel-open="customer_payment"', false);
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
        $user = User::query()->create([
            'name' => 'Quick Action Role Finance',
            'email' => 'quick-action-role-finance@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach (['tenant_owner', 'finance'] as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        return $user;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
