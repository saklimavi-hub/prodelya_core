<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveTabSafetyChecklistTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_tab_shows_safety_checklist_with_user_facing_labels(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-checklist-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $this->actingAs($owner)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $fixture['duplicate_company'], 'tab' => 'benzer-cari']))
            ->assertOk()
            ->assertSee('Güvenlik Kontrol Listesi')
            ->assertSee('Finans hareketi')
            ->assertSee('Sipariş bağlantısı')
            ->assertSee('Tedarik bağlantısı')
            ->assertSee('Portal kullanıcıları')
            ->assertSee('Yetkili kayıtları')
            ->assertSee('Adres kayıtları')
            ->assertSee('Tedarikçi eşleşmesi')
            ->assertDontSee('supplier_id')
            ->assertDontSee('current_account_id')
            ->assertDontSee('canonical')
            ->assertDontSee('tenant_id');
    }
}
