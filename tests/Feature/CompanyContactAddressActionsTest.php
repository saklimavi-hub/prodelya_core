<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyContactAddressActionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->enableCurrentAccounts();
        $this->enableCustomerPortal();
    }

    public function test_company_detail_shows_active_contact_and_address_actions_with_clean_copy(): void
    {
        CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Detay Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'detay-yetkilisi@example.test',
            'phone' => '02121112233',
            'is_primary' => true,
        ]);

        CompanyAddress::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'address_type' => 'billing',
            'title' => 'Merkez Ofis',
            'address' => 'Atatürk Caddesi No:10',
            'district' => 'Çankaya',
            'city' => 'Ankara',
            'country' => 'Türkiye',
            'postal_code' => '06000',
            'is_default' => true,
        ]);

        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal Kullanıcısı',
            'email' => 'portal-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $this->company, 'tab' => 'yetkililer']));

        $response->assertOk()
            ->assertSee('Yetkili Ekle')
            ->assertSee('Adres Ekle')
            ->assertSee('Detay Yetkilisi')
            ->assertSee('Merkez Ofis')
            ->assertSee('Yetkili ve Adresler')
            ->assertDontSee('placeholder')
            ->assertDontSee('invite_token')
            ->assertDontSee('password_reset_token')
            ->assertDontSee('hashed')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('smtp_password');

        $portalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $this->company, 'tab' => 'portal']));

        $portalResponse->assertOk()
            ->assertSee('Portal Kullanıcıları')
            ->assertSee($portalUser->email)
            ->assertDontSee('Yeni yetkili ve adres aksiyonlari hazir')
            ->assertDontSee('TODO')
            ->assertDontSee('placeholder');
    }

    public function test_contact_and_address_empty_states_are_user_friendly(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $this->company, 'tab' => 'yetkililer']));

        $response->assertOk()
            ->assertSee('Henüz yetkili kişi eklenmemiş.')
            ->assertSee('Henüz adres eklenmemiş.')
            ->assertDontSee('hazir')
            ->assertDontSee('placeholder');
    }

    public function test_valid_contact_submission_creates_company_contact_without_creating_portal_user(): void
    {
        $portalUserCountBefore = CustomerPortalUser::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->count();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.companies.show', $this->company))
            ->post(route('admin.companies.contacts.store', $this->company), [
                'contact_name' => 'Satınalma Yetkilisi',
                'contact_title' => 'Satınalma Müdürü',
                'contact_email' => 'satin-alma@example.test',
                'contact_phone' => '02125557788',
                'contact_mobile' => '05325557788',
                'contact_is_primary' => '1',
            ]);

        $response->assertRedirect(route('admin.companies.show', $this->company) . '#company-contacts');
        $response->assertSessionHas('success', 'Yetkili kişi eklendi.');

        $this->assertDatabaseHas('company_contacts', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Satınalma Yetkilisi',
            'email' => 'satin-alma@example.test',
            'is_primary' => true,
        ]);

        $this->assertSame(
            $portalUserCountBefore,
            CustomerPortalUser::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('company_id', $this->company->id)
                ->count()
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $this->company, 'tab' => 'yetkililer']))
            ->assertSee('Satınalma Yetkilisi')
            ->assertSee('Satınalma Müdürü')
            ->assertSee('satin-alma@example.test');
    }

    public function test_invalid_contact_email_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.companies.show', $this->company))
            ->post(route('admin.companies.contacts.store', $this->company), [
                'contact_name' => 'Hatalı Mail Yetkilisi',
                'contact_email' => 'gecersiz-email',
            ]);

        $response->assertRedirect(route('admin.companies.show', $this->company));
        $response->assertSessionHasErrors('contact_email');

        $this->assertDatabaseMissing('company_contacts', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Hatalı Mail Yetkilisi',
        ]);
    }

    public function test_contact_and_address_actions_are_blocked_for_other_tenant_companies(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Foreign Tenant',
            'legal_name' => 'Foreign Tenant Ltd.',
            'slug' => 'foreign-tenant',
            'panel_subdomain' => 'foreign-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Foreign Company',
            'short_name' => 'Foreign Company',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.contacts.store', $foreignCompany), [
                'contact_name' => 'Yabancı Yetkili',
            ])
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.addresses.store', $foreignCompany), [
                'address_body' => 'Yabancı adres',
            ])
            ->assertForbidden();
    }

    public function test_valid_address_submission_creates_company_address_and_shows_it_on_detail_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.companies.show', $this->company))
            ->post(route('admin.companies.addresses.store', $this->company), [
                'address_title' => 'İstanbul Depo',
                'address_type' => 'delivery',
                'address_body' => 'Organize Sanayi Bölgesi 2. Cadde No:15',
                'address_district' => 'Tuzla',
                'address_city' => 'İstanbul',
                'address_country' => 'Türkiye',
                'address_postal_code' => '34940',
                'address_is_default' => '1',
            ]);

        $response->assertRedirect(route('admin.companies.show', $this->company) . '#company-addresses');
        $response->assertSessionHas('success', 'Adres eklendi.');

        $this->assertDatabaseHas('company_addresses', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'title' => 'İstanbul Depo',
            'address_type' => 'delivery',
            'district' => 'Tuzla',
            'city' => 'İstanbul',
            'is_default' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $this->company, 'tab' => 'yetkililer']))
            ->assertSee('İstanbul Depo')
            ->assertSee('Teslimat')
            ->assertSee('Tuzla')
            ->assertSee('İstanbul');
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
}
