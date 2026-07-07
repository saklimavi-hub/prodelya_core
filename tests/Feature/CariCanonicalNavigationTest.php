<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CariCanonicalNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->firstOrFail();
    }

    public function test_admin_menu_and_company_pages_use_canonical_cari_language(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('Cari Kartlar')
            ->assertSee('Müşteri, tedarikçi, fasoncu, kargo firması ve diğer carileri tek kart üzerinden yönetin.')
            ->assertSee(route('admin.companies.index'), false)
            ->assertDontSee(route('admin.current-accounts.index'), false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.create'))
            ->assertOk()
            ->assertSee('Yeni Cari Oluştur')
            ->assertSee('Aynı cari kart müşteri, tedarikçi, fasoncu veya diğer rol bilgilerini taşıyabilir.');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $this->company))
            ->assertOk()
            ->assertSee('Cari Kart Detayı')
            ->assertSee($this->company->legal_name);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.edit', $this->company))
            ->assertOk()
            ->assertSee('Cari Kartı Düzenle');
    }
}
