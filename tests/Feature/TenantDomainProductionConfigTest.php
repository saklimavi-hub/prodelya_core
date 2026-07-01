<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantDomainProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_central_and_reserved_hosts_are_read_from_config(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net', 'app.saklimavi.net'],
            'prodelya_domains.reserved_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'www.saklimavi.net'],
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1'],
        ]);

        $tenant = $this->createTenant('blue');
        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->isCentralAdmin(Request::create('https://saklimavi.net/admin/super-admin/tenants')));
        $this->assertTrue($resolver->isCentralAdmin(Request::create('https://app.saklimavi.net/admin/super-admin/tenants')));
        $this->assertNull($resolver->resolve(Request::create('https://www.saklimavi.net/admin/dashboard')));
        $this->assertSame(
            $tenant->id,
            $resolver->resolve(Request::create('https://blue.saklimavi.net/admin/dashboard'))?->id
        );
    }

    public function test_local_fallback_hosts_continue_to_work_in_local_testing(): void
    {
        config([
            'prodelya_domains.central_hosts' => [],
            'prodelya_domains.reserved_hosts' => [],
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1', 'prodelya_core.test'],
        ]);

        $tenant = $this->createTenant('local-fallback-smoke');
        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->isCentralAdmin(Request::create('http://prodelya_core.test/admin/super-admin/tenants')));
        $this->assertSame(
            $tenant->id,
            $resolver->resolve(Request::create('http://local-fallback-smoke.prodelya_core.test/admin/dashboard'))?->id
        );
    }

    public function test_reserved_hosts_are_not_resolved_as_tenants_even_when_matching_custom_domains(): void
    {
        config([
            'prodelya_domains.central_hosts' => ['saklimavi.net'],
            'prodelya_domains.reserved_hosts' => ['saklimavi.net', 'app.saklimavi.net', 'www.saklimavi.net'],
            'prodelya_domains.local_hosts' => ['localhost', '127.0.0.1'],
        ]);

        TenantAccount::query()->create([
            'name' => 'Reserved Host Tenant',
            'legal_name' => 'Reserved Host Tenant Ltd.',
            'slug' => 'reserved-host-tenant',
            'panel_subdomain' => 'reserved-host-tenant',
            'custom_domain' => 'www.saklimavi.net',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $resolver = app(TenantResolver::class);

        $this->assertNull($resolver->resolve(Request::create('https://www.saklimavi.net/admin/dashboard')));
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
