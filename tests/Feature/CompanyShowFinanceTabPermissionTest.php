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

class CompanyShowFinanceTabPermissionTest extends TestCase
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
        $this->financeUser = $this->createUser('show-finance@example.test', ['tenant_owner', 'finance']);
        $this->limitedUser = $this->createUser('show-limited@example.test', ['delivery']);
    }

    public function test_finance_tab_and_quick_panel_respect_permissions(): void
    {
        [$account, $company] = $this->createCompany('İzin Test Cari');

        $this->actingAs($this->financeUser)
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Güncel Bakiye');

        $this->actingAs($this->limitedUser)
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Finans bilgileri yalnız yetkili kullanıcılar için görünür.')
            ->assertDontSee('Tahsilat Gir');
    }

    private function createCompany(string $name): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $name,
            'legal_name' => $name . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account, Company::query()->findOrFail($company->id)];
    }

    private function createUser(string $email, array $roles): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach ($roles as $roleKey) {
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
