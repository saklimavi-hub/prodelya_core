<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SuperAdminTenantPackageOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const TENANT_HOST = 'override-tenant.prodelya.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private TenantAccount $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'Override Tenant',
            'legal_name' => 'Override Tenant Ltd.',
            'slug' => 'override-tenant',
            'panel_subdomain' => 'override-tenant',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        UserRole::query()->firstOrCreate([
            'user_id' => $this->adminUser->id,
            'role_id' => Role::query()->where('key', 'admin')->value('id'),
            'tenant_account_id' => $this->tenant->id,
        ]);

        $this->otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant',
            'panel_subdomain' => 'other-tenant',
            'status' => 'active',
            'package_key' => 'suite',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        if (!Route::has('test.tenant-override.customer-portal')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'module.enabled:customer_portal'])
                ->get('/admin/test/tenant-override/customer-portal', fn () => response('portal-ok', 200))
                ->name('test.tenant-override.customer-portal');
        }

        if (!Route::has('test.tenant-override.quote-approval')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'feature.enabled:quote_customer_approval,public_quote_approval'])
                ->get('/admin/test/tenant-override/quote-approval', fn () => response('feature-ok', 200))
                ->name('test.tenant-override.quote-approval');
        }
    }

    public function test_super_admin_can_manage_tenant_package_module_feature_and_limit_overrides(): void
    {
        $showUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id;
        $editUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/edit';
        $updateUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id;
        $modulesUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/modules';
        $featuresUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/features';
        $limitsUrl = 'http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/limits';

        $show = $this->actingAs($this->adminUser)
            ->get($showUrl);

        $show->assertOk();
        $show->assertSee('Modül / Override Özeti');
        $show->assertSee('Starter');
        $show->assertDontSee('smtp_password', false);
        $show->assertDontSee('file_path', false);

        $edit = $this->actingAs($this->adminUser)
            ->get($editUrl);

        $edit->assertOk();
        $edit->assertSee('Abone Firma Düzenle');
        $edit->assertSee('Modül Override');
        $edit->assertSee('Feature Override');
        $edit->assertSee('Limit Override');

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/edit')
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->from($editUrl)
            ->put($updateUrl, [
                'package_key' => 'missing-package',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertSame('starter', $this->tenant->fresh()->package_key);

        $passivePackage = Package::query()->create([
            'key' => 'legacy-passive',
            'name' => 'Legacy Passive',
            'status' => 'passive',
            'currency' => 'TRY',
        ]);

        $this->actingAs($this->adminUser)
            ->from($editUrl)
            ->put($updateUrl, [
                'package_key' => $passivePackage->key,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertSame('starter', $this->tenant->fresh()->package_key);

        $access = app(TenantAccessService::class);
        $usage = app(TenantUsageService::class);
        $menu = app(AdminMenuService::class);

        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));

        $this->actingAs($this->adminUser)
            ->put($modulesUrl, [
                'overrides' => [
                    'customer_portal' => 'enabled',
                    'core' => 'disabled',
                    'production_qc' => 'enabled',
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));
        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'core'));
        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'production_qc'));
        $this->assertDatabaseHas('tenant_modules', [
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'customer_portal',
            'feature_key' => null,
            'is_enabled' => 1,
        ]);
        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'production_qc',
            'feature_key' => null,
        ]);

        $menuLabels = collect($menu->tenantMenu($this->tenant->fresh(), $this->adminUser))
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();
        $this->assertContains('Müşteri Portalı', $menuLabels);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/tenant-override/customer-portal')
            ->assertOk()
            ->assertSee('portal-ok');

        $this->actingAs($this->adminUser)
            ->put($modulesUrl, [
                'overrides' => [
                    'customer_portal' => 'disabled',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));

        $menuLabels = collect($menu->tenantMenu($this->tenant->fresh(), $this->adminUser))
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();
        $this->assertNotContains('Müşteri Portalı', $menuLabels);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/tenant-override/customer-portal')
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->put($modulesUrl, [
                'overrides' => [
                    'customer_portal' => 'default',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));

        $this->actingAs($this->adminUser)
            ->put($updateUrl, [
                'package_key' => 'suite',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->tenant->refresh();
        $this->assertSame('suite', $this->tenant->package_key);
        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));
        $this->assertSame(20, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/tenant-override/customer-portal')
            ->assertOk()
            ->assertSee('portal-ok');

        $this->assertTrue($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));

        $this->actingAs($this->adminUser)
            ->put($featuresUrl, [
                'overrides' => [
                    'public_quote_approval' => 'disabled',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));
        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/tenant-override/quote-approval')
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->put($featuresUrl, [
                'overrides' => [
                    'public_quote_approval' => 'default',
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));
        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/tenant-override/quote-approval')
            ->assertOk()
            ->assertSee('feature-ok');

        $this->actingAs($this->adminUser)
            ->put($limitsUrl, [
                'limits' => [
                    'users' => ['mode' => 'value', 'value' => '99'],
                    'api_tokens' => ['mode' => 'unlimited', 'value' => ''],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(99, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);
        $this->assertSame('unlimited', $usage->getUsageForKey($this->tenant->fresh(), 'api_tokens')['status']);
        $this->assertSame(20, $usage->getUsageForKey($this->otherTenant->fresh(), 'users')['limit']);

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_account_id' => $this->tenant->id,
            'key' => 'limit_users',
            'value' => '99',
        ]);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_account_id' => $this->tenant->id,
            'key' => 'limit_api_tokens',
            'value' => 'unlimited',
        ]);
    }
}
