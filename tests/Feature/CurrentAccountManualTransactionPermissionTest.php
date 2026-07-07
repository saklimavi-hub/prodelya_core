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

class CurrentAccountManualTransactionPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;
    private User $limitedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createUser('manual-permission-finance@example.test', 'finance', true);
        $this->limitedUser = $this->createUser('manual-permission-limited@example.test', 'delivery', false);
    }

    public function test_only_finance_user_can_view_and_store_manual_transactions(): void
    {
        $account = $this->createAccount('Permission Cari');

        $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertForbidden();

        $this->actingAs($this->limitedUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 100,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertOk()
            ->assertSee('Borç / Alacak / Tahsilat / Ödeme Fişi');

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 100,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'description' => 'Yetkili hareket',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));
    }

    private function createUser(string $email, string $baseRole, bool $includeFinanceRole): User
    {
        $baseRoleModel = Role::query()->where('key', $baseRole)->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => $includeFinanceRole ? 'Permission Finance User' : 'Permission Limited User',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $baseRoleModel->id,
        ]);

        if ($includeFinanceRole && $baseRole !== 'finance') {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => $financeRole->id,
            ]);
        }

        return $user;
    }

    private function createAccount(string $displayName): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
