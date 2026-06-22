<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SaasPackageAccessEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const TENANT_HOST = 'saas-smoke.prodelya.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private TenantAccount $lifecycleTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'SaaS Smoke Tenant',
            'legal_name' => 'SaaS Smoke Tenant Ltd.',
            'slug' => 'saas-smoke-tenant',
            'panel_subdomain' => 'saas-smoke',
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

        $this->lifecycleTenant = TenantAccount::query()->firstOrCreate(
            ['panel_subdomain' => 'demo'],
            [
                'name' => 'Demo Lifecycle Tenant',
                'legal_name' => 'Demo Lifecycle Tenant Ltd.',
                'slug' => 'demo',
                'status' => 'active',
                'package_key' => 'starter',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'number_format_locale' => 'tr_TR',
            ]
        );

        if (!Route::has('test.saas.quote-approval')) {
            Route::middleware(['auth', 'resolve.tenant', 'tenant.active', 'feature.enabled:quote_customer_approval,public_quote_approval'])
                ->get('/admin/test/saas/quote-approval', fn () => response('quote-approval-ok', 200))
                ->name('test.saas.quote-approval');
        }
    }

    public function test_saas_package_access_end_to_end_smoke_and_hardening(): void
    {
        $menu = app(AdminMenuService::class);
        $access = app(TenantAccessService::class);
        $usage = app(TenantUsageService::class);

        $tenantMenuLabels = $this->tenantMenuLabels($menu);
        $this->assertNotContains('Product Data Hub', $tenantMenuLabels);

        $disabledModule = $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/product-data-hub');

        $disabledModule->assertForbidden();
        $disabledModule->assertSee('aktif değil');
        $disabledModule->assertDontSee('API key', false);
        $disabledModule->assertDontSee('smtp_password', false);
        $disabledModule->assertDontSee('file_path', false);
        $disabledModule->assertDontSee('group_code', false);

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/modules', [
                'overrides' => [
                    'product_data_hub' => 'enabled',
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'product_data_hub'));
        $tenantMenuLabels = $this->tenantMenuLabels($menu);
        $this->assertNotContains('Product Data Hub', $tenantMenuLabels);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/product-data-hub')
            ->assertForbidden()
            ->assertSee('yalnız Super Admin tarafından yönetilir.');

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/modules', [
                'overrides' => [
                    'product_data_hub' => 'default',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'product_data_hub'));
        $tenantMenuLabels = $this->tenantMenuLabels($menu);
        $this->assertNotContains('Product Data Hub', $tenantMenuLabels);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/product-data-hub')
            ->assertForbidden();

        $this->assertSame(3, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/limits', [
                'limits' => [
                    'users' => ['mode' => 'value', 'value' => '5'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(5, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/limits', [
                'limits' => [
                    'users' => ['mode' => 'default', 'value' => ''],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(3, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id, [
                'package_key' => 'suite',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'product_data_hub'));

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/modules', [
                'overrides' => [
                    'product_data_hub' => 'disabled',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessModule($this->tenant->fresh(), 'product_data_hub'));
        $tenantMenuLabels = $this->tenantMenuLabels($menu);
        $this->assertNotContains('Product Data Hub', $tenantMenuLabels);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/product-data-hub')
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/features', [
                'overrides' => [
                    'public_quote_approval' => 'enabled',
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/saas/quote-approval')
            ->assertOk()
            ->assertSee('quote-approval-ok');

        $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/features', [
                'overrides' => [
                    'public_quote_approval' => 'disabled',
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));

        $disabledFeature = $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/test/saas/quote-approval');

        $disabledFeature->assertForbidden();
        $disabledFeature->assertDontSee('API key', false);
        $disabledFeature->assertDontSee('file_path', false);
        $disabledFeature->assertDontSee('PDH raw', false);

        $this->assertSame(20, $usage->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $settings = $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/settings');

        $settings->assertOk();
        $settings->assertSee('Suite');
        $settings->assertSee('Kullanicilar');
        $settings->assertSee('20');
        $settings->assertDontSee('product_data_hub', false);
        $settings->assertDontSee('API key', false);
        $settings->assertDontSee('file_path', false);
        $settings->assertDontSee('smtp_password', false);

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/print-service-quotes')
            ->assertForbidden();

        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->lifecycleTenant->forceFill(['status' => 'expired'])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $this->actingAs($this->adminUser)
            ->get('http://' . self::CENTRAL_HOST . '/admin/settings')
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->post('http://' . self::CENTRAL_HOST . '/admin/settings', [
                'work_folder_root_name' => 'EXPIRED-TRY',
            ])
            ->assertForbidden();

        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->lifecycleTenant->forceFill(['status' => 'suspended'])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $this->actingAs($this->adminUser)
            ->get('http://' . self::CENTRAL_HOST . '/admin/dashboard')
            ->assertForbidden();

        $this->lifecycleTenant->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->adminUser)
            ->get('http://' . self::CENTRAL_HOST . '/admin/current-accounts')
            ->assertForbidden();

        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->lifecycleTenant->forceFill(['status' => 'trial'])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $this->actingAs($this->adminUser)
            ->get('http://' . self::CENTRAL_HOST . '/admin/dashboard')
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertOk();

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();
    }

    private function tenantMenuLabels(AdminMenuService $menu): array
    {
        return collect($menu->tenantMenu($this->tenant->fresh(), $this->adminUser))
            ->flatMap(fn (array $item) => collect($item['children'] ?? [$item])->pluck('label'))
            ->filter()
            ->values()
            ->all();
    }
}
