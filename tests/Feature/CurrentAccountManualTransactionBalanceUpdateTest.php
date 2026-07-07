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

class CurrentAccountManualTransactionBalanceUpdateTest extends TestCase
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

    public function test_manual_transactions_update_balance_direction_and_aging(): void
    {
        [$customerAccount, $customerCompany] = $this->createLinkedAccount('Bakiye Müşteri Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        [$supplierAccount, $supplierCompany] = $this->createLinkedAccount('Bakiye Tedarikçi Cari', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);

        $this->createTransaction($customerAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'amount' => 1000,
            'transaction_date' => '2026-07-01',
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $this->createTransaction($customerAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'amount' => 250,
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
        ]);

        $this->createTransaction($supplierAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'amount' => 600,
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $this->createTransaction($supplierAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            'amount' => 200,
            'transaction_date' => '2026-07-04',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk()
            ->assertSee($customerCompany->legal_name)
            ->assertSee($supplierCompany->legal_name)
            ->assertSee('750,00 TL')
            ->assertDontSee('+750,00 TL')
            ->assertSee('-400,00 TL')
            ->assertSee('Borç Bakiyesi')
            ->assertSee('Alacak Bakiyesi');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $customerCompany->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('750,00 TL')
            ->assertDontSee('+750,00 TL')
            ->assertSee('Borç Bakiyesi')
            ->assertSee('Vade Yaşlandırma')
            ->assertSee('1-7 Gün')
            ->assertSee('1.000,00 TL / 1');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $supplierCompany->id))
            ->assertOk()
            ->assertSee('-400,00 TL')
            ->assertSee('Alacak Bakiyesi');
    }

    private function createTransaction(CurrentAccount $account, array $overrides): CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'direction' => CurrentAccountTransaction::inferredDirectionForType((string) ($overrides['transaction_type'] ?? CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)),
            'amount' => 0,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Bakiye test hareketi',
        ], $overrides));
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Balance Update Finance',
            'email' => 'balance-update-finance@example.test',
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

    private function createLinkedAccount(string $displayName, array $roles): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);

        return [$account->fresh(['roles', 'primaryCompanyLink']), Company::query()->findOrFail($company->id)];
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
