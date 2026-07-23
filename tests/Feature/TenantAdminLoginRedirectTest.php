<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantAdminLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_login_form_posts_back_to_same_host_when_app_url_is_localhost(): void
    {
        config([
            'app.url' => 'http://localhost',
            'prodelya_domains.central_hosts' => ['prodelya_core.test', 'localhost'],
            'prodelya_domains.panel_domain' => 'prodelya_core.test',
        ]);

        $tenant = $this->createTenant('tenant-login-form');

        $response = $this->withServerVariables([
            'HTTP_HOST' => $tenant->panel_subdomain . '.prodelya_core.test',
        ])->get('/login');

        $response->assertOk();
        $response->assertSee('action="/login"', false);
        $response->assertDontSee('action="http://localhost/login"', false);
        $response->assertDontSee('action="http://localhost/admin/super-admin/dashboard"', false);
    }

    public function test_tenant_owner_login_on_tenant_host_redirects_to_relative_tenant_dashboard_and_never_super_admin(): void
    {
        config([
            'app.url' => 'http://localhost',
            'prodelya_domains.central_hosts' => ['prodelya_core.test', 'localhost'],
            'prodelya_domains.panel_domain' => 'prodelya_core.test',
            'prodelya_domains.force_https' => false,
        ]);

        $tenant = $this->createTenant('tenant-login-redirect');
        $owner = $this->createTenantUser($tenant, 'tenant_owner', 'tenant-owner-login@example.test', 'Tenant Owner', 'secret-pass-123');

        $response = $this->withServerVariables([
            'HTTP_HOST' => $tenant->panel_subdomain . '.prodelya_core.test',
        ])->post('/login', [
            'email' => $owner->email,
            'password' => 'secret-pass-123',
        ]);

        $response->assertRedirect('http://' . $tenant->panel_subdomain . '.prodelya_core.test/admin/dashboard');
        self::assertStringContainsString($tenant->panel_subdomain . '.prodelya_core.test/admin/dashboard', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('/admin/super-admin/', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('http://localhost/', (string) $response->headers->get('Location'));
    }

    public function test_tenant_owner_login_ignores_unsafe_super_admin_intended_url(): void
    {
        config([
            'app.url' => 'http://localhost',
            'prodelya_domains.central_hosts' => ['prodelya_core.test', 'localhost'],
            'prodelya_domains.panel_domain' => 'prodelya_core.test',
            'prodelya_domains.force_https' => false,
        ]);

        $tenant = $this->createTenant('tenant-login-intended');
        $owner = $this->createTenantUser($tenant, 'tenant_owner', 'tenant-owner-intended@example.test', 'Tenant Owner Intended', 'secret-pass-456');

        $response = $this->withServerVariables([
            'HTTP_HOST' => $tenant->panel_subdomain . '.prodelya_core.test',
        ])->withSession([
            'url.intended' => 'http://localhost/admin/super-admin/dashboard',
        ])->post('/login', [
            'email' => $owner->email,
            'password' => 'secret-pass-456',
        ]);

        $response->assertRedirect('http://' . $tenant->panel_subdomain . '.prodelya_core.test/admin/dashboard');
        self::assertStringContainsString($tenant->panel_subdomain . '.prodelya_core.test/admin/dashboard', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('/admin/super-admin/', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('http://localhost/', (string) $response->headers->get('Location'));
        self::assertNotEquals('http://localhost/admin/super-admin/dashboard', $response->headers->get('Location'));
    }

    public function test_tenant_owner_login_on_central_host_redirects_to_tenant_host_even_when_app_url_is_localhost(): void
    {
        config([
            'app.url' => 'http://localhost',
            'prodelya_domains.central_hosts' => ['prodelya_core.test', 'localhost'],
            'prodelya_domains.panel_domain' => 'prodelya_core.test',
            'prodelya_domains.force_https' => false,
        ]);

        $tenant = $this->createTenant('tenant-login-central');
        $owner = $this->createTenantUser($tenant, 'tenant_owner', 'tenant-owner-central@example.test', 'Tenant Owner Central', 'secret-pass-789');

        $response = $this->withServerVariables([
            'HTTP_HOST' => 'prodelya_core.test',
        ])->post('/login', [
            'email' => $owner->email,
            'password' => 'secret-pass-789',
        ]);

        $response->assertRedirect('http://' . $tenant->panel_subdomain . '.prodelya_core.test/admin/dashboard');
    }

    public function test_platform_admin_central_login_behavior_is_preserved_with_localhost_app_url(): void
    {
        config([
            'app.url' => 'http://localhost',
            'prodelya_domains.central_hosts' => ['prodelya_core.test', 'localhost'],
            'prodelya_domains.panel_domain' => 'prodelya_core.test',
            'prodelya_domains.force_https' => false,
        ]);

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->post('http://prodelya_core.test/login', [
            'email' => $platformAdmin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('http://prodelya_core.test/admin/super-admin/dashboard');
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

    private function createTenantUser(TenantAccount $tenant, string $roleKey, string $email, string $name, string $password): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
