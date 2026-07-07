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

class CompanyCreateCompactTemplateUxTest extends TestCase
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
            'name' => 'Compact Template Owner',
            'email' => 'compact-template-owner@example.test',
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

    public function test_company_create_screen_renders_compact_template_sections_and_summary(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $response->assertOk()
            ->assertSee('Yeni Cari Oluştur')
            ->assertSee('Temel Bilgiler')
            ->assertSee('İletişim ve Resmi Bilgiler')
            ->assertSee('Opsiyonel: Yetkili ve Ek Adres')
            ->assertSee('Kayıt Özeti')
            ->assertSee('VKN / TCKN')
            ->assertSee('Açık Fatura Adresi')
            ->assertSee('+ Yetkili Ekle')
            ->assertSee('+ Adres Ekle')
            ->assertSee('Kurumsal Firma')
            ->assertSee('Şahıs İşletmesi')
            ->assertSee('Fason')
            ->assertSee('Kargo')
            ->assertDontSee('Vergi No')
            ->assertDontSee('TC No')
            ->assertDontSee('Sorgula')
            ->assertDontSee('Cariyi Tanı')
            ->assertDontSee('Yetkili ve Varsayılan Adres');
    }

    public function test_current_account_create_still_redirects_to_company_create(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/create'));

        $response->assertRedirect($this->tenantUrl('/admin/companies/create'));
    }

    public function test_portal_contact_autofill_data_remains_safe_on_company_show(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Compact Template Company',
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
            'email' => 'portal-safe@example.test',
            'phone' => '02120000000',
            'is_primary' => true,
        ]);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=portal'));

        $show->assertOk()
            ->assertSee('data-contact-name="Portal Yetkili"', false)
            ->assertSee('data-contact-email="portal-safe@example.test"', false)
            ->assertSee('data-contact-phone="02120000000"', false)
            ->assertDontSee('password')
            ->assertDontSee('smtp_password')
            ->assertDontSee('physical_path');
    }

    public function test_company_create_store_still_syncs_current_account_and_keeps_tenant_profile_separate(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Compact Flow Company',
                'tax_number' => '1234567890',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Compact Flow Company')
            ->firstOrFail();

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => $company->id,
        ]);
        $this->assertDatabaseMissing('companies', [
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'SAKLImavi',
            'short_name' => 'SAKLImavi',
        ]);
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
