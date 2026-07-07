<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CariUiTurkishTerminologyTest extends TestCase
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

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_new_cari_ui_surfaces_use_turkish_terminology_without_technical_english(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Terminoloji Cari');

        $companyResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $statementResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        foreach ([$companyResponse, $statementResponse] as $response) {
            $response->assertOk()
                ->assertDontSee('Company')
                ->assertDontSee('Current Account')
                ->assertDontSee('Transaction')
                ->assertDontSee('Source')
                ->assertDontSee('Payment')
                ->assertDontSee('Preview')
                ->assertDontSee('Drawer')
                ->assertSee('Tahsilat')
                ->assertSee('Ödeme')
                ->assertSee('Güncel Bakiye')
                ->assertSee('Belge No');
        }

        $companyResponse
            ->assertDontSee('Siparis')
            ->assertDontSee('Henuz')
            ->assertDontSee('Kisa Ad')
            ->assertDontSee('Acik')
            ->assertDontSee('Kapali')
            ->assertSee('Cari Kart')
            ->assertSee('Cari Ekstre')
            ->assertSee('Özet')
            ->assertSee('Hızlı İşlemler');

        $statementResponse
            ->assertSee('Cari Ekstre')
            ->assertSee('Hareket')
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee('Bakiye')
            ->assertSee('Hızlı Tahsilat / Ödeme');
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

    private function createFinanceUser(): User
    {
        $user = User::query()->create([
            'name' => 'Terminoloji Finance',
            'email' => 'terminoloji-finance@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach (['tenant_owner', 'finance'] as $roleKey) {
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
