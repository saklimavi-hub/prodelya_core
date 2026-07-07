<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountStatementTerminologyTest extends TestCase
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
        $this->enableCurrentAccounts();
    }

    public function test_current_account_statement_page_uses_cari_ekstre_terminology(): void
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Terminology Statement Cari',
            'legal_name' => 'Terminology Statement Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 450,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Statement terminology',
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $response->assertOk()
            ->assertSee('Cari Ekstre')
            ->assertSee('Cari Kartı Aç')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Bakiye Durumu')
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee(route('admin.companies.show', $company), false)
            ->assertDontSee('Current Account');
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Cari Ekstre Finance User',
            'email' => 'cari-ekstre-finance@example.test',
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

    private function enableCurrentAccounts(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
