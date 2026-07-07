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
use App\Services\CurrentAccountTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountManualTransactionCancelConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first()
            ?? TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->financeUser = $this->createTenantUser('statement-cancel-confirm@example.test', ['tenant_owner', 'finance']);
    }

    public function test_manual_transaction_row_shows_explicit_cancel_confirmation_context(): void
    {
        $account = $this->createAccount('Manuel Iptal Cari');
        $transaction = app(CurrentAccountTransactionService::class)->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'amount' => 1450,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'document_number' => 'MN-001',
            'description' => 'Manuel iptal test hareketi',
        ], $this->adminUser);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertOk()
            ->assertSee('İptal Et')
            ->assertSee('Bu cari hareketi iptal etmek üzeresiniz. İşlem bakiyeyi etkiler. Devam etmek istiyor musunuz?')
            ->assertSee('Manuel Iptal Cari')
            ->assertSee($transaction->safeTypeLabel())
            ->assertSee($transaction->formattedAmount())
            ->assertSee('03.07.2026')
            ->assertSee('MN-001')
            ->assertSee('Manuel iptal test hareketi');
    }

    private function createTenantUser(string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => 'Statement Cancel ' . $email,
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
