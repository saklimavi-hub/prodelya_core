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

class CompanyStatementPreviewTest extends TestCase
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

    public function test_company_show_renders_statement_preview_with_turkish_labels(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Ekstre Onizleme Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1250,
            'currency' => 'TL',
            'transaction_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Sipariş tahsilat öncesi satış hareketi',
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'));

        $response->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Filtreli Hareket Toplamı')
            ->assertSee('Vade Yaşlandırma')
            ->assertSee('Müşteri Borcu / Satış')
            ->assertSee('Bakiye')
            ->assertSee('1.250,00 TL')
            ->assertDontSee('Current Account');
    }

    public function test_company_show_renders_safe_empty_state_when_no_transactions_exist(): void
    {
        [, $company] = $this->createLinkedCompanyAccount('Bos Ekstre Cari');

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'));

        $response->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Henüz cari hareket bulunmuyor.');
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Company Statement Preview Finance',
            'email' => 'company-statement-preview@example.test',
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
