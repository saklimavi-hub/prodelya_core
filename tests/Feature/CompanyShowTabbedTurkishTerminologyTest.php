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

class CompanyShowTabbedTurkishTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_company_show_tabbed_view_uses_turkish_labels_without_technical_english(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Terminoloji Cari',
            'legal_name' => 'Terminoloji Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $company));

        $response->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Yetkili ve Adresler')
            ->assertSee('Benzer Cari Kontrolü')
            ->assertDontSee('Musteri')
            ->assertDontSee('Siparis')
            ->assertDontSee('Tedarikci')
            ->assertDontSee('Current Account')
            ->assertDontSee('Company')
            ->assertDontSee('canonical')
            ->assertDontSee('duplicate');
    }
}
