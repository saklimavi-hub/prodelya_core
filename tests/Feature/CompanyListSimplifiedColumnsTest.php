<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\TestCase;

class CompanyListSimplifiedColumnsTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_company_list_uses_simplified_columns_for_finance_view(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'company-list-columns@example.test');

        $this->createCompanyLinkedAccount($tenant, 'Kolon Sade Cari', [CurrentAccountRole::ROLE_CUSTOMER]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'));

        $response->assertOk()
            ->assertSee('Cari Adı')
            ->assertSee('Roller')
            ->assertSee('İletişim')
            ->assertSee('Güncel Bakiye')
            ->assertSee('Bakiye Durumu')
            ->assertSee('Açık Hareket')
            ->assertSee('Son Hareket')
            ->assertSee('Aksiyon')
            ->assertDontSee('<th>Şehir</th>', false)
            ->assertDontSee('<th>VKN / TCKN</th>', false)
            ->assertDontSee('<th>Vadesi Geçen</th>', false);
    }
}
