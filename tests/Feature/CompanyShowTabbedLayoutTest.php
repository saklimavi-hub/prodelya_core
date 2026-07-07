<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyShowTabbedLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_company_show_uses_tabbed_layout_with_single_main_header(): void
    {
        [, $company] = $this->createCompany('Sekmeli Test Cari');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $company));

        $response->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Yetkili ve Adresler')
            ->assertSee('Tedarikçi Eşleşme')
            ->assertSee('Siparişler')
            ->assertSee('Benzer Cari Kontrolü');

        $this->assertSame(1, substr_count($response->getContent(), 'Cari Kart Detayı'));
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
}
