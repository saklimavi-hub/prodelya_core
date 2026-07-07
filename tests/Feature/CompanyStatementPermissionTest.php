<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyStatementPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;
    private User $limitedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createUser('company-statement-permission-finance@example.test', 'finance', true);
        $this->limitedUser = $this->createUser('company-statement-permission-limited@example.test', 'delivery', false);
    }

    public function test_company_statement_respects_finance_permissions(): void
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Permission Statement Cari',
            'legal_name' => 'Permission Statement Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Finans bilgileri yalnız yetkili kullanıcılar için görünür.');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe');
    }

    private function createUser(string $email, string $baseRole, bool $includeFinanceRole): User
    {
        $baseRoleModel = Role::query()->where('key', $baseRole)->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => $includeFinanceRole ? 'Company Statement Finance User' : 'Company Statement Limited User',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $baseRoleModel->id,
        ]);

        if ($includeFinanceRole && $baseRole !== 'finance') {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => $financeRole->id,
            ]);
        }

        return $user;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
