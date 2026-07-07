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

class CompanyBalanceSummaryListTest extends TestCase
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
        $this->financeUser = $this->createTenantUserWithFinanceRole();
    }

    public function test_company_list_shows_real_balance_direction_and_safe_fallbacks(): void
    {
        [$receivableAccount] = $this->createLinkedAccountWithCompany('Liste Musteri Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'due_date' => now()->subDays(3),
        ]);
        $this->createTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 250,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);

        [$payableAccount] = $this->createLinkedAccountWithCompany('Liste Tedarikci Cari', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction($payableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 600,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        [$closedAccount] = $this->createLinkedAccountWithCompany('Liste Kapali Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction($closedAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);
        $this->createTransaction($closedAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);

        [$noMovementAccount] = $this->createLinkedAccountWithCompany('Liste Hareketsiz Cari', [CurrentAccountRole::ROLE_CUSTOMER]);

        Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Liste Finans Hesapsiz Cari',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies'));

        $response->assertOk()
            ->assertSee('Güncel Bakiye')
            ->assertSee('Bakiye Durumu')
            ->assertSee('Borç Bakiyesi')
            ->assertSee('Alacak Bakiyesi')
            ->assertSee('Kapalı')
            ->assertSee('750,00 TL')
            ->assertDontSee('+750,00 TL')
            ->assertSee('-600,00 TL')
            ->assertSee('0,00 TL')
            ->assertSee('Finans hesabı yok')
            ->assertSee('Hareket yok')
            ->assertSee('Açık Hareket')
            ->assertSee('Son Hareket');

        $this->assertSame('Liste Hareketsiz Cari', $noMovementAccount->fresh()->safeDisplayName());
    }

    private function createTenantUserWithFinanceRole(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Company Balance Finance User',
            'email' => 'company-balance-finance@example.test',
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

    private function createLinkedAccountWithCompany(string $displayName, array $roles): array
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

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function createTransaction(CurrentAccount $account, array $attributes): CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_OTHER,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 0,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Liste bakiye testi',
        ], $attributes));
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
