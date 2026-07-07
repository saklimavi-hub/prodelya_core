<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCreateEditBillingAddressUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private User $platformAdmin;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Company UX Owner',
            'email' => 'company-ux-owner@example.test',
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

    public function test_company_create_form_shows_vkn_tckn_billing_address_and_identity_type(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $response->assertOk()
            ->assertSee('VKN / TCKN')
            ->assertSee('VKN 10, TCKN 11 hane olmalıdır.')
            ->assertSee('Açık Fatura Adresi')
            ->assertSee('Kurumsal Firma')
            ->assertSee('Bireysel Kişi')
            ->assertSee('Fatura Adresi')
            ->assertSee('+ Yetkili Ekle')
            ->assertDontSee('Sorgula')
            ->assertDontSee('Süreç Geçmişi')
            ->assertDontSee('Teklif sonrası otomatik davet gönder');
    }

    public function test_valid_company_create_builds_company_default_billing_address_optional_contact_and_current_account_sync(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'SAKLImavi Musteri A.S.',
                'short_name' => 'SAKLImavi Musteri',
                'tax_office' => 'Kadikoy',
                'tax_number' => '1234567890',
                'email' => 'musteri@example.test',
                'phone' => '02121231212',
                'mobile' => '05321231212',
                'website' => 'saklimavi-musteri.test',
                'status' => 'active',
                'risk_status' => 'low',
                'portal_enabled' => '1',
                'roles' => ['customer', 'supplier'],
                'billing_address' => 'Bagdat Cad. No:10',
                'billing_city' => 'Istanbul',
                'billing_district' => 'Kadikoy',
                'billing_country' => 'Turkiye',
                'billing_postal_code' => '34710',
                'primary_contact_name' => 'Ayse Yilmaz',
                'primary_contact_email' => 'ayse@example.test',
                'primary_contact_phone' => '05320001122',
                'primary_contact_note' => 'Satinalma',
            ]);

        $company = Company::query()->where('tenant_account_id', $this->tenant->id)->where('legal_name', 'SAKLImavi Musteri A.S.')->firstOrFail();

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));
        $this->assertSame('https://saklimavi-musteri.test', $company->website);
        $this->assertDatabaseHas('company_addresses', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'address_type' => 'billing',
            'title' => 'Fatura Adresi',
            'is_default' => true,
            'district' => 'Kadikoy',
            'city' => 'Istanbul',
        ]);
        $this->assertDatabaseHas('company_contacts', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Ayse Yilmaz',
            'email' => 'ayse@example.test',
            'phone' => '05320001122',
            'title' => 'Satinalma',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => $company->id,
        ]);

        $link = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->firstOrFail();

        $account = CurrentAccount::query()->findOrFail($link->current_account_id);
        $this->assertSame('1234567890', $account->tax_number);
        $this->assertSame('musteri@example.test', $account->email);
    }

    public function test_blank_optional_address_and_contact_do_not_create_empty_rows_and_11_digit_tckn_is_accepted(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'person',
                'legal_name' => 'Bireysel Musteri',
                'tax_number' => '12345678901',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $company = Company::query()->where('tenant_account_id', $this->tenant->id)->where('legal_name', 'Bireysel Musteri')->firstOrFail();

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));
        $this->assertSame(0, $company->addresses()->count());
        $this->assertSame(0, $company->contacts()->count());
    }

    public function test_invalid_vkn_tckn_and_turkish_validation_messages_are_shown(): void
    {
        $invalidLength = $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl('/admin/companies/create'))
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Hatali Vergi No',
                'tax_number' => '123456789',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $invalidLength->assertRedirect($this->tenantUrl('/admin/companies/create'))
            ->assertSessionHasErrors('tax_number');
        $this->assertSame(
            'VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.',
            $invalidLength->getSession()->get('errors')->first('tax_number')
        );
        $this->followRedirects($invalidLength)
            ->assertDontSee('The tax number');

        $invalidChars = $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl('/admin/companies/create'))
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Harfli Vergi No',
                'tax_number' => '12345ABCDE',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $invalidChars->assertRedirect($this->tenantUrl('/admin/companies/create'))
            ->assertSessionHasErrors('tax_number');
        $this->assertSame(
            'VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.',
            $invalidChars->getSession()->get('errors')->first('tax_number')
        );
    }

    public function test_edit_screen_and_portal_contact_autofill_render_safely(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Edit Render Company',
            'short_name' => 'Edit Render',
            'tax_office' => 'Besiktas',
            'tax_number' => '1234567890',
            'email' => 'edit-company@example.test',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $contactWithEmail = $company->contacts()->create([
            'tenant_account_id' => $this->tenant->id,
            'name' => 'Portal Yetkili',
            'email' => 'portal-contact@example.test',
            'phone' => '02120000000',
            'is_primary' => true,
        ]);

        $company->contacts()->create([
            'tenant_account_id' => $this->tenant->id,
            'name' => 'Eposta Yok',
            'phone' => '02123334455',
            'is_primary' => false,
        ]);

        $edit = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'));

        $edit->assertOk()
            ->assertSee('VKN / TCKN')
            ->assertSee('Cari Rolleri')
            ->assertSee('Portal erişimi aktif')
            ->assertSee('Fason Baskı Firması');

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=portal'));

        $show->assertOk()
            ->assertSee('data-contact-name="Portal Yetkili"', false)
            ->assertSee('data-contact-email="portal-contact@example.test"', false)
            ->assertSee('data-contact-phone="02120000000"', false)
            ->assertSee('Seçili yetkilinin e-posta adresi yok. Portal daveti için e-posta girmeniz gerekir.')
            ->assertDontSee('password')
            ->assertDontSee('invite_token')
            ->assertDontSee('password_reset_token')
            ->assertDontSee('smtp_password')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path');

        $this->assertDatabaseMissing('companies', [
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'SAKLImavi',
            'short_name' => 'SAKLImavi',
        ]);
        $this->assertDatabaseHas('company_contacts', [
            'id' => $contactWithEmail->id,
            'tenant_account_id' => $this->tenant->id,
        ]);
    }

    public function test_platform_admin_on_tenant_host_and_foreign_tenant_user_cannot_access_company_form(): void
    {
        $company = Company::query()->where('tenant_account_id', $this->tenant->id)->firstOrFail();
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Tenant',
            'legal_name' => 'Foreign Tenant Ltd.',
            'slug' => 'foreign-company-form',
            'panel_subdomain' => 'foreign-company-form',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $foreignOwner = User::query()->create([
            'name' => 'Foreign Owner',
            'email' => 'foreign-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'user_id' => $foreignOwner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertForbidden();

        $this->actingAs($foreignOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'))
            ->assertForbidden();
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

    private function tenantHost(): string
    {
        return $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
