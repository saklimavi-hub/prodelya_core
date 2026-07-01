<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PromotionQuotePdfService;
use App\Services\TenantCompanyProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCompanyProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
    }

    public function test_tenant_owner_can_open_company_profile_screen_and_update_it_without_creating_company_records(): void
    {
        $tenant = $this->createTenant('tenant-profile-owner');
        $owner = $this->createOwner($tenant, 'owner-profile@example.test');

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->assertOk()
            ->assertSee('Firma Bilgileri')
            ->assertSee('Kurulum Merkezi')
            ->assertSee('Firma Bilgileri')
            ->assertSee('Kurulum Merkezi')
            ->assertSee('Abone firma kimliğinizi')
            ->assertSee('Bu bilgiler cari kart değildir')
            ->assertSee('Belge başlığı')
            ->assertSee('Vergi bilgisi')
            ->assertSee('Web sitesi')
            ->assertSee('Firma Kimliği')
            ->assertSee('İletişim')
            ->assertSee('Adres ve Belge Bilgileri')
            ->assertSee('Logo ve Görsel Kimlik')
            ->assertSee('Firma Profili Özeti')
            ->assertSee('Belge Başlığı Önizlemesi')
            ->assertSee('Bu Bilgiler Nerede Kullanılır')
            ->assertSee('Teklif PDF’i')
            ->assertSee('İş formu')
            ->assertSee('Müşteri ekranı')
            ->assertSee('Bildirim şablonları')
            ->assertDontSee('Logo Yükle');

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->assertDontSee('Belge önizle')
            ->assertDontSee('type="file"', false)
            ->assertDontSee('Super Admin Yönetir')
            ->assertDontSee('raw JSON')
            ->assertDontSee('physical path')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('group_code')
            ->assertDontSee('secret');

        $companyCount = $tenant->companies()->count();
        $currentAccountCount = $tenant->currentAccounts()->count();

        $response = $this->actingAs($owner, 'web')
            ->post($this->tenantUrl($tenant, '/admin/settings/company-profile'), [
                'display_name' => 'SAKLImavi',
                'legal_name' => 'SAKLImavi Reklam Matbaa İletişim Hizmetleri San. Tic. Ltd. Şti.',
                'tax_office' => 'Kadikoy',
                'tax_number' => '1234567890',
                'phone' => '02165554433',
                'email' => 'info@saklimavi.test',
                'website' => 'saklimavi.test',
                'address' => 'Bagdat Caddesi No: 10',
                'district' => 'Kadikoy',
                'city' => 'Istanbul',
                'country' => 'Turkiye',
                'postal_code' => '34710',
            ]);

        $response->assertRedirect($this->tenantUrl($tenant, '/admin/settings/company-profile'));

        $tenant->refresh();

        $profile = app(TenantCompanyProfileService::class)->getProfile($tenant);

        $this->assertSame('SAKLImavi', $profile['display_name']);
        $this->assertSame('SAKLImavi Reklam Matbaa İletişim Hizmetleri San. Tic. Ltd. Şti.', $profile['legal_name']);
        $this->assertSame('Kadikoy', $profile['tax_office']);
        $this->assertSame('1234567890', $profile['tax_number']);
        $this->assertSame('02165554433', $profile['phone']);
        $this->assertSame('info@saklimavi.test', $profile['email']);
        $this->assertSame('https://saklimavi.test', $profile['website']);
        $this->assertStringContainsString('Bagdat Caddesi No: 10', (string) $profile['full_address']);
        $this->assertNull($profile['logo_url']);
        $this->assertSame('SAKLImavi', $tenant->name);
        $this->assertSame('SAKLImavi Reklam Matbaa İletişim Hizmetleri San. Tic. Ltd. Şti.', $tenant->legal_name);
        $this->assertSame($companyCount, $tenant->companies()->count());
        $this->assertSame($currentAccountCount, $tenant->currentAccounts()->count());

        $settingsPage = $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings'));

        $settingsPage->assertOk()
            ->assertSee('Firma Bilgilerini Düzenle')
            ->assertSee('Firma Profili')
            ->assertSee('Dosya ve Depolama')
            ->assertSee('Turkiye / Istanbul')
            ->assertSee('info@saklimavi.test')
            ->assertDontSee('smtp_password', false)
            ->assertDontSee('api_key', false)
            ->assertDontSee('file_path', false)
            ->assertDontSee('physical_path', false);
    }

    public function test_tenant_scope_platform_admin_and_other_tenant_users_cannot_edit_company_profile(): void
    {
        $tenant = $this->createTenant('tenant-profile-guard');
        $owner = $this->createOwner($tenant, 'guard-owner@example.test');
        $otherTenant = $this->createTenant('tenant-profile-other');
        $otherOwner = $this->createOwner($otherTenant, 'other-owner@example.test');

        $this->actingAs($otherOwner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($this->demoTenant, '/admin/settings/company-profile'))
            ->assertForbidden();
    }

    public function test_company_profile_validates_required_display_name_email_and_website(): void
    {
        $tenant = $this->createTenant('tenant-profile-validation');
        $owner = $this->createOwner($tenant, 'validation-owner@example.test');

        $this->actingAs($owner, 'web')
            ->from($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->post($this->tenantUrl($tenant, '/admin/settings/company-profile'), [
                'display_name' => '',
                'email' => 'invalid-email',
                'website' => 'not a url',
            ])
            ->assertRedirect($this->tenantUrl($tenant, '/admin/settings/company-profile'))
            ->assertSessionHasErrors(['display_name', 'email', 'website']);
    }

    public function test_profile_helper_falls_back_to_tenant_legal_name_and_name_when_settings_are_blank(): void
    {
        $tenant = $this->createTenant('tenant-profile-fallback', 'Fallback Tenant', 'Fallback Legal');

        $profile = app(TenantCompanyProfileService::class)->getProfile($tenant);

        $this->assertSame('Fallback Legal', $profile['display_name']);
        $this->assertSame('Fallback Legal', $profile['legal_name']);
        $this->assertSame('Türkiye', $profile['country']);
        $this->assertArrayNotHasKey('smtp_password', $profile);
        $this->assertArrayNotHasKey('password', $profile);
        $this->assertArrayNotHasKey('token', $profile);
        $this->assertArrayNotHasKey('file_path', $profile);
        $this->assertArrayNotHasKey('physical_path', $profile);
    }

    public function test_quote_pdf_uses_company_profile_display_name_without_breaking_existing_behavior(): void
    {
        $tenant = $this->createTenant('tenant-profile-pdf', 'Tenant Display', 'Tenant Legal');
        $owner = $this->createOwner($tenant, 'pdf-owner@example.test');
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'PDF Musteri A.S.',
            'short_name' => 'PDF Musteri',
            'email' => 'pdf-customer@example.test',
            'phone' => '02120000000',
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'web')
            ->post($this->tenantUrl($tenant, '/admin/settings/company-profile'), [
                'display_name' => 'Profil Gorunen Ad',
                'legal_name' => 'Profil Yasal Unvan',
                'email' => 'pdf@saklimavi.test',
                'country' => 'Turkiye',
            ])
            ->assertRedirect();

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-TEST-001',
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'draft',
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 200,
        ]);

        $viewData = app(PromotionQuotePdfService::class)->buildViewData($quote->fresh());

        $this->assertSame('Profil Gorunen Ad', $viewData['tenantName']);
    }

    private function createTenant(
        string $subdomain,
        string $name = 'Tenant Profile',
        string $legalName = 'Tenant Profile Legal'
    ): TenantAccount {
        return TenantAccount::query()->create([
            'name' => $name,
            'legal_name' => $legalName,
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createOwner(TenantAccount $tenant, string $email): User
    {
        $owner = User::query()->create([
            'name' => 'Tenant Owner',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        return $owner;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
