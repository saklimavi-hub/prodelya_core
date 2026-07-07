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

class CompanyBalanceDashboardCardsTest extends TestCase
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

    public function test_dashboard_cards_use_real_non_cancelled_summary_values(): void
    {
        [$receivableAccount, $receivableCompany] = $this->createLinkedAccountWithCompany('Dashboard Musteri Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'due_date' => now()->subDay(),
        ]);
        $this->createTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 200,
            'status' => CurrentAccountTransaction::STATUS_PAID,
        ]);
        $this->createTransaction($receivableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        [$payableAccount, $payableCompany] = $this->createLinkedAccountWithCompany('Dashboard Tedarikci Cari', [CurrentAccountRole::ROLE_SUPPLIER, CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createTransaction($payableAccount, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 300,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'due_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies'));

        $response->assertOk()
            ->assertSee('Toplam Borç')
            ->assertSee('800,00 TL')
            ->assertSee('Toplam Alacak')
            ->assertSee('300,00 TL')
            ->assertSee('Vadesi Geçen')
            ->assertSee('1.000,00 TL')
            ->assertSee('Açık Hareket')
            ->assertSee('2')
            ->assertSee('En Yüksek Borç Bakiyesi')
            ->assertSee('En Yüksek Alacak Bakiyesi')
            ->assertSee('Vadesi Geçenler')
            ->assertSee($receivableCompany->legal_name)
            ->assertSee($payableCompany->legal_name)
            ->assertDontSee('1.500,00 TL');
    }

    private function createTenantUserWithFinanceRole(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Company Dashboard Finance User',
            'email' => 'company-dashboard-finance@example.test',
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

        return [$account->fresh(['roles', 'links']), $company->fresh()];
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
            'description' => 'Dashboard bakiye testi',
        ], $attributes));
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
