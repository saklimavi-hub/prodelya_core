<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyShowQuickPanelStillWorksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_company_statement_tab_still_renders_quick_panel_markup_and_routes(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = User::query()->create([
            'name' => 'Quick Panel Finance',
            'email' => 'tabbed-quick-panel@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach (['tenant_owner', 'finance'] as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $tenant->id,
                'user_id' => $financeUser->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Hızlı Panel Sekmeli Cari',
            'legal_name' => 'Hızlı Panel Sekmeli Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = Company::query()->findOrFail($company->id);

        $response = $this->actingAs($financeUser)
            ->get('http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/companies/' . $company->id . '?tab=ekstre');

        $response->assertOk()
            ->assertSee('Hızlı Tahsilat / Ödeme')
            ->assertSee('data-quick-panel', false)
            ->assertSee(route('admin.current-accounts.transactions.store', $account), false)
            ->assertSee('Tahsilat Gir')
            ->assertSee('Ödeme Yap')
            ->assertSee('Yeni Hareket');
    }
}
