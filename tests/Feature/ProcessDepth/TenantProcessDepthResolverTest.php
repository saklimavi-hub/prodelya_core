<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantProcessDepthResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_override_fast_wins(): void
    {
        $tenant = $this->createTenantWithPackage('standard');
        TenantSetting::setValue($tenant->id, 'process_depth', 'fast', 'string');

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant->fresh());

        $this->assertSame('fast', $resolved['key']);
        $this->assertSame('tenant_override', $resolved['source']);
        $this->assertSame('Abone Firma tercihi', $resolved['source_label']);
        $this->assertTrue($resolved['is_overridden']);
    }

    public function test_tenant_override_controlled_wins(): void
    {
        $tenant = $this->createTenantWithPackage('standard');
        TenantSetting::setValue($tenant->id, 'process_depth', 'controlled', 'string');

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant->fresh());

        $this->assertSame('controlled', $resolved['key']);
        $this->assertSame('tenant_override', $resolved['source']);
    }

    public function test_package_default_is_used_when_override_missing(): void
    {
        $tenant = $this->createTenantWithPackage('fast');

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant->fresh());

        $this->assertSame('fast', $resolved['key']);
        $this->assertSame('package_default', $resolved['source']);
        $this->assertFalse($resolved['is_overridden']);
    }

    public function test_missing_package_falls_back_to_system_standard(): void
    {
        $tenant = TenantAccount::factory()->create([
            'package_key' => 'missing-package',
        ]);

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant);

        $this->assertSame('standard', $resolved['key']);
        $this->assertSame('system_default', $resolved['source']);
        $this->assertSame('Sistem varsayılanı', $resolved['source_label']);
    }

    public function test_invalid_tenant_override_falls_back_to_package_default(): void
    {
        $tenant = $this->createTenantWithPackage('controlled');
        TenantSetting::setValue($tenant->id, 'process_depth', 'bogus', 'string');

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant->fresh());

        $this->assertSame('controlled', $resolved['key']);
        $this->assertSame('package_default', $resolved['source']);
    }

    public function test_invalid_package_default_falls_back_to_system_default(): void
    {
        $package = Package::query()->create([
            'key' => 'invalid-package',
            'name' => 'Invalid Package',
            'status' => 'active',
            'currency' => 'TRY',
        ]);

        DB::table('packages')->where('id', $package->id)->update([
            'process_depth' => 'bogus',
        ]);

        $tenant = TenantAccount::factory()->create([
            'package_key' => $package->key,
        ]);

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenant->fresh());

        $this->assertSame('standard', $resolved['key']);
        $this->assertSame('system_default', $resolved['source']);
    }

    public function test_cross_tenant_override_isolation_is_preserved(): void
    {
        $tenantA = $this->createTenantWithPackage('standard', 'package-a');
        $tenantB = $this->createTenantWithPackage('standard', 'package-b');

        TenantSetting::setValue($tenantA->id, 'process_depth', 'controlled', 'string');

        $resolved = app(TenantProcessDepthResolver::class)->resolve($tenantB->fresh());

        $this->assertSame('standard', $resolved['key']);
        $this->assertSame('package_default', $resolved['source']);
    }

    public function test_package_records_default_to_standard_when_omitted(): void
    {
        $package = Package::query()->create([
            'key' => 'default-package',
            'name' => 'Default Package',
            'status' => 'active',
            'currency' => 'TRY',
        ])->fresh();

        $this->assertSame('standard', $package->process_depth);
    }

    protected function createTenantWithPackage(string $processDepth, string $packageKey = 'suite'): TenantAccount
    {
        $package = Package::query()->create([
            'key' => $packageKey,
            'name' => strtoupper($packageKey),
            'status' => 'active',
            'currency' => 'TRY',
            'process_depth' => $processDepth,
        ]);

        return TenantAccount::factory()->create([
            'package_key' => $package->key,
        ]);
    }
}
