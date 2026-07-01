<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSignupRequest;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicMarketingDomainSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_central_host_keeps_public_marketing_home_and_lead_routes_open(): void
    {
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('Abone Firma Girişi')
            ->assertSee('1 Ay Ücretsiz Dene')
            ->assertDontSee('raw')
            ->assertDontSee('projection')
            ->assertDontSee('group_code')
            ->assertDontSee('supplier_cost');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.register-interest'))
            ->assertOk()
            ->assertSee('Başvuruyu Gönder');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.demo-request'))
            ->assertOk()
            ->assertSee('Demo Talebini Gönder');
    }

    public function test_tenant_host_root_redirects_to_customer_portal_when_portal_login_is_enabled(): void
    {
        $tenant = $this->createTenant('portal-root-open');
        $this->enablePortalLogin($tenant);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/'))
            ->assertRedirect($this->tenantUrl($tenant, '/musteri-giris'));

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/musteri-giris'))
            ->assertOk()
            ->assertSee('Müşteri Portalı')
            ->assertDontSee('1 Ay Ücretsiz Dene')
            ->assertDontSee('Demo Talep Et');
    }

    public function test_tenant_host_root_redirects_to_admin_when_portal_login_is_disabled(): void
    {
        $tenant = $this->createTenant('portal-root-closed');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/'))
            ->assertRedirect($this->tenantUrl($tenant, '/admin'));

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/admin'))
            ->assertRedirect(route('login'));
    }

    public function test_tenant_hosts_cannot_open_central_marketing_lead_forms(): void
    {
        $tenant = $this->createTenant('marketing-closed');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/register-interest'))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/demo-talep'))
            ->assertNotFound();
    }

    public function test_tenant_hosts_cannot_post_central_marketing_leads(): void
    {
        $tenant = $this->createTenant('marketing-post-closed');
        $package = Package::query()->create([
            'key' => 'public-domain-test',
            'name' => 'Public Domain Test',
            'description' => 'Public package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post($this->tenantUrl($tenant, '/register-interest'), [
                'company_name' => 'Yanlis Host A.Ş.',
                'contact_name' => 'Tenant Host User',
                'phone' => '05320000000',
                'email' => 'tenant-host@example.test',
                'requested_package_id' => $package->id,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('tenant_signup_requests', 0);
    }

    public function test_customer_login_route_and_super_admin_guard_behaviour_remain_intact_after_root_split(): void
    {
        $tenant = $this->createTenant('portal-guarded');
        $this->enablePortalLogin($tenant);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('customer.login'))
            ->assertOk()
            ->assertSee('Genel Müşteri Portalı Girişi');

        $this->actingAs(\App\Models\User::query()->where('email', 'admin@prodelya.local')->firstOrFail(), 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get($this->tenantUrl($tenant, '/admin/super-admin/dashboard'))
            ->assertForbidden();
    }

    public function test_named_marketing_routes_and_admin_entrypoints_stay_registered(): void
    {
        $this->assertTrue(Route::has('marketing.home'));
        $this->assertTrue(Route::has('marketing.register-interest'));
        $this->assertTrue(Route::has('marketing.register-interest.store'));
        $this->assertTrue(Route::has('marketing.demo-request'));
        $this->assertTrue(Route::has('marketing.demo-request.store'));
        $this->assertTrue(Route::has('customer.login'));
        $this->assertTrue(Route::has('admin.dashboard'));
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function enablePortalLogin(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'customer_login',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $this->tenantHost($tenant) . $path;
    }
}
