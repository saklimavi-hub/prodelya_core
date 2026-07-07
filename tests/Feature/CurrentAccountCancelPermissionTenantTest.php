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

class CurrentAccountCancelPermissionTenantTest extends TestCase
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

        $this->tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createTenantUser($this->tenant, 'cancel-finance@example.test', ['tenant_owner', 'finance']);
        $this->limitedUser = $this->createTenantUser($this->tenant, 'cancel-limited@example.test', ['production']);
    }

    public function test_cancel_endpoint_stays_permission_and_tenant_scoped(): void
    {
        $account = $this->createAccount($this->tenant, 'Permission Cari');
        $transaction = $this->createManualTransaction($account, 'Permission test hareketi');

        $this->actingAs($this->limitedUser, 'web')
            ->post($this->tenantUrl($this->tenant, '/admin/current-account-transactions/' . $transaction->id . '/cancel'), [
                'cancellation_reason' => 'Yetkisiz deneme',
            ])
            ->assertForbidden();

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Cancel Tenant',
            'legal_name' => 'Foreign Cancel Tenant Ltd.',
            'slug' => 'foreign-cancel-tenant',
            'panel_subdomain' => 'foreign-cancel-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignFinanceUser = $this->createTenantUser($foreignTenant, 'foreign-cancel-finance@example.test', ['tenant_owner', 'finance']);

        $this->actingAs($foreignFinanceUser, 'web')
            ->post($this->tenantUrl($foreignTenant, '/admin/current-account-transactions/' . $transaction->id . '/cancel'), [
                'cancellation_reason' => 'Cross-tenant deneme',
            ])
            ->assertForbidden();

        $this->assertFalse($transaction->fresh()->isCancelled());
    }

    private function createTenantUser(TenantAccount $tenant, string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => 'Cancel Permission ' . $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            UserRole::query()->create([
                'tenant_account_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }

        return $user;
    }

    private function createAccount(TenantAccount $tenant, string $name): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $name,
            'legal_name' => $name . ' Ltd. Şti.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function createManualTransaction(CurrentAccount $account, string $description): CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'source_id' => null,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 750,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => $description,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
