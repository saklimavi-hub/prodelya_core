<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchivePermissionTenantTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_archive_respects_permission_and_tenant_isolation(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $noRoleUser = User::query()->create([
            'name' => 'Yetkisiz Kullanıcı',
            'email' => 'duplicate-no-role@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Duplicate Foreign Tenant',
            'legal_name' => 'Duplicate Foreign Tenant Ltd.',
            'slug' => 'duplicate-foreign-tenant',
            'panel_subdomain' => 'duplicate-foreign-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $foreignOwner = $this->makeTenantOwner($foreignTenant, 'duplicate-foreign-owner@example.test');

        $this->actingAs($noRoleUser)
            ->post($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '/archive-duplicate'))
            ->assertForbidden();

        $this->actingAs($foreignOwner)
            ->get($this->tenantUrl($foreignTenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'))
            ->assertForbidden();
    }
}
