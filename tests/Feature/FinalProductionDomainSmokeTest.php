<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SuperAdmin\ProductionEnvironmentReadinessService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use App\Services\SuperAdmin\SuperAdminSystemHealthService;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinalProductionDomainSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_domain_config_and_super_admin_host_guards_work_for_final_smoke(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net', self::CENTRAL_HOST],
            'prodelya_domains.reserved_hosts' => ['saklimavi.net', 'www.saklimavi.net', 'app.saklimavi.net', self::CENTRAL_HOST],
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1', self::CENTRAL_HOST],
        ]);

        $tenant = $this->createTenant('domain-smoke');
        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->isCentralAdmin(Request::create('https://saklimavi.net/admin/super-admin/dashboard')));
        $this->assertNull($resolver->resolve(Request::create('https://www.saklimavi.net/admin/dashboard')));
        $this->assertSame(
            $tenant->id,
            $resolver->resolve(Request::create('https://domain-smoke.saklimavi.net/admin/dashboard'))?->id
        );

        $this->actingAs($this->platformAdmin, 'web')
            ->get('http://' . self::CENTRAL_HOST . '/admin/super-admin/dashboard')
            ->assertOk();

        $this->actingAs($this->platformAdmin, 'web')
            ->get('http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/super-admin/dashboard')
            ->assertForbidden();
    }

    public function test_force_https_is_used_for_tenant_login_redirect_in_central_flow(): void
    {
        config([
            'prodelya_domains.force_https' => true,
            'prodelya_domains.panel_domain' => 'saklimavi.net',
        ]);

        $tenant = $this->createTenant('force-https-smoke');
        $tenantUser = User::query()->create([
            'name' => 'Tenant Login Smoke',
            'email' => 'tenant-login-smoke@example.test',
            'password' => Hash::make('secret-pass-123'),
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantUser->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('login.post'), [
                'email' => $tenantUser->email,
                'password' => 'secret-pass-123',
            ]);

        $response->assertRedirect('https://' . $tenant->panel_subdomain . '.saklimavi.net/admin/dashboard');
    }

    public function test_production_readiness_service_blocks_local_debug_and_hides_secret_values(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'mail.default' => 'log',
            'session.secure' => false,
        ]);

        $checks = collect(app(ProductionEnvironmentReadinessService::class)->buildReadinessChecks());
        $json = strtolower((string) json_encode($checks, JSON_UNESCAPED_UNICODE));

        $this->assertContains($checks->firstWhere('key', 'app_env')['status'], ['warning', 'blocked']);
        $this->assertSame('blocked', $checks->firstWhere('key', 'app_debug')['status']);
        $this->assertSame('blocked', $checks->firstWhere('key', 'app_url')['status']);
        $this->assertContains($checks->firstWhere('key', 'mail_mailer')['status'], ['warning', 'blocked']);
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('mail_password', $json);
        $this->assertStringNotContainsString('auth_token', $json);
        $this->assertStringNotContainsString('api key', $json);
    }

    public function test_dashboard_shows_final_production_readiness_warning_without_sensitive_data(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'mail.default' => 'log',
            'session.secure' => false,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Canlıya Çıkış final smoke planı uygulanmalı');
        $response->assertSee('APP_DEBUG canlıda kapalı olmalı');
        $response->assertDontSee('owner_temporary_password', false);
        $response->assertDontSee('temporary password', false);
        $response->assertDontSee('smtp_password', false);
        $response->assertDontSee('MAIL_PASSWORD', false);
        $response->assertDontSee('auth_token', false);
        $response->assertDontSee('api key', false);
        $response->assertDontSee('raw payload', false);
        $response->assertDontSee('raw JSON', false);
        $response->assertDontSee('exception trace', false);
        $response->assertDontSee(base_path(), false);
        $response->assertDontSee(storage_path(), false);
    }

    public function test_public_signup_and_product_hub_routes_exist_for_final_smoke_plan(): void
    {
        $this->assertTrue(Route::has('marketing.home'));
        $this->assertTrue(Route::has('marketing.register-interest'));
        $this->assertTrue(Route::has('marketing.register-interest.store'));
        $this->assertTrue(Route::has('marketing.demo-request'));
        $this->assertTrue(Route::has('marketing.demo-request.store'));

        $this->assertTrue(Route::has('admin.super.signup-requests.conversion-preview'));
        $this->assertTrue(Route::has('admin.super.signup-requests.conversion-success'));
        $this->assertTrue(Route::has('admin.upgrade-requests.index'));
        $this->assertTrue(Route::has('admin.super.upgrade-requests.index'));
        $this->assertTrue(Route::has('admin.super.product-data-hub.index'));
        $this->assertTrue(Route::has('admin.super.product-data-hub.sources.index'));
        $this->assertTrue(Route::has('customer.portal.home'));
        $this->assertTrue(Route::has('admin.promotion-quotes.store'));
        $this->assertTrue(Route::has('admin.orders.convert.from.quote'));
    }

    public function test_system_health_context_and_operation_dashboard_context_cover_final_smoke_keys(): void
    {
        $systemHealth = app(SuperAdminSystemHealthService::class)->buildHealthContext();
        $dashboard = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        foreach ([
            'queue_worker',
            'scheduler',
            'failed_jobs',
            'backup',
            'disk_usage',
            'database',
            'cache',
            'storage_link',
            'log_errors',
            'php_compatibility',
        ] as $key) {
            $this->assertArrayHasKey($key, $systemHealth);
        }

        $this->assertArrayHasKey('product_data_hub', $dashboard);
        $this->assertArrayHasKey('system_health', $dashboard);
        $this->assertArrayHasKey('security_warnings', $dashboard);
        $this->assertArrayHasKey('live_readiness', $dashboard['product_data_hub']);
    }

    public function test_final_production_smoke_documents_exist_and_include_core_sections(): void
    {
        $checklist = file_get_contents(base_path('docs/production-go-live-checklist.md'));
        $plan = file_get_contents(base_path('docs/production-smoke-test-plan.md'));

        $this->assertStringContainsString('## L) Final Production Domain Smoke', $checklist);
        $this->assertStringContainsString('### A) Merkezi Alan Adı Smoke', $checklist);
        $this->assertStringContainsString('### I) Tekliften Siparişe Kısa Smoke', $checklist);

        $this->assertStringContainsString('PROD-SMOKE-001 Merkezi Alan Adı', $plan);
        $this->assertStringContainsString('PROD-SMOKE-006 Talep Merkezi', $plan);
        $this->assertStringContainsString('PROD-SMOKE-012 Müşteri Portalı', $plan);
        $this->assertStringContainsString('Geçti / Kaldı', $plan);
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst($subdomain) . ' Tenant',
            'legal_name' => ucfirst($subdomain) . ' Tenant Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }
}
