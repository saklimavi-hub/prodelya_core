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

class CompanyFinanceCardSummaryTest extends TestCase
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
        $this->limitedUser = $this->createTenantUser('company-finance-card-owner@example.test', 'delivery');
        $this->financeUser = $this->createTenantUser('company-finance-card-finance@example.test', true);
    }

    public function test_company_show_finance_card_renders_summary_for_finance_users_only(): void
    {
        [$account, $company] = $this->createLinkedAccountWithCompany('Detay Finans Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 900,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Detay finans kartı',
        ]);

        $financeResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $financeResponse->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Toplam Borç')
            ->assertSee('Toplam Alacak')
            ->assertSee('Açık Hareket')
            ->assertSee('Son Hareket')
            ->assertSee('Borç Bakiyesi')
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), false);

        $ownerResponse = $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $ownerResponse->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertDontSee('Tahsilat Gir')
            ->assertDontSee('Ödeme Yap');
    }

    private function createTenantUser(string $email, bool|string $role): User
    {
        $tenantRole = Role::query()->where('key', is_string($role) ? $role : 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => $role === true ? 'Company Finance Card Finance User' : 'Company Finance Card Limited User',
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

    private function createLinkedAccountWithCompany(string $displayName): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
