<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCreateUltraCompactTemplateUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Ultra Compact Owner',
            'email' => 'ultra-compact-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->enableCurrentAccounts();
        $this->enableCustomerPortal();
    }

    public function test_company_create_screen_uses_ultra_compact_structure(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $response->assertOk()
            ->assertSee('Yeni Cari Oluştur')
            ->assertSee('Temel Bilgiler')
            ->assertSee('İletişim ve Resmi Bilgiler')
            ->assertSee('Roller')
            ->assertSee('Opsiyonel: Yetkili ve Ek Adres')
            ->assertSee('Kayıt Özeti')
            ->assertSee('VKN / TCKN')
            ->assertSee('Açık Fatura Adresi')
            ->assertSee('+ Yetkili Ekle')
            ->assertSee('+ Adres Ekle')
            ->assertSee('Kurumsal Firma')
            ->assertSee('Bireysel Kişi')
            ->assertSee('Şahıs İşletmesi')
            ->assertSee('Müşteri')
            ->assertSee('Tedarikçi')
            ->assertSee('Fason Baskı Firması')
            ->assertSee('Nakliye / Kargo')
            ->assertSee('Fason Üretim Firması')
            ->assertDontSee('Cariyi Tanı')
            ->assertDontSee('İletişim + Resmi Bilgi')
            ->assertDontSee('Yetkili + Adres')
            ->assertDontSee('Varsayılan Fatura Adresi')
            ->assertDontSee('Vergi No')
            ->assertDontSee('TC No')
            ->assertDontSee('Sorgula');
    }

    public function test_current_account_create_redirect_and_store_sync_are_preserved(): void
    {
        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/create'))
            ->assertRedirect($this->tenantUrl('/admin/companies/create'));

        $store = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Ultra Compact Route Company',
                'tax_number' => '1234567890',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Ultra Compact Route Company')
            ->firstOrFail();

        $store->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => $company->id,
        ]);
    }

    public function test_portal_contact_autofill_and_turkish_validation_remain_safe(): void
    {
        $invalid = $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl('/admin/companies/create'))
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Ultra Compact Invalid',
                'tax_number' => '12345ABCDE',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $invalid->assertRedirect($this->tenantUrl('/admin/companies/create'))
            ->assertSessionHasErrors('tax_number');
        $this->assertSame(
            'VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.',
            $invalid->getSession()->get('errors')->first('tax_number')
        );

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Ultra Compact Portal Company',
            'tax_number' => '1234567890',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $company->contacts()->create([
            'tenant_account_id' => $this->tenant->id,
            'name' => 'Portal Yetkili',
            'email' => 'ultra-portal@example.test',
            'phone' => '02120000000',
            'is_primary' => true,
        ]);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=portal'));

        $show->assertOk()
            ->assertSee('data-contact-name="Portal Yetkili"', false)
            ->assertSee('data-contact-email="ultra-portal@example.test"', false)
            ->assertSee('data-contact-phone="02120000000"', false);
    }

    private function enableCurrentAccounts(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function enableCustomerPortal(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
