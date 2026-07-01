<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_platform_admin_login_from_tenant_host_redirects_to_central_super_admin_dashboard(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'prodelya_core.test'],
            'prodelya_domains.force_https' => false,
        ]);

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenant = $this->createTenant('redirect-tenant');

        $response = $this->withServerVariables([
            'HTTP_HOST' => $tenant->panel_subdomain . '.prodelya_core.test',
        ])->post(route('login.post'), [
            'email' => $platformAdmin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('http://saklimavi.net/admin/super-admin/dashboard');
    }

    public function test_authenticated_platform_admin_visiting_login_form_is_redirected_to_central_super_admin_dashboard(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'prodelya_core.test'],
            'prodelya_domains.force_https' => false,
        ]);

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenant = $this->createTenant('redirect-login-form');

        $response = $this->actingAs($platformAdmin, 'web')
            ->withServerVariables([
                'HTTP_HOST' => $tenant->panel_subdomain . '.prodelya_core.test',
            ])
            ->get(route('login'));

        $response->assertRedirect('http://saklimavi.net/admin/super-admin/dashboard');
    }

    public function test_tenant_user_login_still_redirects_to_tenant_dashboard(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'prodelya_core.test'],
            'prodelya_domains.panel_domain' => 'saklimavi.net',
            'prodelya_domains.force_https' => true,
        ]);

        $tenant = $this->createTenant('redirect-tenant-user');
        $tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $tenantUser = User::query()->create([
            'name' => 'Tenant Redirect User',
            'email' => 'tenant-redirect-user@example.test',
            'password' => Hash::make('secret-pass-123'),
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantUser->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $tenantAdminRole->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->post(route('login.post'), [
                'email' => $tenantUser->email,
                'password' => 'secret-pass-123',
            ]);

        $response->assertRedirect('https://' . $tenant->panel_subdomain . '.saklimavi.net/admin/dashboard');
    }

    public function test_local_sqlite_login_can_continue_without_last_login_write(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'sqlite',
            'prodelya_domains.central_hosts' => ['prodelya_core.test'],
            'prodelya_domains.force_https' => false,
        ]);

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $platformAdmin->forceFill([
            'last_login_at' => null,
            'last_login_ip' => null,
        ])->save();

        $response = $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->post(route('login.post'), [
                'email' => $platformAdmin->email,
                'password' => 'password',
            ]);

        $response->assertRedirect('http://prodelya_core.test/admin/super-admin/dashboard');
        $this->assertNull($platformAdmin->fresh()->last_login_at);
        $this->assertNull($platformAdmin->fresh()->last_login_ip);
    }

    public function test_local_sqlite_logout_uses_current_device_fallback_and_redirects_cleanly(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'sqlite',
        ]);

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($platformAdmin, 'web')
            ->withSession([Auth::guard('web')->getName() => $platformAdmin->id])
            ->post(route('logout'), [
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect('/');
        $this->assertGuest('web');
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
