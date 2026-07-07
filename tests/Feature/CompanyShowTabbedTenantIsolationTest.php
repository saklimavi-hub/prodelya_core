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

class CompanyShowTabbedTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_company_show_tabs_preserve_tenant_isolation(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $ownerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        $localUser = User::query()->create([
            'name' => 'Yerel Kullanıcı',
            'email' => 'tab-local@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $localUser->id,
            'role_id' => $ownerRole->id,
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Sekmeli Yabancı Tenant',
            'legal_name' => 'Sekmeli Yabancı Tenant Ltd.',
            'slug' => 'sekmeli-yabanci-tenant',
            'panel_subdomain' => 'sekmeli-yabanci-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'display_name' => 'Yabancı Cari',
            'legal_name' => 'Yabancı Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = Company::query()->findOrFail($company->id);

        $this->actingAs($localUser)
            ->get('http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/companies/' . $company->id)
            ->assertForbidden();
    }
}
