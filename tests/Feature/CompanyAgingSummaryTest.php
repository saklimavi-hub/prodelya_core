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

class CompanyAgingSummaryTest extends TestCase
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

    public function test_company_aging_summary_counts_only_open_non_cancelled_transactions(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Aging Cari');

        $this->createOpenTransaction($account, 100, now()->addDays(3)->toDateString());
        $this->createOpenTransaction($account, 200, now()->subDays(3)->toDateString());
        $this->createOpenTransaction($account, 300, now()->subDays(10)->toDateString());
        $this->createOpenTransaction($account, 400, now()->subDays(45)->toDateString());
        $this->createOpenTransaction($account, 500, now()->subDays(90)->toDateString());
        $this->createOpenTransaction($account, 600, null);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 700,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'description' => 'Cancelled aging row',
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'));

        $response->assertOk()
            ->assertSee('Vadesi Geçmemiş')
            ->assertSee('100,00 TL / 1')
            ->assertSee('1-7 Gün')
            ->assertSee('200,00 TL / 1')
            ->assertSee('8-30 Gün')
            ->assertSee('300,00 TL / 1')
            ->assertSee('31-60 Gün')
            ->assertSee('400,00 TL / 1')
            ->assertSee('60+ Gün')
            ->assertSee('500,00 TL / 1')
            ->assertSee('Vadesiz / Tarih Yok')
            ->assertSee('600,00 TL / 1')
            ->assertDontSee('700,00 TL / 1');
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Company Aging Finance User',
            'email' => 'company-aging-finance@example.test',
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

    private function createOpenTransaction(CurrentAccount $account, float $amount, ?string $dueDate): void
    {
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => $amount,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Aging test',
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
