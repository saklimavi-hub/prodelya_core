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
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountAutoSourceCancelForbiddenTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createTenantUser('auto-source-cancel@example.test', ['tenant_owner', 'finance']);
    }

    public function test_auto_source_transactions_cannot_be_cancelled_from_statement_but_manual_can(): void
    {
        $account = $this->createAccount('Auto Source Guard Cari');

        $manual = $this->createTransaction($account, CurrentAccountTransaction::SOURCE_TYPE_MANUAL, 0);
        $order = $this->createTransaction($account, OrderCurrentAccountDebitSyncService::SOURCE_TYPE, 1001);
        $payment = $this->createTransaction($account, OrderPaymentCurrentAccountSyncService::SOURCE_TYPE, 1002, CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT, CurrentAccountTransaction::DIRECTION_CREDIT);
        $procurement = $this->createTransaction($account, SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE, 1003, CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT);
        $production = $this->createTransaction($account, SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE, 1004, CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT);

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $order->id . '/cancel'), [
                'cancellation_reason' => 'UI order iptal denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $payment->id . '/cancel'), [
                'cancellation_reason' => 'UI payment iptal denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $procurement->id . '/cancel'), [
                'cancellation_reason' => 'UI procurement iptal denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $production->id . '/cancel'), [
                'cancellation_reason' => 'UI production iptal denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $manual->id . '/cancel'), [
                'cancellation_reason' => 'Manuel hareket iptali',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->assertSame(CurrentAccountTransaction::STATUS_CANCELLED, $manual->fresh()->status);
        $this->assertFalse($order->fresh()->isCancelled());
        $this->assertFalse($payment->fresh()->isCancelled());
        $this->assertFalse($procurement->fresh()->isCancelled());
        $this->assertFalse($production->fresh()->isCancelled());
    }

    private function createTenantUser(string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => 'Auto Source ' . $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }

        return $user;
    }

    private function createAccount(string $name): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $name,
            'legal_name' => $name . ' Ltd. Şti.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function createTransaction(
        CurrentAccount $account,
        ?string $sourceType,
        int $sourceId,
        string $type = CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
        string $direction = CurrentAccountTransaction::DIRECTION_DEBIT
    ): CurrentAccountTransaction {
        return CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'direction' => $direction,
            'amount' => 1000,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Cancel guard test',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
