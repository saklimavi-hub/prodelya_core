<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
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

class CompanyCurrentAccountUnifiedUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $owner;
    private User $financeOwner;
    private User $platformAdmin;
    private User $foreignOwner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $this->owner = User::query()->create([
            'name' => 'Unified UX Owner',
            'email' => 'unified-ux-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $this->financeOwner = User::query()->create([
            'name' => 'Unified UX Finance Owner',
            'email' => 'unified-ux-finance-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->financeOwner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->financeOwner->id,
            'role_id' => $financeRole->id,
        ]);

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Unified UX Tenant',
            'legal_name' => 'Foreign Unified UX Tenant Ltd.',
            'slug' => 'foreign-unified-ux-tenant',
            'panel_subdomain' => 'foreign-unified-ux-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->foreignOwner = User::query()->create([
            'name' => 'Foreign Unified Owner',
            'email' => 'foreign-unified-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'user_id' => $this->foreignOwner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $this->enableCurrentAccounts();
    }

    public function test_linked_current_account_routes_redirect_to_company_show_and_edit(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Unified Redirect Cari');

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id))
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/edit'))
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id . '/edit'));
    }

    public function test_current_account_index_uses_company_routes_for_linked_rows_and_keeps_create_redirect(): void
    {
        [$linkedAccount, $company] = $this->createLinkedCompanyAccount('Linked Index Cari');
        $unlinkedAccount = $this->createUnlinkedAccount('Technical Finance Cari');
        $unlinkedAccount->forceFill([
            'tax_number' => '1234567890',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ])->save();
        $this->createTransaction($linkedAccount);
        $this->createTransaction($unlinkedAccount, CurrentAccountTransaction::STATUS_CLOSED);

        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts?tab=tumu'));

        $response->assertOk()
            ->assertSee('Cari Bakiyeler')
            ->assertSee('Cari Kartı Aç')
            ->assertSee('Ekstre')
            ->assertSee('VKN / TCKN')
            ->assertDontSee('Vergi / Kimlik')
            ->assertSee($this->tenantUrl('/admin/companies/' . $company->id), false)
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $linkedAccount->id . '/transactions'), false)
            ->assertDontSee('href="' . $this->tenantUrl('/admin/current-accounts/' . $linkedAccount->id) . '"', false)
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $unlinkedAccount->id), false)
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $unlinkedAccount->id . '/transactions'), false)
            ->assertDontSee('Düzenle');

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/create'))
            ->assertRedirect($this->tenantUrl('/admin/companies/create'));
    }

    public function test_company_show_renders_safe_current_account_summary_and_finance_link_for_finance_authorized_users(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Summary Cari');

        $ownerResponse = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $ownerResponse->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee($account->safeDisplayName())
            ->assertSee('Güncel Bakiye')
            ->assertSee('Toplam Borç')
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), false);

        $financeResponse = $this->actingAs($this->financeOwner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $financeResponse->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Güncel Bakiye')
            ->assertSee($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), false);
    }

    public function test_tenant_scope_and_platform_admin_guards_are_preserved(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Tenant Scope Cari');

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id))
            ->assertForbidden();

        $this->actingAs($this->foreignOwner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id))
            ->assertForbidden();

        $this->actingAs($this->foreignOwner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id))
            ->assertForbidden();
    }

    private function createLinkedCompanyAccount(string $displayName): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'tax_number' => '1234567890',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        $account = $account->fresh(['roles', 'links', 'primaryCompanyLink']);
        $company = Company::query()->findOrFail($account->primaryCompanyLink->link_id);

        return [$account, $company];
    }

    private function createUnlinkedAccount(string $displayName): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_SUPPLIER]);

        return $account->fresh(['roles', 'links', 'primaryCompanyLink']);
    }

    private function createTransaction(CurrentAccount $account, string $status = CurrentAccountTransaction::STATUS_OPEN): void
    {
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 100,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'status' => $status,
            'description' => 'Unified UX liste testi',
        ]);
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
