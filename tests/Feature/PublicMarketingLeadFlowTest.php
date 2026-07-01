<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSignupRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicMarketingLeadFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantAdminRole;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_home_page_and_ctas_do_not_404(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Promosyon, baskı ve sipariş operasyonlarını tek panelden yönetin.');
        $response->assertSee('1 Ay Ücretsiz Dene');
        $response->assertSee('Demo Talep Et');
        $response->assertSee('Paketleri İncele');
        $response->assertSee('Abone Firma Girişi');
        $response->assertSee('Ücretsiz deneme talebinde ödeme alınmaz');
        $response->assertSee('Kredi kartı gerekmez');
        $response->assertSee('Müşteri Portalı ana giriş değildir');
        $response->assertSee(route('marketing.register-interest'), false);
        $response->assertSee(route('marketing.demo-request'), false);
        $response->assertDontSee(route('customer.login'), false);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('customer.login'))
            ->assertOk()
            ->assertSee('Genel Müşteri Portalı Girişi');
    }

    public function test_register_interest_and_demo_forms_render(): void
    {
        $activePublic = Package::query()->create([
            'key' => 'public-start',
            'name' => 'Public Start',
            'description' => 'Public visible package',
            'status' => 'active',
            'is_public' => true,
            'sort_order' => 10,
        ]);

        Package::query()->create([
            'key' => 'public-passive',
            'name' => 'Public Passive',
            'description' => 'Should stay hidden',
            'status' => 'passive',
            'is_public' => true,
            'sort_order' => 20,
        ]);

        $register = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.register-interest'));

        $register->assertOk();
        $register->assertSee('Başvuruyu Gönder');
        $register->assertSee($activePublic->name);
        $register->assertDontSee('Public Passive');
        $register->assertSee('Musteri Portali');
        $register->assertDontSee('XML ve CSV Aktarim');
        $register->assertSee('Bu başvuruda ödeme alınmaz');
        $register->assertSee('Kredi kartı gerekmez');
        $register->assertSee('Tedarikçi Ürün Kaynakları');
        $register->assertDontSee('Tenant API');
        $register->assertDontSee('projection');
        $register->assertDontSee('preview');

        $demo = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.demo-request'));

        $demo->assertOk();
        $demo->assertSee('Demo Talebini Gönder');
        $demo->assertSee('Promosyon teklif ve sipariş akışı');
        $demo->assertSee('Product Data Hub ve tedarikçi ürünleri');
    }

    public function test_trial_request_creates_signup_record(): void
    {
        $package = Package::query()->create([
            'key' => 'trial-pack',
            'name' => 'Trial Pack',
            'description' => 'Trial package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.register-interest.store'), [
                'company_name' => 'Akasya Promosyon',
                'contact_name' => 'Ayşe Kaya',
                'phone' => '05320000000',
                'email' => 'ayse@example.test',
                'city' => 'İstanbul',
                'business_type' => 'Promosyon',
                'requested_package_id' => $package->id,
                'expected_user_count' => 12,
                'selected_modules' => ['customer_portal', 'product_data_hub'],
                'message' => 'Teklif ve katalog akışını görmek istiyoruz.',
            ]);

        $response->assertRedirect(route('marketing.register-interest'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('tenant_signup_requests', [
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Akasya Promosyon',
            'email' => 'ayse@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
        ]);
    }

    public function test_demo_request_creates_signup_record(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.demo-request.store'), [
                'company_name' => 'Beta Baskı',
                'contact_name' => 'Mehmet Demir',
                'phone' => '05325555555',
                'email' => 'mehmet@example.test',
                'demo_topic' => 'Müşteri portalı ve teklif onayı',
                'message' => 'Portal ve grafik onay sürecini görmek istiyoruz.',
            ]);

        $response->assertRedirect(route('marketing.demo-request'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('tenant_signup_requests', [
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'company_name' => 'Beta Baskı',
            'email' => 'mehmet@example.test',
            'status' => TenantSignupRequest::STATUS_NEW,
        ]);
    }

    public function test_invalid_form_and_honeypot_are_rejected_safely(): void
    {
        $invalid = $this->from(route('marketing.register-interest'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.register-interest.store'), [
                'company_name' => 'Eksik Mail Ltd.',
                'contact_name' => 'Test User',
                'phone' => '05320000000',
                'email' => 'gecersiz-mail',
            ]);

        $invalid->assertRedirect(route('marketing.register-interest'));
        $invalid->assertSessionHasErrors('email');
        $this->assertDatabaseCount('tenant_signup_requests', 0);

        $honeypot = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.demo-request.store'), [
                'website' => 'filled',
                'company_name' => 'Spam Demo',
                'contact_name' => 'Spam',
                'phone' => '05320000000',
                'email' => 'spam@example.test',
                'demo_topic' => 'Spam',
            ]);

        $honeypot->assertRedirect(route('marketing.demo-request'));
        $honeypot->assertSessionHas('success');
        $this->assertDatabaseCount('tenant_signup_requests', 0);
    }

    public function test_super_admin_can_see_public_signup_requests_and_tenant_admin_cannot(): void
    {
        $lead = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Liste Test A.Ş.',
            'contact_name' => 'Liste Yetkilisi',
            'phone' => '05320000000',
            'email' => 'liste@example.test',
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
        ]);

        $index = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.index'));

        $index->assertOk();
        $index->assertSee('Başvurular');
        $index->assertSee('Kontrol Paneli');
        $index->assertSee('Başvuru Karar Paneli');
        $index->assertSee('Hazırlık');
        $index->assertSee('Dönüşebilir Aday');
        $index->assertSee('Yeni Başvuru');
        $index->assertSee($lead->company_name);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $lead));

        $show->assertOk();
        $show->assertSee($lead->contact_name);
        $show->assertSee('Dönüşüm Hazırlığı');
        $show->assertSee('Dönüştürülemez');

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin-marketing@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.index'))
            ->assertForbidden();
    }
}
