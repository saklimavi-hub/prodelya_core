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

class CariDetailQuickActionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;
    private User $productionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createUserWithRoles('detail-finance@example.test', ['finance']);
        $this->productionUser = $this->createUserWithRoles('detail-production@example.test', ['production']);
    }

    public function test_company_show_displays_quick_action_panel_for_finance_users_only(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Hızlı Panel Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 2400,
            'currency' => 'TL',
            'transaction_date' => '2026-07-04',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Detay hızlı panel hareketi',
        ]);

        $financeResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $financeResponse->assertOk()
            ->assertSee('Tahsilat Gir')
            ->assertSee('Ödeme Yap')
            ->assertSee('Yeni Hareket')
            ->assertSee('Hızlı Tahsilat / Ödeme')
            ->assertSee('Cari hareket kaydedildiğinde ekstre ve bakiye anında güncellenir.')
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee('Bakiye')
            ->assertSee('data-quick-panel', false);

        $productionResponse = $this->actingAs($this->productionUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $productionResponse->assertOk()
            ->assertDontSee('Tahsilat Gir')
            ->assertDontSee('Ödeme Yap')
            ->assertDontSee('Yeni Hareket')
            ->assertDontSee('Hızlı Tahsilat / Ödeme');
    }

    private function createLinkedCompanyAccount(string $displayName): array
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

    private function createUserWithRoles(string $email, array $roleKeys): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roleKeys as $roleKey) {
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
